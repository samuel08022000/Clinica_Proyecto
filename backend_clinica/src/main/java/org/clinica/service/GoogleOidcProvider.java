package org.clinica.service;

import com.google.api.client.auth.oauth2.TokenResponseException;
import com.google.api.client.googleapis.auth.oauth2.GoogleAuthorizationCodeTokenRequest;
import com.google.api.client.googleapis.auth.oauth2.GoogleIdToken;
import com.google.api.client.googleapis.auth.oauth2.GoogleIdTokenVerifier;
import com.google.api.client.googleapis.auth.oauth2.GoogleTokenResponse;
import com.google.api.client.http.HttpTransport;
import com.google.api.client.http.javanet.NetHttpTransport;
import com.google.api.client.json.JsonFactory;
import com.google.api.client.json.gson.GsonFactory;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import jakarta.ws.rs.core.UriBuilder;
import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.time.Clock;
import java.time.Instant;
import java.util.Collection;
import java.util.List;
import java.util.Locale;
import java.util.Optional;
import java.util.regex.Pattern;

/** Flujo Authorization Code + PKCE y validacion OIDC mediante la biblioteca oficial de Google. */
@ApplicationScoped
public class GoogleOidcProvider implements GoogleIdentityProvider {

    private static final Pattern OPAQUE_VALUE = Pattern.compile("^[A-Za-z0-9_-]{32,180}$");
    private static final Pattern CODE_VERIFIER = Pattern.compile("^[A-Za-z0-9._~-]{43,128}$");
    private static final List<String> GOOGLE_ISSUERS = List.of(
            "https://accounts.google.com",
            "accounts.google.com");

    private final boolean enabled;
    private final String clientId;
    private final String clientSecret;
    private final String configuredRedirectUri;
    private final String authorizationEndpoint;
    private final HttpTransport transport;
    private final JsonFactory jsonFactory;
    private final Clock clock;

    @Inject
    public GoogleOidcProvider(
            @ConfigProperty(name = "google.oauth.enabled", defaultValue = "false") boolean enabled,
            @ConfigProperty(name = "google.oauth.client-id") Optional<String> clientId,
            @ConfigProperty(name = "google.oauth.client-secret") Optional<String> clientSecret,
            @ConfigProperty(name = "google.oauth.redirect-uri") String redirectUri,
            @ConfigProperty(name = "google.oauth.authorization-endpoint") String authorizationEndpoint) {
        this(enabled, clientId.orElse(""), clientSecret.orElse(""), redirectUri,
                authorizationEndpoint, new NetHttpTransport(), GsonFactory.getDefaultInstance(), Clock.systemUTC());
    }

    GoogleOidcProvider(
            boolean enabled,
            String clientId,
            String clientSecret,
            String redirectUri,
            String authorizationEndpoint,
            HttpTransport transport,
            JsonFactory jsonFactory,
            Clock clock) {
        this.enabled = enabled;
        this.clientId = trim(clientId);
        this.clientSecret = trim(clientSecret);
        this.configuredRedirectUri = trim(redirectUri);
        this.authorizationEndpoint = trim(authorizationEndpoint);
        this.transport = transport;
        this.jsonFactory = jsonFactory;
        this.clock = clock;
    }

    @Override
    public String authorizationUrl(AuthorizationRequest request) {
        requireConfigured();
        if (request == null
                || !OPAQUE_VALUE.matcher(nullToEmpty(request.state())).matches()
                || !OPAQUE_VALUE.matcher(nullToEmpty(request.nonce())).matches()
                || !OPAQUE_VALUE.matcher(nullToEmpty(request.codeChallenge())).matches()) {
            throw validation("Parametros OAuth invalidos.");
        }
        requireExactRedirect(request.redirectUri());

        return UriBuilder.fromUri(authorizationEndpoint)
                .queryParam("client_id", clientId)
                .queryParam("redirect_uri", configuredRedirectUri)
                .queryParam("response_type", "code")
                .queryParam("scope", "openid email profile")
                .queryParam("state", request.state())
                .queryParam("nonce", request.nonce())
                .queryParam("code_challenge", request.codeChallenge())
                .queryParam("code_challenge_method", "S256")
                .queryParam("prompt", "select_account")
                .build()
                .toASCIIString();
    }

    @Override
    public GoogleIdentity exchange(ExchangeRequest request) {
        requireConfigured();
        if (request == null
                || request.code() == null || request.code().isBlank()
                || !CODE_VERIFIER.matcher(nullToEmpty(request.codeVerifier())).matches()
                || !OPAQUE_VALUE.matcher(nullToEmpty(request.expectedNonce())).matches()) {
            throw validation("La respuesta OAuth esta incompleta.");
        }
        requireExactRedirect(request.redirectUri());

        try {
            GoogleTokenResponse tokenResponse = new GoogleAuthorizationCodeTokenRequest(
                    transport,
                    jsonFactory,
                    clientId,
                    clientSecret,
                    request.code(),
                    configuredRedirectUri)
                    .set("code_verifier", request.codeVerifier())
                    .execute();

            String rawIdToken = tokenResponse.getIdToken();
            if (rawIdToken == null || rawIdToken.isBlank()) {
                throw invalidToken();
            }

            GoogleIdTokenVerifier verifier = new GoogleIdTokenVerifier.Builder(transport, jsonFactory)
                    .setAudience(List.of(clientId))
                    .setIssuers(GOOGLE_ISSUERS)
                    .build();
            GoogleIdToken idToken = verifier.verify(rawIdToken);
            if (idToken == null) {
                throw invalidToken();
            }
            return validateClaims(idToken.getPayload(), clientId, request.expectedNonce(), clock.instant());
        } catch (TokenResponseException exception) {
            throw new AuthException(401, "GOOGLE_AUTHENTICATION_FAILED",
                    "Google rechazo o expiro la autorizacion.");
        } catch (GeneralSecurityException exception) {
            throw invalidToken();
        } catch (IOException exception) {
            throw new AuthException(503, "GOOGLE_PROVIDER_UNAVAILABLE",
                    "No fue posible validar la identidad con Google.");
        }
    }

    static GoogleIdentity validateClaims(
            GoogleIdToken.Payload payload,
            String expectedAudience,
            String expectedNonce,
            Instant now) {
        if (payload == null
                || !GOOGLE_ISSUERS.contains(payload.getIssuer())
                || !audienceContains(payload.getAudience(), expectedAudience)
                || !authorizedPartyMatches(payload.getAudience(), payload.get("azp"), expectedAudience)
                || payload.getExpirationTimeSeconds() == null
                || !Instant.ofEpochSecond(payload.getExpirationTimeSeconds()).isAfter(now)
                || !secureEquals(expectedNonce, (String) payload.get("nonce"))) {
            throw invalidToken();
        }

        String subject = trim(payload.getSubject());
        String email = normalizeEmail(payload.getEmail());
        if (subject.isBlank() || email == null) {
            throw invalidToken();
        }
        String name = trim((String) payload.get("name"));
        if (name.length() < 3) {
            name = email.substring(0, email.indexOf('@')).replace('.', ' ').trim();
        }
        if (name.length() < 3) {
            name = "Paciente Google";
        }
        if (name.length() > 150) {
            name = name.substring(0, 150).trim();
        }
        return new GoogleIdentity(subject, email, name, Boolean.TRUE.equals(payload.getEmailVerified()));
    }

    private void requireConfigured() {
        if (!enabled || clientId.isBlank() || clientSecret.isBlank()
                || configuredRedirectUri.isBlank() || authorizationEndpoint.isBlank()) {
            throw new AuthException(503, "GOOGLE_OAUTH_NOT_CONFIGURED",
                    "El acceso con Google no esta configurado.");
        }
    }

    private void requireExactRedirect(String requestedRedirectUri) {
        if (!secureEquals(configuredRedirectUri, trim(requestedRedirectUri))) {
            throw validation("La URL de retorno OAuth no esta autorizada.");
        }
    }

    private static boolean audienceContains(Object audience, String expected) {
        if (audience instanceof String value) {
            return secureEquals(value, expected);
        }
        if (audience instanceof Collection<?> values) {
            return values.stream().anyMatch(value -> value instanceof String text && secureEquals(text, expected));
        }
        return false;
    }

    private static boolean authorizedPartyMatches(Object audience, Object authorizedParty, String expected) {
        if (!(audience instanceof Collection<?> values) || values.size() <= 1) {
            return true;
        }
        return authorizedParty instanceof String value && secureEquals(value, expected);
    }

    private static String normalizeEmail(String email) {
        if (email == null || email.isBlank()) {
            return null;
        }
        String normalized = email.trim().toLowerCase(Locale.ROOT);
        return normalized.length() <= 254 && normalized.matches("^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$")
                ? normalized
                : null;
    }

    private static boolean secureEquals(String expected, String supplied) {
        if (expected == null || supplied == null) {
            return false;
        }
        return MessageDigest.isEqual(
                expected.getBytes(StandardCharsets.UTF_8),
                supplied.getBytes(StandardCharsets.UTF_8));
    }

    private static String nullToEmpty(String value) {
        return value == null ? "" : value;
    }

    private static String trim(String value) {
        return value == null ? "" : value.trim();
    }

    private static AuthException validation(String message) {
        return new AuthException(422, "VALIDATION_ERROR", message);
    }

    private static AuthException invalidToken() {
        return new AuthException(401, "GOOGLE_ID_TOKEN_INVALID",
                "No fue posible validar la identidad de Google.");
    }
}
