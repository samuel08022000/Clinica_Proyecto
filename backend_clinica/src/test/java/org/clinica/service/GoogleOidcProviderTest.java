package org.clinica.service;

import com.google.api.client.googleapis.auth.oauth2.GoogleIdToken;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class GoogleOidcProviderTest {

    @Test
    void claimsRequireIssuerAudienceExpirationNonceAndStableSubject() {
        GoogleIdToken.Payload payload = validPayload();

        var identity = GoogleOidcProvider.validateClaims(
                payload, "client-id", "expected-nonce", Instant.parse("2026-11-06T12:00:00Z"));

        assertEquals("stable-google-sub", identity.subject());
        assertEquals("patient@example.test", identity.email());
        assertTrue(identity.emailVerified());
    }

    @Test
    void rejectsWrongAudienceIssuerExpiredTokenAndNonceReplay() {
        GoogleIdToken.Payload wrongAudience = validPayload().setAudience(List.of("attacker-client"));
        assertInvalid(wrongAudience, "expected-nonce");

        GoogleIdToken.Payload wrongIssuer = validPayload().setIssuer("https://attacker.example");
        assertInvalid(wrongIssuer, "expected-nonce");

        GoogleIdToken.Payload expired = validPayload().setExpirationTimeSeconds(1L);
        assertInvalid(expired, "expected-nonce");

        assertInvalid(validPayload(), "different-nonce");

        GoogleIdToken.Payload missingAuthorizedParty = validPayload()
                .setAudience(List.of("client-id", "another-client"));
        assertInvalid(missingAuthorizedParty, "expected-nonce");
    }

    private static void assertInvalid(GoogleIdToken.Payload payload, String nonce) {
        AuthException error = assertThrows(AuthException.class, () -> GoogleOidcProvider.validateClaims(
                payload, "client-id", nonce, Instant.parse("2026-11-06T12:00:00Z")));
        assertEquals("GOOGLE_ID_TOKEN_INVALID", error.code());
    }

    private static GoogleIdToken.Payload validPayload() {
        return new GoogleIdToken.Payload()
                .setIssuer("https://accounts.google.com")
                .setAudience(List.of("client-id"))
                .setExpirationTimeSeconds(Instant.parse("2026-11-06T12:05:00Z").getEpochSecond())
                .setSubject("stable-google-sub")
                .setEmail("Patient@Example.Test")
                .setEmailVerified(true)
                .set("nonce", "expected-nonce")
                .set("name", "Patient Test");
    }
}
