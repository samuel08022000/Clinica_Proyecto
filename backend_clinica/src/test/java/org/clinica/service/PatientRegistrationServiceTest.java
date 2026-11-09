package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.persistence.PersistenceException;
import jakarta.transaction.Transactional;
import org.clinica.dto.AuthDtos.OtpChallengeData;
import org.clinica.dto.AuthDtos.OtpRequest;
import org.clinica.dto.AuthDtos.RegisterPatientRequest;
import org.clinica.repository.PatientRegistrationGateway;
import org.clinica.repository.PatientRegistrationGateway.PatientAccount;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.ZoneOffset;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class PatientRegistrationServiceTest {

    private static final String PASSWORD = "PacienteSegura123";

    private FakeGateway gateway;
    private FakeDeliveryProvider delivery;
    private MutableClock clock;
    private RegistrationChallengeStore challenges;
    private PatientRegistrationService service;

    @BeforeEach
    void setUp() {
        clock = new MutableClock(Instant.parse("2026-11-04T12:00:00Z"));
        gateway = new FakeGateway();
        delivery = new FakeDeliveryProvider();
        challenges = new RegistrationChallengeStore(Duration.ofMinutes(30), clock);
        LoginAttemptGuard guard = new LoginAttemptGuard(
                5,
                Duration.ofMinutes(15),
                Duration.ofMinutes(15),
                clock);
        service = new PatientRegistrationService(gateway, challenges, delivery, guard, clock);
    }

    @Test
    void successfulRegistrationCreatesPendingPatientAndOtpChallenge() {
        var result = service.register(validRequest());

        assertEquals("PAC000100", result.patientId());
        assertEquals("ROL001", result.roleCode());
        assertTrue(result.emailVerificationRequired());
        assertNotNull(result.verification().verificationToken());
        assertEquals("n***@clinica.bo", result.verification().maskedDestination());
        assertEquals("DEMO", result.verification().deliveryMode());
        assertFalse(result.verification().deliveryConfirmed());
        assertTrue(BcryptUtil.matches(PASSWORD, gateway.account.passwordHash()));
        assertFalse(gateway.account.emailVerified());
        assertEquals("123456", delivery.lastCode);
    }

    @Test
    void missingDataAndWeakPasswordAreRejectedBeforePersistence() {
        AuthException missing = assertThrows(AuthException.class, () -> service.register(null));
        assertEquals(422, missing.status());

        RegisterPatientRequest weak = new RegisterPatientRequest(
                "Paciente Prueba", "nuevo@clinica.bo", "debil", "CI-9001",
                LocalDate.of(1995, 4, 12), "+59170000678", "La Paz");
        AuthException invalidPassword = assertThrows(AuthException.class, () -> service.register(weak));

        assertEquals("VALIDATION_ERROR", invalidPassword.code());
        assertFalse(gateway.registerCalled);
    }

    @Test
    void duplicateEmailAndDocumentAreRejected() {
        gateway.emailExists = true;
        AuthException email = assertThrows(AuthException.class, () -> service.register(validRequest()));
        assertEquals(409, email.status());
        assertEquals("EMAIL_ALREADY_REGISTERED", email.code());

        gateway.emailExists = false;
        gateway.documentExists = true;
        AuthException document = assertThrows(AuthException.class, () -> service.register(validRequest()));
        assertEquals("DOCUMENT_ALREADY_REGISTERED", document.code());
    }

    @Test
    void providerFailureDoesNotLeaveAnUsableChallenge() throws NoSuchMethodException {
        delivery.fail = true;

        AuthException error = assertThrows(AuthException.class, () -> service.register(validRequest()));

        assertEquals("OTP_PROVIDER_UNAVAILABLE", error.code());
        assertTrue(PatientRegistrationService.class
                .getMethod("register", RegisterPatientRequest.class)
                .isAnnotationPresent(Transactional.class));
        assertTrue(delivery.lastToken == null || challenges.find(delivery.lastToken).isEmpty());
    }

    @Test
    void correctOtpVerifiesAndCannotBeReused() {
        OtpChallengeData challenge = service.register(validRequest()).verification();

        assertTrue(service.verify(challenge.verificationToken(), "123456").verified());
        assertTrue(gateway.account.emailVerified());

        AuthException reused = assertThrows(AuthException.class,
                () -> service.verify(challenge.verificationToken(), "123456"));
        assertEquals("INVALID_VERIFICATION_CHALLENGE", reused.code());
    }

    @Test
    void incorrectOtpIsRejectedAndAttemptLimitConsumesIt() {
        OtpChallengeData challenge = service.register(validRequest()).verification();

        for (int attempt = 0; attempt < 3; attempt++) {
            AuthException invalid = assertThrows(AuthException.class,
                    () -> service.verify(challenge.verificationToken(), "000000"));
            assertEquals("OTP_INVALID_OR_EXPIRED", invalid.code());
        }

        AuthException exhausted = assertThrows(AuthException.class,
                () -> service.verify(challenge.verificationToken(), "123456"));
        assertEquals("OTP_INVALID_OR_EXPIRED", exhausted.code());
    }

    @Test
    void expiredChallengeIsRejectedWithoutCheckingOtp() {
        OtpChallengeData challenge = service.register(validRequest()).verification();
        clock.advance(Duration.ofMinutes(31));

        AuthException expired = assertThrows(AuthException.class,
                () -> service.verify(challenge.verificationToken(), "123456"));

        assertEquals("INVALID_VERIFICATION_CHALLENGE", expired.code());
        assertEquals(0, gateway.verifyCalls);
    }

    @Test
    void resendReplacesPreviousOtpAndKeepsChallengeOpaque() {
        OtpChallengeData first = service.register(validRequest()).verification();
        gateway.nextCode = "654321";

        OtpChallengeData resent = service.resend(first.verificationToken());

        assertEquals(first.verificationToken(), resent.verificationToken());
        assertEquals("654321", delivery.lastCode);
        assertEquals(2, gateway.createOtpCalls);
        assertThrows(AuthException.class, () -> service.verify(first.verificationToken(), "123456"));
        assertTrue(service.verify(first.verificationToken(), "654321").verified());
    }

    @Test
    void resendLimitFromDatabaseIsReturnedAsControlledError() {
        OtpChallengeData challenge = service.register(validRequest()).verification();
        gateway.createOtpError = new AuthException(429, "OTP_RESEND_TOO_SOON", "Espera.", 60L);

        AuthException limited = assertThrows(AuthException.class,
                () -> service.resend(challenge.verificationToken()));

        assertEquals(429, limited.status());
        assertEquals(60L, limited.retryAfterSeconds());
    }

    @Test
    void unexpectedPersistenceFailureDoesNotExposeDatabaseDetails() {
        OtpChallengeData challenge = service.register(validRequest()).verification();
        gateway.createOtpError = new PersistenceException("detalle SQL sensible");

        AuthException failure = assertThrows(AuthException.class,
                () -> service.resend(challenge.verificationToken()));

        assertEquals(500, failure.status());
        assertEquals("AUTH_OPERATION_FAILED", failure.code());
        assertFalse(failure.getMessage().contains("SQL"));
    }

    @Test
    void pendingPatientCanResumeWithCredentialsButUnknownAccountGetsNeutralError() {
        service.register(validRequest());

        OtpChallengeData resumed = service.requestOtp(
                new OtpRequest("nuevo@clinica.bo", PASSWORD),
                "127.0.0.1");
        assertNotNull(resumed.verificationToken());

        AuthException unknown = assertThrows(AuthException.class, () -> service.requestOtp(
                new OtpRequest("nadie@clinica.bo", PASSWORD),
                "127.0.0.1"));
        assertEquals("INVALID_VERIFICATION_CREDENTIALS", unknown.code());
    }

    private static RegisterPatientRequest validRequest() {
        return new RegisterPatientRequest(
                "Paciente Prueba",
                "nuevo@clinica.bo",
                PASSWORD,
                "CI-9001",
                LocalDate.of(1995, 4, 12),
                "+591 70000678",
                "La Paz");
    }

    private static final class FakeGateway extends PatientRegistrationGateway {
        private boolean emailExists;
        private boolean documentExists;
        private boolean registerCalled;
        private PatientAccount account;
        private String activeCode;
        private String nextCode = "123456";
        private int invalidAttempts;
        private boolean consumed;
        private int createOtpCalls;
        private int verifyCalls;
        private RuntimeException createOtpError;

        @Override
        public boolean emailExists(String email) {
            return emailExists;
        }

        @Override
        public boolean documentExists(String ci) {
            return documentExists;
        }

        @Override
        public Optional<PatientAccount> findByEmail(String email) {
            return account != null && account.email().equals(email) ? Optional.of(account) : Optional.empty();
        }

        @Override
        public Optional<PatientAccount> findByUserId(String codUsu) {
            return account != null && account.userId().equals(codUsu) ? Optional.of(account) : Optional.empty();
        }

        @Override
        public PatientAccount register(
                String name,
                String email,
                String hash,
                String ci,
                LocalDate birthDate,
                String phone,
                String city) {
            registerCalled = true;
            account = new PatientAccount(
                    "USU000100", "PAC000100", name, email, hash, "ROL001",
                    "Activo", "Activo", phone, false);
            return account;
        }

        @Override
        public String createOtp(String codUsu) {
            if (createOtpError != null) {
                throw createOtpError;
            }
            createOtpCalls++;
            activeCode = nextCode;
            invalidAttempts = 0;
            consumed = false;
            return activeCode;
        }

        @Override
        public boolean verifyOtp(String codUsu, String code) {
            verifyCalls++;
            if (consumed || invalidAttempts >= 3) {
                return false;
            }
            if (activeCode.equals(code)) {
                consumed = true;
                account = new PatientAccount(
                        account.userId(), account.patientId(), account.name(), account.email(),
                        account.passwordHash(), account.roleCode(), account.userStatus(),
                        account.profileStatus(), account.phone(), true);
                return true;
            }
            invalidAttempts++;
            if (invalidAttempts >= 3) {
                consumed = true;
            }
            return false;
        }
    }

    private static final class FakeDeliveryProvider implements OtpDeliveryProvider {
        private boolean fail;
        private String lastToken;
        private String lastCode;

        @Override
        public DeliveryReceipt deliver(OtpMessage message) {
            lastToken = message.verificationToken();
            if (fail) {
                throw new AuthException(503, "OTP_PROVIDER_UNAVAILABLE", "No disponible.");
            }
            lastCode = message.code();
            return new DeliveryReceipt("DEMO", false);
        }

        @Override
        public DeliveryReceipt deliverPasswordReset(PasswordResetMessage message) {
            return new DeliveryReceipt("DEMO", false);
        }

        @Override
        public void discard(String verificationToken) {
            if (verificationToken != null && verificationToken.equals(lastToken)) {
                lastCode = null;
            }
        }
    }

    private static final class MutableClock extends Clock {
        private Instant instant;

        private MutableClock(Instant instant) {
            this.instant = instant;
        }

        void advance(Duration duration) {
            instant = instant.plus(duration);
        }

        @Override
        public ZoneId getZone() {
            return ZoneOffset.UTC;
        }

        @Override
        public Clock withZone(ZoneId zone) {
            return this;
        }

        @Override
        public Instant instant() {
            return instant;
        }
    }
}
