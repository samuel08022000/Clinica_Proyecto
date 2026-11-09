package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.security.SecureRandom;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.Base64;
import java.util.Optional;
import java.util.concurrent.ConcurrentHashMap;

/** Tickets opacos, de un solo uso, para continuar registro o vinculacion. */
@ApplicationScoped
public class GoogleContinuationStore {

    private final ConcurrentHashMap<String, Continuation> continuations = new ConcurrentHashMap<>();
    private final SecureRandom secureRandom = new SecureRandom();
    private final Duration ttl;
    private final Clock clock;

    @Inject
    public GoogleContinuationStore(
            @ConfigProperty(name = "google.oauth.continuation.ttl.minutes", defaultValue = "10") long ttlMinutes) {
        this(Duration.ofMinutes(ttlMinutes), Clock.systemUTC());
    }

    GoogleContinuationStore(Duration ttl, Clock clock) {
        this.ttl = ttl;
        this.clock = clock;
    }

    public IssuedContinuation issue(Purpose purpose, GoogleIdentity identity) {
        purgeExpired();
        byte[] random = new byte[32];
        secureRandom.nextBytes(random);
        String rawToken = Base64.getUrlEncoder().withoutPadding().encodeToString(random);
        Instant expiresAt = clock.instant().plus(ttl);
        continuations.put(
                RegistrationChallengeStore.hash(rawToken),
                new Continuation(purpose, identity, expiresAt));
        return new IssuedContinuation(rawToken, expiresAt);
    }

    public Optional<Continuation> claim(String rawToken, Purpose expectedPurpose) {
        if (rawToken == null || rawToken.isBlank()) {
            return Optional.empty();
        }
        String tokenHash = RegistrationChallengeStore.hash(rawToken);
        Continuation continuation = continuations.get(tokenHash);
        if (continuation == null || !continuation.expiresAt().isAfter(clock.instant())) {
            if (continuation != null) {
                continuations.remove(tokenHash, continuation);
            }
            return Optional.empty();
        }
        if (continuation.purpose() != expectedPurpose) {
            return Optional.empty();
        }
        return continuations.remove(tokenHash, continuation)
                ? Optional.of(continuation)
                : Optional.empty();
    }

    private void purgeExpired() {
        Instant now = clock.instant();
        continuations.entrySet().removeIf(entry -> !entry.getValue().expiresAt().isAfter(now));
    }

    public enum Purpose {
        REGISTER,
        LINK
    }

    public record IssuedContinuation(String rawToken, Instant expiresAt) {
    }

    public record Continuation(Purpose purpose, GoogleIdentity identity, Instant expiresAt) {
    }
}
