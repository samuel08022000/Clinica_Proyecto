package org.clinica.service;

import org.clinica.repository.AuthAccountGateway;
import org.clinica.repository.AuthAccountGateway.AuthAccount;
import org.clinica.repository.CitaFunctionRepository;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class PasswordRecoveryServiceTest {

    private FakeFunctions functions;
    private FakeDelivery delivery;
    private FakeAuthService auth;
    private PasswordRecoveryService service;

    @BeforeEach
    void setUp() {
        functions = new FakeFunctions();
        delivery = new FakeDelivery();
        auth = new FakeAuthService();
        service = new PasswordRecoveryService(
                functions,
                new FakeAccounts(),
                delivery,
                auth,
                "http://127.0.0.1:8000",
                Clock.fixed(Instant.parse("2026-11-06T12:00:00Z"), ZoneOffset.UTC));
    }

    @Test
    void unknownAndKnownEmailReceiveTheSamePublicMessage() {
        functions.token = null;
        var unknown = service.request("unknown@example.test");
        functions.token = "a".repeat(64);
        var known = service.request("patient@example.test");

        assertEquals(unknown.message(), known.message());
        assertNotNull(delivery.lastResetUrl);
        assertTrue(delivery.lastResetUrl.startsWith("http://127.0.0.1:8000/restablecer-contrasena?"));
    }

    @Test
    void validResetChangesPasswordAndRevokesEveryExistingSession() {
        functions.resetResult = true;

        var result = service.reset("patient@example.test", "x".repeat(64), "NuevaSegura123");

        assertTrue(result.reset());
        assertEquals("USU000010", auth.revokedUser);
        assertNotNull(functions.passwordHash);
        assertTrue(io.quarkus.elytron.security.common.BcryptUtil.matches("NuevaSegura123", functions.passwordHash));
    }

    @Test
    void invalidExpiredOrReusedTokenIsRejected() {
        functions.resetResult = false;

        AuthException error = assertThrows(AuthException.class,
                () -> service.reset("patient@example.test", "x".repeat(64), "NuevaSegura123"));

        assertEquals("PASSWORD_RESET_TOKEN_INVALID", error.code());
        assertEquals(null, auth.revokedUser);
    }

    private static final class FakeFunctions extends CitaFunctionRepository {
        private String token;
        private boolean resetResult;
        private String passwordHash;

        @Override
        public String crearTokenRecuperacion(String email) {
            return token;
        }

        @Override
        public Boolean restablecerContrasena(String email, String rawToken, String newHash) {
            passwordHash = newHash;
            return resetResult;
        }
    }

    private static final class FakeAccounts extends AuthAccountGateway {
        @Override
        public Optional<AuthAccount> findByEmail(String email) {
            if (!"patient@example.test".equals(email)) {
                return Optional.empty();
            }
            return Optional.of(new AuthAccount(
                    "USU000010", "Patient Test", email, null, "ROL001", "Paciente",
                    "Activo", "Activo", true, "Activo", (OffsetDateTime) null, true));
        }
    }

    private static final class FakeDelivery extends ConfiguredOtpDeliveryProvider {
        private String lastResetUrl;

        private FakeDelivery() {
            super("disabled", null, null);
        }

        @Override public void requireAvailable() { }
        @Override public String mode() { return "DEMO"; }

        @Override
        public DeliveryReceipt deliverPasswordReset(PasswordResetMessage message) {
            lastResetUrl = message.resetUrl();
            return new DeliveryReceipt("DEMO", false);
        }
    }

    private static final class FakeAuthService extends AuthService {
        private String revokedUser;

        private FakeAuthService() {
            super(new AuthAccountGateway(),
                    new AuthSessionStore(Duration.ofMinutes(10), Clock.systemUTC()),
                    new LoginAttemptGuard(5, Duration.ofMinutes(1), Duration.ofMinutes(1), Clock.systemUTC()));
        }

        @Override
        public void revokeAllForUser(String userId) {
            revokedUser = userId;
        }
    }
}
