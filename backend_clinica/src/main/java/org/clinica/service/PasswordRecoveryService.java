package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.PersistenceException;
import jakarta.transaction.Transactional;
import jakarta.ws.rs.core.UriBuilder;
import org.clinica.dto.AuthDtos.PasswordRecoveryData;
import org.clinica.dto.AuthDtos.PasswordResetData;
import org.clinica.repository.AuthAccountGateway;
import org.clinica.repository.CitaFunctionRepository;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Duration;
import java.util.Locale;
import java.util.regex.Pattern;

/** Recuperacion neutral, de un solo uso y con revocacion de todas las sesiones internas. */
@ApplicationScoped
public class PasswordRecoveryService {

    private static final Pattern EMAIL = Pattern.compile("^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$");
    private static final Duration TOKEN_TTL = Duration.ofMinutes(15);
    private static final String GENERIC_MESSAGE =
            "Si la cuenta es elegible, recibiras instrucciones para restablecer la contrasena.";

    private final CitaFunctionRepository functions;
    private final AuthAccountGateway accounts;
    private final ConfiguredOtpDeliveryProvider deliveryProvider;
    private final AuthService authService;
    private final String frontendBaseUrl;
    private final Clock clock;

    @Inject
    public PasswordRecoveryService(
            CitaFunctionRepository functions,
            AuthAccountGateway accounts,
            ConfiguredOtpDeliveryProvider deliveryProvider,
            AuthService authService,
            @ConfigProperty(name = "auth.frontend.base-url") String frontendBaseUrl) {
        this(functions, accounts, deliveryProvider, authService, frontendBaseUrl, Clock.systemUTC());
    }

    PasswordRecoveryService(
            CitaFunctionRepository functions,
            AuthAccountGateway accounts,
            ConfiguredOtpDeliveryProvider deliveryProvider,
            AuthService authService,
            String frontendBaseUrl,
            Clock clock) {
        this.functions = functions;
        this.accounts = accounts;
        this.deliveryProvider = deliveryProvider;
        this.authService = authService;
        this.frontendBaseUrl = frontendBaseUrl;
        this.clock = clock;
    }

    @Transactional
    public PasswordRecoveryData request(String suppliedEmail) {
        String email = normalizeEmail(suppliedEmail);
        if (email == null) {
            throw new AuthException(422, "VALIDATION_ERROR", "Introduce un correo valido.");
        }
        deliveryProvider.requireAvailable();
        final String rawToken;
        try {
            rawToken = functions.crearTokenRecuperacion(email);
        } catch (PersistenceException exception) {
            return genericResponse();
        }
        if (rawToken == null || rawToken.isBlank()) {
            return genericResponse();
        }

        String resetUrl = UriBuilder.fromUri(frontendBaseUrl)
                .path("restablecer-contrasena")
                .queryParam("email", email)
                .queryParam("token", rawToken)
                .build()
                .toASCIIString();
        try {
            deliveryProvider.deliverPasswordReset(new OtpDeliveryProvider.PasswordResetMessage(
                    email,
                    resetUrl,
                    clock.instant().plus(TOKEN_TTL)));
        } catch (AuthException deliveryFailure) {
            // La respuesta permanece neutra: no confirma existencia ni una entrega fallida.
        }
        return genericResponse();
    }

    @Transactional
    public PasswordResetData reset(String suppliedEmail, String rawToken, String password) {
        String email = normalizeEmail(suppliedEmail);
        if (email == null || rawToken == null || rawToken.length() < 32 || rawToken.length() > 512) {
            throw invalidToken();
        }
        validatePassword(password);
        boolean changed;
        try {
            changed = Boolean.TRUE.equals(functions.restablecerContrasena(
                    email,
                    rawToken,
                    BcryptUtil.bcryptHash(password, 12)));
        } catch (PersistenceException exception) {
            throw invalidToken();
        }
        if (!changed) {
            throw invalidToken();
        }
        accounts.findByEmail(email).ifPresent(account -> authService.revokeAllForUser(account.id()));
        return new PasswordResetData(true, "LOGIN");
    }

    static void validatePassword(String password) {
        if (password == null
                || password.length() < 10
                || password.getBytes(StandardCharsets.UTF_8).length > 72
                || password.chars().noneMatch(Character::isUpperCase)
                || password.chars().noneMatch(Character::isLowerCase)
                || password.chars().noneMatch(Character::isDigit)) {
            throw new AuthException(422, "VALIDATION_ERROR",
                    "La contrasena debe tener entre 10 y 72 bytes, mayusculas, minusculas y numeros.");
        }
    }

    private static String normalizeEmail(String email) {
        if (email == null || email.isBlank()) {
            return null;
        }
        String normalized = email.trim().toLowerCase(Locale.ROOT);
        return normalized.length() <= 254 && EMAIL.matcher(normalized).matches() ? normalized : null;
    }

    private static AuthException invalidToken() {
        return new AuthException(422, "PASSWORD_RESET_TOKEN_INVALID",
                "El enlace de recuperacion no es valido, ya fue utilizado o expiro.");
    }

    private static PasswordRecoveryData genericResponse() {
        return new PasswordRecoveryData(GENERIC_MESSAGE);
    }
}
