package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.Base64;
import java.util.HexFormat;
import java.util.Optional;
import java.util.concurrent.ConcurrentHashMap;

/** Conserva solamente el SHA-256 de los tokens opacos usados durante la verificacion. */
@ApplicationScoped
public class RegistrationChallengeStore {

    private final ConcurrentHashMap<String, Challenge> challenges = new ConcurrentHashMap<>();
    private final SecureRandom secureRandom = new SecureRandom();
    private final Duration ttl;
    private final Clock clock;

    @Inject
    public RegistrationChallengeStore(
            @ConfigProperty(name = "auth.registration.challenge.ttl.minutes", defaultValue = "30") long ttlMinutes) {
        this(Duration.ofMinutes(ttlMinutes), Clock.systemUTC());
    }

    RegistrationChallengeStore(Duration ttl, Clock clock) {
        this.ttl = ttl;
        this.clock = clock;
    }

    public IssuedChallenge issue(String codUsu, String maskedDestination) {
        purgeExpired();
        revokeAllForUser(codUsu);
        byte[] random = new byte[32];
        secureRandom.nextBytes(random);
        String rawToken = Base64.getUrlEncoder().withoutPadding().encodeToString(random);
        Instant expiresAt = clock.instant().plus(ttl);
        challenges.put(hash(rawToken), new Challenge(codUsu, maskedDestination, expiresAt));
        return new IssuedChallenge(rawToken, expiresAt);
    }

    public Optional<Challenge> find(String rawToken) {
        if (rawToken == null || rawToken.isBlank()) {
            return Optional.empty();
        }
        String tokenHash = hash(rawToken);
        Challenge challenge = challenges.get(tokenHash);
        if (challenge == null) {
            return Optional.empty();
        }
        if (!challenge.expiresAt().isAfter(clock.instant())) {
            challenges.remove(tokenHash, challenge);
            return Optional.empty();
        }
        return Optional.of(challenge);
    }

    public boolean revoke(String rawToken) {
        return rawToken != null && !rawToken.isBlank() && challenges.remove(hash(rawToken)) != null;
    }

    public void revokeAllForUser(String codUsu) {
        challenges.entrySet().removeIf(entry -> entry.getValue().codUsu().equals(codUsu));
    }

    private void purgeExpired() {
        Instant now = clock.instant();
        challenges.entrySet().removeIf(entry -> !entry.getValue().expiresAt().isAfter(now));
    }

    static String hash(String token) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            return HexFormat.of().formatHex(digest.digest(token.getBytes(StandardCharsets.UTF_8)));
        } catch (NoSuchAlgorithmException exception) {
            throw new IllegalStateException("SHA-256 no esta disponible", exception);
        }
    }

    public record IssuedChallenge(String rawToken, Instant expiresAt) {
    }

    public record Challenge(String codUsu, String maskedDestination, Instant expiresAt) {
    }
}
