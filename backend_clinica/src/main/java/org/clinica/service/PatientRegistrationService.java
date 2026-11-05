package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.PersistenceException;
import jakarta.transaction.Transactional;
import org.clinica.dto.AuthDtos.OtpChallengeData;
import org.clinica.dto.AuthDtos.OtpRequest;
import org.clinica.dto.AuthDtos.OtpStatusData;
import org.clinica.dto.AuthDtos.OtpVerificationData;
import org.clinica.dto.AuthDtos.RegisterPatientRequest;
import org.clinica.dto.AuthDtos.RegistrationData;
import org.clinica.repository.PatientRegistrationGateway;
import org.clinica.repository.PatientRegistrationGateway.PatientAccount;
import org.postgresql.util.PSQLException;

import java.nio.charset.StandardCharsets;
import java.sql.SQLException;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Collections;
import java.util.IdentityHashMap;
import java.util.Locale;
import java.util.Set;
import java.util.regex.Pattern;

@ApplicationScoped
public class PatientRegistrationService {

    private static final Duration OTP_TTL = Duration.ofMinutes(5);
    private static final Duration OTP_RESEND_DELAY = Duration.ofSeconds(60);
    private static final Pattern EMAIL = Pattern.compile("^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$");
    private static final Pattern NAME = Pattern.compile("^[\\p{L}][\\p{L} .'-]{1,148}[\\p{L}.]$");
    private static final Pattern CITY = Pattern.compile("^[\\p{L}][\\p{L} .'-]{1,98}[\\p{L}.]$");

    private final PatientRegistrationGateway gateway;
    private final RegistrationChallengeStore challenges;
    private final OtpDeliveryProvider deliveryProvider;
    private final LoginAttemptGuard attemptGuard;
    private final Clock clock;
    private final String dummyHash;

    @Inject
    public PatientRegistrationService(
            PatientRegistrationGateway gateway,
            RegistrationChallengeStore challenges,
            OtpDeliveryProvider deliveryProvider,
            LoginAttemptGuard attemptGuard) {
        this(gateway, challenges, deliveryProvider, attemptGuard, Clock.systemUTC());
    }

    PatientRegistrationService(
            PatientRegistrationGateway gateway,
            RegistrationChallengeStore challenges,
            OtpDeliveryProvider deliveryProvider,
            LoginAttemptGuard attemptGuard,
            Clock clock) {
        this.gateway = gateway;
        this.challenges = challenges;
        this.deliveryProvider = deliveryProvider;
        this.attemptGuard = attemptGuard;
        this.clock = clock;
        this.dummyHash = BcryptUtil.bcryptHash("dummy-registration-comparison-only");
    }

    @Transactional
    public RegistrationData register(RegisterPatientRequest request) {
        ValidRegistration valid = validateRegistration(request);
        if (gateway.emailExists(valid.email())) {
            throw new AuthException(409, "EMAIL_ALREADY_REGISTERED", "El correo ya esta registrado.");
        }
        if (gateway.documentExists(valid.ci())) {
            throw new AuthException(409, "DOCUMENT_ALREADY_REGISTERED", "El documento ya esta registrado.");
        }

        PatientAccount account;
        try {
            account = gateway.register(
                    valid.name(),
                    valid.email(),
                    BcryptUtil.bcryptHash(valid.password(), 12),
                    valid.ci(),
                    valid.birthDate(),
                    valid.phone(),
                    valid.city());
        } catch (PersistenceException exception) {
            throw translatePersistence(exception);
        }

        OtpChallengeData verification = issueAndDeliver(account);
        return new RegistrationData(account.patientId(), "ROL001", true, verification);
    }

    @Transactional
    public OtpChallengeData requestOtp(OtpRequest request, String remoteAddress) {
        String email = normalizeEmail(request == null ? null : request.email());
        String password = request == null ? null : request.password();
        if (email == null || password == null || password.isBlank()) {
            throw new AuthException(422, "VALIDATION_ERROR", "Correo y contrasena son obligatorios.");
        }

        attemptGuard.checkAllowed(email, remoteAddress);
        PatientAccount account = gateway.findByEmail(email).orElse(null);
        String hash = account == null ? dummyHash : account.passwordHash();
        boolean matches;
        try {
            matches = BcryptUtil.matches(password, hash);
        } catch (RuntimeException ignored) {
            matches = false;
        }

        if (account == null || !matches) {
            attemptGuard.recordFailure(email, remoteAddress);
            throw new AuthException(
                    401,
                    "INVALID_VERIFICATION_CREDENTIALS",
                    "No fue posible iniciar la verificacion con esos datos.");
        }
        attemptGuard.recordSuccess(email, remoteAddress);
        requireEligiblePendingAccount(account);
        return issueAndDeliver(account);
    }

    @Transactional
    public OtpVerificationData verify(String verificationToken, String code) {
        if (code == null || !code.matches("^[0-9]{6}$")) {
            throw new AuthException(422, "OTP_FORMAT_INVALID", "El codigo debe contener seis digitos.");
        }
        RegistrationChallengeStore.Challenge challenge = requireChallenge(verificationToken);
        boolean verified;
        try {
            verified = gateway.verifyOtp(challenge.codUsu(), code);
        } catch (PersistenceException exception) {
            throw translatePersistence(exception);
        }
        if (!verified) {
            throw new AuthException(
                    422,
                    "OTP_INVALID_OR_EXPIRED",
                    "El codigo no es valido, expiro o alcanzo el limite de intentos.");
        }
        challenges.revoke(verificationToken);
        deliveryProvider.discard(verificationToken);
        return new OtpVerificationData(true, "LOGIN");
    }

    @Transactional
    public OtpChallengeData resend(String verificationToken) {
        RegistrationChallengeStore.Challenge challenge = requireChallenge(verificationToken);
        PatientAccount account = gateway.findByUserId(challenge.codUsu())
                .orElseThrow(() -> invalidChallenge());
        requireEligiblePendingAccount(account);
        return deliverForExistingChallenge(verificationToken, challenge, account);
    }

    public OtpStatusData status(String verificationToken) {
        RegistrationChallengeStore.Challenge challenge = requireChallenge(verificationToken);
        PatientAccount account = gateway.findByUserId(challenge.codUsu())
                .orElseThrow(() -> invalidChallenge());
        return new OtpStatusData(
                account.phoneVerified(),
                challenge.maskedDestination(),
                OffsetDateTime.ofInstant(challenge.expiresAt(), ZoneOffset.UTC));
    }

    private OtpChallengeData issueAndDeliver(PatientAccount account) {
        requireEligiblePendingAccount(account);
        String masked = maskPhone(account.phone());
        RegistrationChallengeStore.IssuedChallenge issued = challenges.issue(account.userId(), masked);
        RegistrationChallengeStore.Challenge challenge = new RegistrationChallengeStore.Challenge(
                account.userId(), masked, issued.expiresAt());
        try {
            return deliverForExistingChallenge(issued.rawToken(), challenge, account);
        } catch (RuntimeException exception) {
            challenges.revoke(issued.rawToken());
            deliveryProvider.discard(issued.rawToken());
            throw exception;
        }
    }

    private OtpChallengeData deliverForExistingChallenge(
            String verificationToken,
            RegistrationChallengeStore.Challenge challenge,
            PatientAccount account) {
        String code;
        try {
            code = gateway.createOtp(account.userId());
        } catch (PersistenceException exception) {
            throw translatePersistence(exception);
        }
        Instant now = clock.instant();
        Instant otpExpiresAt = now.plus(OTP_TTL);
        deliveryProvider.deliver(new OtpDeliveryProvider.OtpMessage(
                verificationToken,
                account.phone(),
                code,
                otpExpiresAt));
        return new OtpChallengeData(
                verificationToken,
                challenge.maskedDestination(),
                OffsetDateTime.ofInstant(otpExpiresAt, ZoneOffset.UTC),
                OffsetDateTime.ofInstant(now.plus(OTP_RESEND_DELAY), ZoneOffset.UTC),
                OffsetDateTime.ofInstant(challenge.expiresAt(), ZoneOffset.UTC));
    }

    private RegistrationChallengeStore.Challenge requireChallenge(String rawToken) {
        return challenges.find(rawToken).orElseThrow(PatientRegistrationService::invalidChallenge);
    }

    private static void requireEligiblePendingAccount(PatientAccount account) {
        if (!"ROL001".equals(account.roleCode())
                || !"Activo".equals(account.userStatus())
                || !"Activo".equals(account.profileStatus())) {
            throw new AuthException(403, "ACCOUNT_NOT_ELIGIBLE", "La cuenta no esta habilitada para verificacion.");
        }
        if (account.phoneVerified()) {
            throw new AuthException(409, "PHONE_ALREADY_VERIFIED", "El telefono ya fue verificado.");
        }
    }

    private ValidRegistration validateRegistration(RegisterPatientRequest request) {
        if (request == null) {
            throw validation("Los datos de registro son obligatorios.");
        }
        String name = trim(request.nombreCompleto());
        String email = normalizeEmail(request.email());
        String password = request.password();
        String ci = trim(request.ci());
        String phone = normalizePhone(request.telefono());
        String city = trim(request.ciudadResidencia());
        LocalDate birthDate = request.fechaNacimiento();

        if (name == null || name.length() > 150 || !NAME.matcher(name).matches()) {
            throw validation("El nombre completo no tiene un formato valido.");
        }
        if (email == null) {
            throw validation("El correo no tiene un formato valido.");
        }
        validatePassword(password);
        String normalizedDocument = ci == null ? "" : ci.replaceAll("[^\\p{L}\\p{N}]", "");
        if (ci == null || ci.length() > 30 || normalizedDocument.length() < 4) {
            throw validation("El documento no tiene un formato valido.");
        }
        if (birthDate == null || birthDate.isAfter(LocalDate.now(clock))) {
            throw validation("La fecha de nacimiento no puede estar en el futuro.");
        }
        if (phone == null) {
            throw validation("El telefono debe contener entre 8 y 15 digitos.");
        }
        if (city == null || city.length() > 100 || !CITY.matcher(city).matches()) {
            throw validation("La ciudad no tiene un formato valido.");
        }
        return new ValidRegistration(name, email, password, ci, birthDate, phone, city);
    }

    private static void validatePassword(String password) {
        if (password == null
                || password.length() < 10
                || password.getBytes(StandardCharsets.UTF_8).length > 72
                || password.chars().noneMatch(Character::isUpperCase)
                || password.chars().noneMatch(Character::isLowerCase)
                || password.chars().noneMatch(Character::isDigit)) {
            throw validation("La contrasena debe tener entre 10 y 72 bytes, mayusculas, minusculas y numeros.");
        }
    }

    private static String normalizeEmail(String value) {
        String email = trim(value);
        if (email == null || email.length() > 254 || !EMAIL.matcher(email).matches()) {
            return null;
        }
        return email.toLowerCase(Locale.ROOT);
    }

    private static String normalizePhone(String value) {
        String phone = trim(value);
        if (phone == null || phone.length() > 25 || !phone.matches("^\\+?[0-9 ()-]+$")) {
            return null;
        }
        boolean international = phone.startsWith("+");
        String digits = phone.replaceAll("[^0-9]", "");
        if (digits.length() < 8 || digits.length() > 15) {
            return null;
        }
        return (international ? "+" : "") + digits;
    }

    private static String maskPhone(String phone) {
        String digits = phone == null ? "" : phone.replaceAll("[^0-9]", "");
        String suffix = digits.length() <= 3 ? digits : digits.substring(digits.length() - 3);
        return "*** *** " + suffix;
    }

    private static String trim(String value) {
        if (value == null || value.isBlank()) {
            return null;
        }
        return value.trim().replaceAll("\\s+", " ");
    }

    private static AuthException validation(String message) {
        return new AuthException(422, "VALIDATION_ERROR", message);
    }

    private static AuthException invalidChallenge() {
        return new AuthException(
                401,
                "INVALID_VERIFICATION_CHALLENGE",
                "La solicitud de verificacion expiro o no es valida.");
    }

    private static RuntimeException translatePersistence(PersistenceException exception) {
        PSQLException psql = findPostgresException(exception);
        if (psql == null || psql.getServerErrorMessage() == null) {
            return persistenceFailure();
        }
        String constraint = psql.getServerErrorMessage().getConstraint();
        String message = String.valueOf(psql.getServerErrorMessage().getMessage()).toLowerCase(Locale.ROOT);
        if ("uq_usuarios_email_ci".equals(constraint)) {
            return new AuthException(409, "EMAIL_ALREADY_REGISTERED", "El correo ya esta registrado.");
        }
        if ("uq_pacientes_ci_normalizado".equals(constraint)) {
            return new AuthException(409, "DOCUMENT_ALREADY_REGISTERED", "El documento ya esta registrado.");
        }
        if (message.contains("60 segundos")) {
            return new AuthException(429, "OTP_RESEND_TOO_SOON", "Espera antes de solicitar otro codigo.", 60L);
        }
        if (message.contains("limite de solicitudes otp") || message.contains("límite de solicitudes otp")) {
            return new AuthException(429, "OTP_HOURLY_LIMIT_REACHED", "Se alcanzo el limite de codigos por hora.", 3600L);
        }
        return persistenceFailure();
    }

    private static AuthException persistenceFailure() {
        return new AuthException(
                500,
                "AUTH_OPERATION_FAILED",
                "No fue posible completar la operacion de autenticacion.");
    }

    private static PSQLException findPostgresException(Throwable throwable) {
        Set<Throwable> visited = Collections.newSetFromMap(new IdentityHashMap<>());
        Throwable current = throwable;
        while (current != null && visited.add(current)) {
            if (current instanceof PSQLException psql) {
                return psql;
            }
            if (current instanceof SQLException sql && sql.getNextException() instanceof PSQLException next) {
                return next;
            }
            current = current.getCause();
        }
        return null;
    }

    private record ValidRegistration(
            String name,
            String email,
            String password,
            String ci,
            LocalDate birthDate,
            String phone,
            String city) {
    }
}
