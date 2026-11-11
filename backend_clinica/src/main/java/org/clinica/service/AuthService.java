package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.transaction.Status;
import jakarta.transaction.Synchronization;
import jakarta.transaction.TransactionSynchronizationRegistry;
import org.clinica.dto.AuthDtos.LoginData;
import org.clinica.dto.AuthDtos.MeData;
import org.clinica.dto.AuthDtos.RoleResponse;
import org.clinica.dto.AuthDtos.SessionResponse;
import org.clinica.dto.AuthDtos.UserResponse;
import org.clinica.repository.AuthAccountGateway;
import org.clinica.repository.AuthAccountGateway.AuthAccount;

import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Locale;
import java.util.Map;
import java.util.Objects;
import java.util.Set;
import java.util.stream.IntStream;

@ApplicationScoped
public class AuthService {

    private static final String INVALID_CREDENTIALS = "Correo o contrasena incorrectos.";
    private static final Map<String, String> ROLE_SLUGS = Map.of(
            "ROL001", "paciente",
            "ROL002", "secretaria",
            "ROL003", "medico",
            "ROL004", "administracion");

    private final AuthAccountGateway accountGateway;
    private final AuthSessionStore sessionStore;
    private final LoginAttemptGuard attemptGuard;
    private final String dummyHash;
    private final Object[] sessionLocks = IntStream.range(0, 64).mapToObj(i -> new Object()).toArray();

    @Inject
    TransactionSynchronizationRegistry transactions;

    @Inject
    public AuthService(
            AuthAccountGateway accountGateway,
            AuthSessionStore sessionStore,
            LoginAttemptGuard attemptGuard) {
        this.accountGateway = accountGateway;
        this.sessionStore = sessionStore;
        this.attemptGuard = attemptGuard;
        this.dummyHash = BcryptUtil.bcryptHash("dummy-comparison-only");
    }

    public LoginData login(String email, String password, String remoteAddress) {
        String normalizedEmail = normalizeEmail(email);
        if (normalizedEmail == null || password == null || password.isBlank()) {
            throw new AuthException(422, "VALIDATION_ERROR", "Correo y contrasena son obligatorios.");
        }

        attemptGuard.checkAllowed(normalizedEmail, remoteAddress);
        AuthAccount account = accountGateway.findByEmail(normalizedEmail).orElse(null);
        String hashToCheck = account == null || account.passwordHash() == null
                ? dummyHash
                : account.passwordHash();
        boolean passwordMatches;
        try {
            passwordMatches = BcryptUtil.matches(password, hashToCheck);
        } catch (RuntimeException ignored) {
            passwordMatches = false;
        }

        if (account == null || account.passwordHash() == null || !passwordMatches) {
            attemptGuard.recordFailure(normalizedEmail, remoteAddress);
            throw new AuthException(401, "INVALID_CREDENTIALS", INVALID_CREDENTIALS);
        }

        validateAccount(account);
        LoginData login = switch (account.roleCode()) {
            case "ROL002", "ROL003" -> issueStaffSession(account, normalizedEmail);
            default -> issueSession(account);
        };
        attemptGuard.recordSuccess(normalizedEmail, remoteAddress);
        return login;
    }

    /** Emite la sesion interna solo despues de que un proveedor confiable valido la identidad. */
    public LoginData loginTrustedUser(String userId) {
        AuthAccount account = accountGateway.findById(userId)
                .orElseThrow(() -> new AuthException(401, "ACCOUNT_NOT_FOUND", "La cuenta ya no esta disponible."));
        validateAccount(account);
        return issueSession(account);
    }

    public void revokeAllForUser(String userId) {
        synchronized (sessionLock(userId)) {
            sessionStore.revokeAllForUser(userId);
        }
    }

    public void revokeAllForUserAfterCommit(String userId) {
        transactions.registerInterposedSynchronization(new Synchronization() {
            @Override
            public void beforeCompletion() {
            }

            @Override
            public void afterCompletion(int status) {
                if (status == Status.STATUS_COMMITTED) {
                    revokeAllForUser(userId);
                }
            }
        });
    }

    public MeData me(String rawToken) {
        AuthSessionStore.StoredSession session = authenticatedSession(rawToken);
        AuthAccount account = accountGateway.findById(session.codUsu()).orElseThrow(() -> {
            sessionStore.revoke(rawToken);
            return new AuthException(401, "INVALID_SESSION", "La sesion ya no es valida.");
        });

        try {
            validateAccount(account);
        } catch (AuthException exception) {
            sessionStore.revoke(rawToken);
            throw exception;
        }

        return new MeData(
                toUserResponse(account),
                OffsetDateTime.ofInstant(session.expiresAt(), ZoneOffset.UTC));
    }

    public MeData requireAnyRole(String rawToken, Set<String> allowedRoles) {
        MeData authenticated = me(rawToken);
        if (!allowedRoles.contains(authenticated.user().role().code())) {
            throw new AuthException(403, "FORBIDDEN", "Tu rol no tiene permiso para acceder a este recurso.");
        }
        return authenticated;
    }

    public boolean logout(String rawToken) {
        authenticatedSession(rawToken);
        return sessionStore.revoke(rawToken);
    }

    private LoginData issueStaffSession(AuthAccount verified, String email) {
        // Coordina la emisión con la revocación administrativa, incluso si BCrypt se ejecutó antes del commit.
        synchronized (sessionLock(verified.id())) {
            AuthAccount current = accountGateway.findCurrentById(verified.id())
                    .orElseThrow(() -> new AuthException(401, "INVALID_CREDENTIALS", INVALID_CREDENTIALS));
            if (!Objects.equals(verified.passwordHash(), current.passwordHash())
                    || !verified.roleCode().equals(current.roleCode())
                    || !email.equals(normalizeEmail(current.email()))) {
                throw new AuthException(401, "INVALID_CREDENTIALS", INVALID_CREDENTIALS);
            }
            validateAccount(current);
            return issueSession(current);
        }
    }

    private Object sessionLock(String userId) {
        return sessionLocks[Math.floorMod(userId.hashCode(), sessionLocks.length)];
    }

    private LoginData issueSession(AuthAccount account) {
        sessionStore.revokeAllForUser(account.id());
        AuthSessionStore.IssuedSession issued = sessionStore.issue(account.id());
        return new LoginData(
                toUserResponse(account),
                new SessionResponse(
                        issued.rawToken(),
                        "Bearer",
                        OffsetDateTime.ofInstant(issued.expiresAt(), ZoneOffset.UTC)));
    }

    private AuthSessionStore.StoredSession authenticatedSession(String rawToken) {
        if (rawToken == null || rawToken.isBlank()) {
            throw new AuthException(401, "AUTHENTICATION_REQUIRED", "Debes iniciar sesion para continuar.");
        }
        return sessionStore.find(rawToken)
                .orElseThrow(() -> new AuthException(401, "INVALID_SESSION", "La sesion expiro o fue revocada."));
    }

    private static void validateAccount(AuthAccount account) {
        if (!ROLE_SLUGS.containsKey(account.roleCode()) || account.roleName() == null) {
            throw new AuthException(403, "ROLE_NOT_ALLOWED", "La cuenta no tiene un rol autorizado.");
        }
        if (!"Activo".equals(account.roleStatus())) {
            throw new AuthException(403, "ROLE_DISABLED", "El rol de la cuenta esta inactivo.");
        }
        if (!"Activo".equals(account.userStatus())) {
            throw new AuthException(403, "ACCOUNT_DISABLED", "La cuenta esta inactiva.");
        }
        if (!account.profilePresent()) {
            throw new AuthException(403, "PROFILE_UNAVAILABLE", "La cuenta no tiene un perfil habilitado.");
        }
        if ("Bloqueado_Temporal".equals(account.profileStatus())) {
            String message = account.blockedUntil() == null
                    ? "La cuenta esta bloqueada temporalmente."
                    : "La cuenta esta bloqueada temporalmente hasta " + account.blockedUntil() + ".";
            throw new AuthException(423, "ACCOUNT_TEMPORARILY_BLOCKED", message);
        }
        if (!"Activo".equals(account.profileStatus())) {
            throw new AuthException(403, "PROFILE_DISABLED", "El perfil de la cuenta esta inactivo.");
        }
        if ("ROL001".equals(account.roleCode()) && !Boolean.TRUE.equals(account.emailVerified())) {
            throw new AuthException(
                    403,
                    "EMAIL_VERIFICATION_REQUIRED",
                    "Debes verificar tu correo antes de iniciar sesion.");
        }
    }

    private static UserResponse toUserResponse(AuthAccount account) {
        RoleResponse role = new RoleResponse(
                account.roleCode(),
                account.roleName(),
                ROLE_SLUGS.get(account.roleCode()));
        return new UserResponse(
                account.id(),
                account.name(),
                account.email(),
                role,
                account.profileStatus());
    }

    private static String normalizeEmail(String email) {
        if (email == null || email.isBlank()) {
            return null;
        }
        String normalized = email.trim().toLowerCase(Locale.ROOT);
        return normalized.length() <= 254 && normalized.contains("@") ? normalized : null;
    }
}
