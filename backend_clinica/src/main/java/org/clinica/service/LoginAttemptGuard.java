package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.Locale;
import java.util.concurrent.ConcurrentHashMap;

/** Limita intentos por combinacion de identificador normalizado y direccion remota. */
@ApplicationScoped
public class LoginAttemptGuard {

    private final ConcurrentHashMap<String, AttemptState> attempts = new ConcurrentHashMap<>();
    private final int maxAttempts;
    private final Duration window;
    private final Duration lockDuration;
    private final Clock clock;

    @Inject
    public LoginAttemptGuard(
            @ConfigProperty(name = "auth.login.max-attempts", defaultValue = "5") int maxAttempts,
            @ConfigProperty(name = "auth.login.window.minutes", defaultValue = "15") long windowMinutes,
            @ConfigProperty(name = "auth.login.lock.minutes", defaultValue = "15") long lockMinutes) {
        this(maxAttempts, Duration.ofMinutes(windowMinutes), Duration.ofMinutes(lockMinutes), Clock.systemUTC());
    }

    LoginAttemptGuard(int maxAttempts, Duration window, Duration lockDuration, Clock clock) {
        this.maxAttempts = maxAttempts;
        this.window = window;
        this.lockDuration = lockDuration;
        this.clock = clock;
    }

    public void checkAllowed(String identifier, String remoteAddress) {
        String key = key(identifier, remoteAddress);
        AttemptState state = attempts.get(key);
        if (state == null) {
            return;
        }

        Instant now = clock.instant();
        if (state.lockedUntil() != null && state.lockedUntil().isAfter(now)) {
            throw tooManyAttempts(state.lockedUntil(), now);
        }
        if (!state.windowStartedAt().plus(window).isAfter(now)) {
            attempts.remove(key, state);
        }
    }

    public void recordFailure(String identifier, String remoteAddress) {
        String key = key(identifier, remoteAddress);
        Instant now = clock.instant();
        AttemptState state = attempts.compute(key, (ignored, current) -> {
            if (current == null || !current.windowStartedAt().plus(window).isAfter(now)) {
                return new AttemptState(1, now, null);
            }
            int failures = current.failures() + 1;
            Instant lockedUntil = failures >= maxAttempts ? now.plus(lockDuration) : current.lockedUntil();
            return new AttemptState(failures, current.windowStartedAt(), lockedUntil);
        });

        if (state.lockedUntil() != null && state.lockedUntil().isAfter(now)) {
            throw tooManyAttempts(state.lockedUntil(), now);
        }
    }

    public void recordSuccess(String identifier, String remoteAddress) {
        attempts.remove(key(identifier, remoteAddress));
    }

    private static String key(String identifier, String remoteAddress) {
        String normalizedIdentifier = identifier == null ? "" : identifier.trim().toLowerCase(Locale.ROOT);
        String normalizedRemote = remoteAddress == null || remoteAddress.isBlank() ? "unknown" : remoteAddress.trim();
        return normalizedIdentifier + "|" + normalizedRemote;
    }

    private static AuthException tooManyAttempts(Instant lockedUntil, Instant now) {
        long retryAfter = Math.max(1, Duration.between(now, lockedUntil).toSeconds());
        return new AuthException(
                429,
                "TOO_MANY_ATTEMPTS",
                "Demasiados intentos. Espera antes de volver a intentarlo.",
                retryAfter);
    }

    private record AttemptState(int failures, Instant windowStartedAt, Instant lockedUntil) {
    }
}
