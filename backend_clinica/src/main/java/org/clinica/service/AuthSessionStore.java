package org.clinica.service;

import io.quarkus.runtime.annotations.RegisterForReflection;
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

/**
 * Almacen de sesiones opacas. Solo conserva el SHA-256 del token y nunca el token entregado.
 * Es intencionalmente local al proceso: reiniciar Quarkus revoca todas las sesiones.
 */
@ApplicationScoped
public class AuthSessionStore {

    private final ConcurrentHashMap<String, StoredSession> sessions = new ConcurrentHashMap<>();
    private final SecureRandom secureRandom = new SecureRandom();
    private final Duration ttl;
    private final Clock clock;

    @Inject
    public AuthSessionStore(@ConfigProperty(name = "auth.session.ttl.minutes", defaultValue = "120") long ttlMinutes) {
        this(Duration.ofMinutes(ttlMinutes), Clock.systemUTC());
    }

    AuthSessionStore(Duration ttl, Clock clock) {
        this.ttl = ttl;
        this.clock = clock;
    }

    public IssuedSession issue(String codUsu) {
        purgeExpired();
        byte[] random = new byte[32];
        secureRandom.nextBytes(random);
        String rawToken = Base64.getUrlEncoder().withoutPadding().encodeToString(random);
        Instant expiresAt = clock.instant().plus(ttl);
        sessions.put(hash(rawToken), new StoredSession(codUsu, expiresAt));
        return new IssuedSession(rawToken, expiresAt);
    }

    public Optional<StoredSession> find(String rawToken) {
        if (rawToken == null || rawToken.isBlank()) {
            return Optional.empty();
        }

        String tokenHash = hash(rawToken);
        StoredSession session = sessions.get(tokenHash);
        if (session == null) {
            return Optional.empty();
        }
        if (!session.expiresAt().isAfter(clock.instant())) {
            sessions.remove(tokenHash, session);
            return Optional.empty();
        }
        return Optional.of(session);
    }

    public boolean revoke(String rawToken) {
        if (rawToken == null || rawToken.isBlank()) {
            return false;
        }
        return sessions.remove(hash(rawToken)) != null;
    }

    public void revokeAllForUser(String codUsu) {
        sessions.entrySet().removeIf(entry -> entry.getValue().codUsu().equals(codUsu));
    }

    private void purgeExpired() {
        Instant now = clock.instant();
        sessions.entrySet().removeIf(entry -> !entry.getValue().expiresAt().isAfter(now));
    }

    private static String hash(String token) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            return HexFormat.of().formatHex(digest.digest(token.getBytes(StandardCharsets.UTF_8)));
        } catch (NoSuchAlgorithmException exception) {
            throw new IllegalStateException("SHA-256 no esta disponible", exception);
        }
    }

    @RegisterForReflection
    public record IssuedSession(String rawToken, Instant expiresAt) {
    }

    @RegisterForReflection
    public record StoredSession(String codUsu, Instant expiresAt) {
    }
}
