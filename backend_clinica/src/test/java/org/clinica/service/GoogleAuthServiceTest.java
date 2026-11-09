package org.clinica.service;

import org.clinica.dto.AuthDtos.GoogleCodeExchangeRequest;
import org.clinica.dto.AuthDtos.GooglePatientRegistrationRequest;
import org.clinica.dto.AuthDtos.LoginData;
import org.clinica.dto.AuthDtos.MeData;
import org.clinica.dto.AuthDtos.RoleResponse;
import org.clinica.dto.AuthDtos.SessionResponse;
import org.clinica.dto.AuthDtos.UserResponse;
import org.clinica.repository.AuthAccountGateway;
import org.clinica.repository.AuthAccountGateway.AuthAccount;
import org.clinica.repository.GoogleAccountGateway;
import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.clinica.repository.GoogleAccountGateway.GooglePatientDetails;
import org.clinica.repository.GoogleAccountGateway.LinkedGoogleIdentity;
import org.clinica.repository.PatientRegistrationGateway;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.HashMap;
import java.util.Map;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class GoogleAuthServiceTest {

    private FakeIdentityProvider identityProvider;
    private FakeGoogleAccounts accounts;
    private FakeAuthService auth;
    private GoogleAuthService service;

    @BeforeEach
    void setUp() {
        Clock clock = Clock.fixed(Instant.parse("2026-11-06T12:00:00Z"), ZoneOffset.UTC);
        identityProvider = new FakeIdentityProvider();
        accounts = new FakeGoogleAccounts();
        auth = new FakeAuthService();
        service = new GoogleAuthService(
                identityProvider,
                new GoogleContinuationStore(Duration.ofMinutes(10), clock),
                accounts,
                auth,
                new FakeRegistrationService(),
                clock);
    }

    @Test
    void linkedVerifiedPatientReceivesOnlyInternalSession() {
        accounts.account = patient("USU000010", "patient@example.test", true);
        accounts.linked = new LinkedGoogleIdentity("IDE00000001", "USU000010", "google-sub", "patient@example.test", true);

        var result = service.exchange(exchangeRequest());

        assertEquals("AUTHENTICATED", result.status());
        assertEquals("opaque-session", result.login().session().accessToken());
        assertEquals("ROL001", result.login().user().role().code());
    }

    @Test
    void unknownIdentityStartsPatientRegistrationWithoutCreatingAnAccountYet() {
        var result = service.exchange(exchangeRequest());

        assertEquals("REGISTRATION_REQUIRED", result.status());
        assertTrue(result.continuationToken().length() >= 40);
        assertEquals(0, accounts.registrationCount);
    }

    @Test
    void matchingEmailNeverLinksAutomatically() {
        accounts.account = patient("USU000010", "patient@example.test", true);

        var result = service.exchange(exchangeRequest());

        assertEquals("LINK_REQUIRED", result.status());
        assertEquals(0, accounts.linkCount);
    }

    @Test
    void matchingEmailCanBeLinkedOnlyAfterAnAuthenticatedPatientConfirms() {
        accounts.account = patient("USU000010", "patient@example.test", true);
        var exchange = service.exchange(exchangeRequest());

        var result = service.link("authenticated-local-session", exchange.continuationToken());

        assertTrue(result.linked());
        assertEquals(1, accounts.linkCount);
        assertEquals("USU000010", accounts.linked.userId());
    }

    @Test
    void completingRegistrationAlwaysCreatesPatientRoleAndConsumesTicket() {
        var exchange = service.exchange(exchangeRequest());
        var request = new GooglePatientRegistrationRequest(
                exchange.continuationToken(), "CI-9001", LocalDate.of(1995, 2, 3), "+59170000001", "Santa Cruz");

        var result = service.register(request);

        assertEquals("AUTHENTICATED", result.status());
        assertEquals("ROL001", accounts.lastRegisteredRole);
        AuthException replay = assertThrows(AuthException.class, () -> service.register(request));
        assertEquals("GOOGLE_CONTINUATION_INVALID", replay.code());
    }

    @Test
    void privilegedAccountCannotUseGoogleOrBeLinked() {
        accounts.account = staff("USU000004", "patient@example.test");
        accounts.linked = new LinkedGoogleIdentity("IDE00000001", "USU000004", "google-sub", "patient@example.test", true);

        AuthException error = assertThrows(AuthException.class, () -> service.exchange(exchangeRequest()));

        assertEquals("GOOGLE_PATIENT_ONLY", error.code());
    }

    private static GoogleCodeExchangeRequest exchangeRequest() {
        return new GoogleCodeExchangeRequest("code", str('v', 64), str('n', 43), "http://127.0.0.1/callback");
    }

    private static String str(char value, int length) {
        return String.valueOf(value).repeat(length);
    }

    private static AuthAccount patient(String id, String email, boolean verified) {
        return account(id, email, "ROL001", "Paciente", verified);
    }

    private static AuthAccount staff(String id, String email) {
        return account(id, email, "ROL004", "Administrador", true);
    }

    private static AuthAccount account(String id, String email, String role, String roleName, boolean verified) {
        return new AuthAccount(id, "Patient Test", email, null, role, roleName,
                "Activo", "Activo", true, "Activo", null, verified);
    }

    private static final class FakeIdentityProvider implements GoogleIdentityProvider {
        private final GoogleIdentity identity = new GoogleIdentity(
                "google-sub", "patient@example.test", "Patient Test", true);

        @Override
        public String authorizationUrl(AuthorizationRequest request) {
            return "https://accounts.google.com/o/oauth2/v2/auth";
        }

        @Override
        public GoogleIdentity exchange(ExchangeRequest request) {
            return identity;
        }
    }

    private static final class FakeGoogleAccounts extends GoogleAccountGateway {
        private AuthAccount account;
        private LinkedGoogleIdentity linked;
        private int registrationCount;
        private int linkCount;
        private String lastRegisteredRole;

        @Override
        public Optional<LinkedGoogleIdentity> findBySubject(String subject) {
            return Optional.ofNullable(linked);
        }

        @Override
        public Optional<AuthAccount> findAccountByEmail(String email) {
            return account != null && account.email().equals(email) ? Optional.of(account) : Optional.empty();
        }

        @Override
        public Optional<AuthAccount> findAccountById(String id) {
            return account != null && account.id().equals(id) ? Optional.of(account) : Optional.empty();
        }

        @Override
        public boolean documentExists(String document) {
            return false;
        }

        @Override
        public String registerPatient(GoogleIdentity identity, GooglePatientDetails details) {
            registrationCount++;
            account = patient("USU000099", identity.email(), identity.emailVerified());
            lastRegisteredRole = account.roleCode();
            linked = new LinkedGoogleIdentity("IDE00000099", account.id(), identity.subject(), identity.email(), true);
            return account.id();
        }

        @Override
        public void link(String userId, GoogleIdentity identity) {
            linkCount++;
            linked = new LinkedGoogleIdentity("IDE00000099", userId, identity.subject(), identity.email(), true);
        }

        @Override
        public void updateProviderMetadata(LinkedGoogleIdentity linked, GoogleIdentity identity) {
        }
    }

    private static final class FakeAuthService extends AuthService {
        private FakeAuthService() {
            super(new AuthAccountGateway(),
                    new AuthSessionStore(Duration.ofMinutes(10), Clock.systemUTC()),
                    new LoginAttemptGuard(5, Duration.ofMinutes(1), Duration.ofMinutes(1), Clock.systemUTC()));
        }

        @Override
        public LoginData loginTrustedUser(String userId) {
            return new LoginData(
                    new UserResponse(userId, "Patient Test", "patient@example.test",
                            new RoleResponse("ROL001", "Paciente", "paciente"), "Activo"),
                    new SessionResponse("opaque-session", "Bearer", OffsetDateTime.now(ZoneOffset.UTC).plusHours(1)));
        }

        @Override
        public MeData me(String rawToken) {
            return new MeData(
                    new UserResponse("USU000010", "Patient Test", "patient@example.test",
                            new RoleResponse("ROL001", "Paciente", "paciente"), "Activo"),
                    OffsetDateTime.now(ZoneOffset.UTC).plusHours(1));
        }
    }

    private static final class FakeRegistrationService extends PatientRegistrationService {
        private FakeRegistrationService() {
            super(new PatientRegistrationGateway(), new RegistrationChallengeStore(10),
                    new NoopDelivery(),
                    new LoginAttemptGuard(5, Duration.ofMinutes(1), Duration.ofMinutes(1), Clock.systemUTC()),
                    Clock.systemUTC());
        }

        @Override
        public org.clinica.dto.AuthDtos.OtpChallengeData issueEmailVerificationForUser(String userId) {
            throw new AssertionError("No debe emitir OTP para correo Google verificado.");
        }
    }

    private static final class NoopDelivery implements OtpDeliveryProvider {
        @Override public DeliveryReceipt deliver(OtpMessage message) { return new DeliveryReceipt("DEMO", false); }
        @Override public DeliveryReceipt deliverPasswordReset(PasswordResetMessage message) { return new DeliveryReceipt("DEMO", false); }
        @Override public void discard(String verificationToken) { }
    }
}
