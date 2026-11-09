package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.persistence.PersistenceException;
import jakarta.transaction.Transactional;
import org.clinica.dto.AuthDtos.GoogleAuthorizationData;
import org.clinica.dto.AuthDtos.GoogleAuthorizationRequest;
import org.clinica.dto.AuthDtos.GoogleCodeExchangeRequest;
import org.clinica.dto.AuthDtos.GoogleExchangeData;
import org.clinica.dto.AuthDtos.GoogleLinkData;
import org.clinica.dto.AuthDtos.GooglePatientRegistrationRequest;
import org.clinica.dto.AuthDtos.LoginData;
import org.clinica.dto.AuthDtos.OtpChallengeData;
import org.clinica.repository.AuthAccountGateway.AuthAccount;
import org.clinica.repository.GoogleAccountGateway;
import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.clinica.repository.GoogleAccountGateway.GooglePatientDetails;

import java.time.Clock;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Locale;
import java.util.regex.Pattern;

/** Orquesta OIDC sin aceptar nunca correo o subject sin validacion criptografica previa. */
@ApplicationScoped
public class GoogleAuthService {

    private static final Pattern CITY = Pattern.compile("^[\\p{L}][\\p{L} .'-]{1,98}[\\p{L}.]$");

    private final GoogleIdentityProvider identityProvider;
    private final GoogleContinuationStore continuations;
    private final GoogleAccountGateway accounts;
    private final AuthService authService;
    private final PatientRegistrationService registrationService;
    private final Clock clock;

    @Inject
    public GoogleAuthService(
            GoogleIdentityProvider identityProvider,
            GoogleContinuationStore continuations,
            GoogleAccountGateway accounts,
            AuthService authService,
            PatientRegistrationService registrationService) {
        this(identityProvider, continuations, accounts, authService, registrationService, Clock.systemUTC());
    }

    GoogleAuthService(
            GoogleIdentityProvider identityProvider,
            GoogleContinuationStore continuations,
            GoogleAccountGateway accounts,
            AuthService authService,
            PatientRegistrationService registrationService,
            Clock clock) {
        this.identityProvider = identityProvider;
        this.continuations = continuations;
        this.accounts = accounts;
        this.authService = authService;
        this.registrationService = registrationService;
        this.clock = clock;
    }

    public GoogleAuthorizationData authorizationUrl(GoogleAuthorizationRequest request) {
        if (request == null) {
            throw validation("Los parametros OAuth son obligatorios.");
        }
        String url = identityProvider.authorizationUrl(new GoogleIdentityProvider.AuthorizationRequest(
                request.state(), request.nonce(), request.codeChallenge(), request.redirectUri()));
        return new GoogleAuthorizationData(url);
    }

    @Transactional
    public GoogleExchangeData exchange(GoogleCodeExchangeRequest request) {
        if (request == null) {
            throw validation("La respuesta OAuth es obligatoria.");
        }
        GoogleIdentity identity = identityProvider.exchange(new GoogleIdentityProvider.ExchangeRequest(
                request.code(), request.codeVerifier(), request.nonce(), request.redirectUri()));

        var linked = accounts.findBySubject(identity.subject());
        if (linked.isPresent()) {
            AuthAccount account = accounts.findAccountById(linked.get().userId())
                    .orElseThrow(() -> new AuthException(401, "ACCOUNT_NOT_FOUND", "La cuenta ya no esta disponible."));
            requirePatient(account);
            accounts.updateProviderMetadata(linked.get(), identity);
            if (!Boolean.TRUE.equals(account.emailVerified())) {
                OtpChallengeData verification = registrationService.issueEmailVerificationForUser(account.id());
                return response("EMAIL_VERIFICATION_REQUIRED", null, null, identity, verification);
            }
            return response("AUTHENTICATED", authService.loginTrustedUser(account.id()), null, identity, null);
        }

        if (accounts.findAccountByEmail(identity.email()).isPresent()) {
            var ticket = continuations.issue(GoogleContinuationStore.Purpose.LINK, identity);
            return response("LINK_REQUIRED", null, ticket, identity, null);
        }

        var ticket = continuations.issue(GoogleContinuationStore.Purpose.REGISTER, identity);
        return response("REGISTRATION_REQUIRED", null, ticket, identity, null);
    }

    @Transactional
    public GoogleExchangeData register(GooglePatientRegistrationRequest request) {
        ValidDetails details = validateDetails(request);
        GoogleContinuationStore.Continuation continuation = continuations
                .claim(request.continuationToken(), GoogleContinuationStore.Purpose.REGISTER)
                .orElseThrow(GoogleAuthService::invalidContinuation);
        GoogleIdentity identity = continuation.identity();

        if (accounts.findBySubject(identity.subject()).isPresent()
                || accounts.findAccountByEmail(identity.email()).isPresent()) {
            throw new AuthException(409, "GOOGLE_ACCOUNT_CONFLICT", "No fue posible completar el registro con Google.");
        }
        if (accounts.documentExists(details.document())) {
            throw new AuthException(409, "DOCUMENT_ALREADY_REGISTERED", "El documento ya esta registrado.");
        }

        final String userId;
        try {
            userId = accounts.registerPatient(identity, new GooglePatientDetails(
                    details.document(), details.birthDate(), details.phone(), details.city()));
        } catch (PersistenceException exception) {
            throw new AuthException(409, "GOOGLE_ACCOUNT_CONFLICT", "No fue posible completar el registro con Google.");
        }

        if (!identity.emailVerified()) {
            OtpChallengeData verification = registrationService.issueEmailVerificationForUser(userId);
            return response("EMAIL_VERIFICATION_REQUIRED", null, null, identity, verification);
        }
        return response("AUTHENTICATED", authService.loginTrustedUser(userId), null, identity, null);
    }

    @Transactional
    public GoogleLinkData link(String rawSessionToken, String continuationToken) {
        GoogleContinuationStore.Continuation continuation = continuations
                .claim(continuationToken, GoogleContinuationStore.Purpose.LINK)
                .orElseThrow(GoogleAuthService::invalidContinuation);
        AuthAccount account = accounts.findAccountById(authService.me(rawSessionToken).user().id())
                .orElseThrow(() -> new AuthException(401, "ACCOUNT_NOT_FOUND", "La cuenta ya no esta disponible."));
        requirePatient(account);
        GoogleIdentity identity = continuation.identity();
        if (!account.email().equalsIgnoreCase(identity.email())) {
            throw new AuthException(403, "GOOGLE_LINK_NOT_ALLOWED", "No fue posible vincular la cuenta de Google.");
        }
        if (accounts.findBySubject(identity.subject()).isPresent()) {
            throw new AuthException(409, "GOOGLE_IDENTITY_ALREADY_LINKED", "La identidad de Google ya esta vinculada.");
        }
        try {
            accounts.link(account.id(), identity);
        } catch (PersistenceException exception) {
            throw new AuthException(409, "GOOGLE_IDENTITY_ALREADY_LINKED", "La identidad de Google ya esta vinculada.");
        }
        return new GoogleLinkData(true);
    }

    private static GoogleExchangeData response(
            String status,
            LoginData login,
            GoogleContinuationStore.IssuedContinuation ticket,
            GoogleIdentity identity,
            OtpChallengeData verification) {
        return new GoogleExchangeData(
                status,
                login,
                ticket == null ? null : ticket.rawToken(),
                ticket == null ? null : OffsetDateTime.ofInstant(ticket.expiresAt(), ZoneOffset.UTC),
                identity.name(),
                identity.email(),
                identity.emailVerified(),
                verification);
    }

    private ValidDetails validateDetails(GooglePatientRegistrationRequest request) {
        if (request == null || request.continuationToken() == null || request.continuationToken().isBlank()) {
            throw validation("La continuacion del registro no es valida.");
        }
        String document = trim(request.ci());
        String normalizedDocument = document == null ? "" : document.replaceAll("[^\\p{L}\\p{N}]", "");
        String phone = normalizePhone(request.telefono());
        String city = trim(request.ciudadResidencia());
        LocalDate birthDate = request.fechaNacimiento();
        if (document == null || document.length() > 30 || normalizedDocument.length() < 4) {
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
        return new ValidDetails(document, birthDate, phone, city);
    }

    private static void requirePatient(AuthAccount account) {
        if (!"ROL001".equals(account.roleCode())) {
            throw new AuthException(403, "GOOGLE_PATIENT_ONLY", "El acceso con Google esta disponible solo para pacientes.");
        }
    }

    private static String normalizePhone(String value) {
        String phone = trim(value);
        if (phone == null || phone.length() > 25 || !phone.matches("^\\+?[0-9 ()-]+$")) {
            return null;
        }
        boolean international = phone.startsWith("+");
        String digits = phone.replaceAll("[^0-9]", "");
        return digits.length() >= 8 && digits.length() <= 15
                ? (international ? "+" : "") + digits
                : null;
    }

    private static String trim(String value) {
        return value == null || value.isBlank() ? null : value.trim().replaceAll("\\s+", " ");
    }

    private static AuthException validation(String message) {
        return new AuthException(422, "VALIDATION_ERROR", message);
    }

    private static AuthException invalidContinuation() {
        return new AuthException(401, "GOOGLE_CONTINUATION_INVALID", "La continuacion con Google expiro o ya fue utilizada.");
    }

    private record ValidDetails(String document, LocalDate birthDate, String phone, String city) {
    }
}
