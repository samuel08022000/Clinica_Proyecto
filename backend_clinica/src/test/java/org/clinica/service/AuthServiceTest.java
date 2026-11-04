package org.clinica.service;

import org.clinica.dto.AuthDtos.LoginData;
import org.clinica.repository.AuthAccountGateway;
import org.clinica.repository.AuthAccountGateway.AuthAccount;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneId;
import java.time.ZoneOffset;
import java.util.HashMap;
import java.util.Map;
import java.util.Optional;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class AuthServiceTest {

    private static final String PASSWORD = "KnownPassword123";

    private FakeAccountGateway gateway;
    private MutableClock clock;
    private AuthSessionStore sessions;
    private AuthService service;

    @BeforeEach
    void setUp() {
        gateway = new FakeAccountGateway();
        clock = new MutableClock(Instant.parse("2026-11-03T12:00:00Z"));
        sessions = new AuthSessionStore(Duration.ofMinutes(30), clock);
        LoginAttemptGuard attempts = new LoginAttemptGuard(
                5,
                Duration.ofMinutes(15),
                Duration.ofMinutes(15),
                clock);
        service = new AuthService(gateway, sessions, attempts);
        gateway.put(activeAccount("ROL001", "Paciente", "Activo", null));
    }

    @Test
    void validUserReceivesRecognizedRoleAndSession() {
        LoginData result = service.login(" PACIENTE@CLINICA.BO ", PASSWORD, "127.0.0.1");

        assertEquals("ROL001", result.user().role().code());
        assertEquals("paciente", result.user().role().slug());
        assertEquals("Bearer", result.session().tokenType());
        assertTrue(result.session().accessToken().length() >= 40);
        assertEquals(result.user().id(), service.me(result.session().accessToken()).user().id());
    }

    @Test
    void wrongPasswordIsRejected() {
        AuthException error = assertThrows(AuthException.class,
                () -> service.login("paciente@clinica.bo", "IncorrectPassword", "127.0.0.1"));

        assertEquals(401, error.status());
        assertEquals("INVALID_CREDENTIALS", error.code());
    }

    @Test
    void unknownUserUsesTheSamePublicError() {
        AuthException error = assertThrows(AuthException.class,
                () -> service.login("nadie@clinica.bo", PASSWORD, "127.0.0.1"));

        assertEquals(401, error.status());
        assertEquals("INVALID_CREDENTIALS", error.code());
    }

    @Test
    void disabledUserIsRejected() {
        gateway.put(account("USU000002", "inactivo@clinica.bo", "ROL002", "Secretario",
                "Inactivo", "Activo", true, "Activo", null));

        AuthException error = assertThrows(AuthException.class,
                () -> service.login("inactivo@clinica.bo", PASSWORD, "127.0.0.1"));

        assertEquals(403, error.status());
        assertEquals("ACCOUNT_DISABLED", error.code());
    }

    @Test
    void temporarilyBlockedPatientIsRejected() {
        gateway.put(account("USU000003", "bloqueado@clinica.bo", "ROL001", "Paciente",
                "Activo", "Activo", true, "Bloqueado_Temporal",
                OffsetDateTime.parse("2026-11-04T12:00:00Z")));

        AuthException error = assertThrows(AuthException.class,
                () -> service.login("bloqueado@clinica.bo", PASSWORD, "127.0.0.1"));

        assertEquals(423, error.status());
        assertEquals("ACCOUNT_TEMPORARILY_BLOCKED", error.code());
    }

    @Test
    void unrecognizedRoleIsRejected() {
        gateway.put(account("USU000004", "sinrol@clinica.bo", "ROL999", null,
                "Activo", null, false, null, null));

        AuthException error = assertThrows(AuthException.class,
                () -> service.login("sinrol@clinica.bo", PASSWORD, "127.0.0.1"));

        assertEquals(403, error.status());
        assertEquals("ROLE_NOT_ALLOWED", error.code());
    }

    @Test
    void authenticatedRoleCannotUseAnotherRolesAuthorization() {
        LoginData result = service.login("paciente@clinica.bo", PASSWORD, "127.0.0.1");

        AuthException error = assertThrows(AuthException.class,
                () -> service.requireAnyRole(result.session().accessToken(), Set.of("ROL004")));

        assertEquals(403, error.status());
        assertEquals("FORBIDDEN", error.code());
    }

    @Test
    void excessiveAttemptsAreRateLimited() {
        LoginAttemptGuard attempts = new LoginAttemptGuard(
                2,
                Duration.ofMinutes(15),
                Duration.ofMinutes(15),
                clock);
        service = new AuthService(gateway, sessions, attempts);

        assertThrows(AuthException.class,
                () -> service.login("paciente@clinica.bo", "wrong-one", "127.0.0.1"));
        AuthException error = assertThrows(AuthException.class,
                () -> service.login("paciente@clinica.bo", "wrong-two", "127.0.0.1"));

        assertEquals(429, error.status());
        assertEquals("TOO_MANY_ATTEMPTS", error.code());
        assertTrue(error.retryAfterSeconds() > 0);
    }

    @Test
    void expiredSessionIsRejected() {
        LoginData result = service.login("paciente@clinica.bo", PASSWORD, "127.0.0.1");
        clock.advance(Duration.ofMinutes(31));

        AuthException error = assertThrows(AuthException.class,
                () -> service.me(result.session().accessToken()));

        assertEquals(401, error.status());
        assertEquals("INVALID_SESSION", error.code());
    }

    @Test
    void logoutRevokesTheToken() {
        LoginData result = service.login("paciente@clinica.bo", PASSWORD, "127.0.0.1");

        assertTrue(service.logout(result.session().accessToken()));
        assertThrows(AuthException.class, () -> service.me(result.session().accessToken()));
    }

    private static AuthAccount activeAccount(String roleCode, String roleName, String profileStatus,
                                             OffsetDateTime blockedUntil) {
        return account("USU000001", "paciente@clinica.bo", roleCode, roleName,
                "Activo", "Activo", true, profileStatus, blockedUntil);
    }

    private static AuthAccount account(
            String id,
            String email,
            String roleCode,
            String roleName,
            String userStatus,
            String roleStatus,
            boolean profilePresent,
            String profileStatus,
            OffsetDateTime blockedUntil) {
        return new AuthAccount(
                id,
                "Usuario de prueba",
                email,
                io.quarkus.elytron.security.common.BcryptUtil.bcryptHash(PASSWORD),
                roleCode,
                roleName,
                userStatus,
                roleStatus,
                profilePresent,
                profileStatus,
                blockedUntil);
    }

    private static final class FakeAccountGateway extends AuthAccountGateway {
        private final Map<String, AuthAccount> byEmail = new HashMap<>();
        private final Map<String, AuthAccount> byId = new HashMap<>();

        void put(AuthAccount account) {
            byEmail.put(account.email(), account);
            byId.put(account.id(), account);
        }

        @Override
        public Optional<AuthAccount> findByEmail(String email) {
            return Optional.ofNullable(byEmail.get(email));
        }

        @Override
        public Optional<AuthAccount> findById(String codUsu) {
            return Optional.ofNullable(byId.get(codUsu));
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
