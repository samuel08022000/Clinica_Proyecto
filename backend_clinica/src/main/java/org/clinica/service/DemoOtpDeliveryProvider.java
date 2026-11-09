package org.clinica.service;

import io.quarkus.runtime.LaunchMode;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.enterprise.inject.Typed;
import jakarta.inject.Inject;
import org.clinica.dto.AuthDtos.DemoOtpData;
import org.clinica.dto.AuthDtos.DemoPasswordResetData;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Locale;
import java.util.Optional;
import java.util.concurrent.ConcurrentHashMap;

/** Entrega DEMO: conserva el OTP solo en memoria y nunca simula un correo real. */
@ApplicationScoped
@Typed(DemoOtpDeliveryProvider.class)
public class DemoOtpDeliveryProvider implements OtpDeliveryProvider {

    private final ConcurrentHashMap<String, DemoCode> demoCodes = new ConcurrentHashMap<>();
    private final ConcurrentHashMap<String, String> destinationIndex = new ConcurrentHashMap<>();
    private final ConcurrentHashMap<String, DemoReset> passwordResets = new ConcurrentHashMap<>();
    private final String demoAccessKey;
    private final boolean demoRuntimeAllowed;

    @Inject
    public DemoOtpDeliveryProvider(
            @ConfigProperty(name = "otp.demo.access-key") Optional<String> demoAccessKey) {
        this(demoAccessKey.orElse(""), LaunchMode.current() != LaunchMode.NORMAL);
    }

    DemoOtpDeliveryProvider(String demoAccessKey, boolean demoRuntimeAllowed) {
        this.demoAccessKey = demoAccessKey == null ? "" : demoAccessKey;
        this.demoRuntimeAllowed = demoRuntimeAllowed;
    }

    @Override
    public DeliveryReceipt deliver(OtpMessage message) {
        requireAvailable();
        purgeExpired();
        String tokenHash = RegistrationChallengeStore.hash(message.verificationToken());
        String destinationHash = RegistrationChallengeStore.hash(normalizeEmail(message.destination()));
        DemoCode previous = demoCodes.put(
                tokenHash,
                new DemoCode(message.code(), message.expiresAt(), destinationHash));
        if (previous != null) {
            destinationIndex.remove(previous.destinationHash(), tokenHash);
        }
        destinationIndex.put(destinationHash, tokenHash);
        return new DeliveryReceipt("DEMO", false);
    }

    @Override
    public DeliveryReceipt deliverPasswordReset(PasswordResetMessage message) {
        requireAvailable();
        purgeExpired();
        String destinationHash = RegistrationChallengeStore.hash(normalizeEmail(message.destination()));
        passwordResets.put(destinationHash, new DemoReset(message.resetUrl(), message.expiresAt()));
        return new DeliveryReceipt("DEMO", false);
    }

    public void requireAvailable() {
        if (!demoRuntimeAllowed || demoAccessKey.length() < 24) {
            throw new AuthException(
                    503,
                    "OTP_DEMO_NOT_ALLOWED",
                    "El proveedor de correo de desarrollo no esta habilitado de forma segura.");
        }
    }

    @Override
    public void discard(String verificationToken) {
        if (verificationToken == null || verificationToken.isBlank()) {
            return;
        }
        String tokenHash = RegistrationChallengeStore.hash(verificationToken);
        DemoCode removed = demoCodes.remove(tokenHash);
        if (removed != null) {
            destinationIndex.remove(removed.destinationHash(), tokenHash);
        }
    }

    public Optional<DemoOtpData> readDemoCode(
            String verificationToken,
            String suppliedAccessKey,
            String remoteAddress) {
        if (verificationToken == null
                || verificationToken.isBlank()
                || !isDemoAccessAllowed(suppliedAccessKey, remoteAddress)) {
            return Optional.empty();
        }
        return readByTokenHash(RegistrationChallengeStore.hash(verificationToken));
    }

    public Optional<DemoOtpData> readDemoCodeForDestination(
            String destination,
            String suppliedAccessKey,
            String remoteAddress) {
        if (destination == null
                || destination.isBlank()
                || !isDemoAccessAllowed(suppliedAccessKey, remoteAddress)) {
            return Optional.empty();
        }
        String destinationHash = RegistrationChallengeStore.hash(normalizeEmail(destination));
        String tokenHash = destinationIndex.get(destinationHash);
        return tokenHash == null ? Optional.empty() : readByTokenHash(tokenHash);
    }

    public Optional<DemoPasswordResetData> readPasswordReset(
            String destination,
            String suppliedAccessKey,
            String remoteAddress) {
        if (destination == null || destination.isBlank()
                || !isDemoAccessAllowed(suppliedAccessKey, remoteAddress)) {
            return Optional.empty();
        }
        String destinationHash = RegistrationChallengeStore.hash(normalizeEmail(destination));
        DemoReset reset = passwordResets.get(destinationHash);
        if (reset == null || !reset.expiresAt().isAfter(Instant.now())) {
            if (reset != null) {
                passwordResets.remove(destinationHash, reset);
            }
            return Optional.empty();
        }
        return Optional.of(new DemoPasswordResetData(
                reset.resetUrl(),
                OffsetDateTime.ofInstant(reset.expiresAt(), ZoneOffset.UTC)));
    }

    private Optional<DemoOtpData> readByTokenHash(String tokenHash) {
        DemoCode demoCode = demoCodes.get(tokenHash);
        if (demoCode == null || !demoCode.expiresAt().isAfter(Instant.now())) {
            if (demoCode != null) {
                demoCodes.remove(tokenHash, demoCode);
                destinationIndex.remove(demoCode.destinationHash(), tokenHash);
            }
            return Optional.empty();
        }
        return Optional.of(new DemoOtpData(
                demoCode.code(),
                OffsetDateTime.ofInstant(demoCode.expiresAt(), ZoneOffset.UTC)));
    }

    private boolean isDemoAccessAllowed(String suppliedAccessKey, String remoteAddress) {
        return demoRuntimeAllowed
                && isLoopback(remoteAddress)
                && demoAccessKey.length() >= 24
                && secureEquals(demoAccessKey, suppliedAccessKey);
    }

    private void purgeExpired() {
        Instant now = Instant.now();
        demoCodes.forEach((tokenHash, code) -> {
            if (!code.expiresAt().isAfter(now) && demoCodes.remove(tokenHash, code)) {
                destinationIndex.remove(code.destinationHash(), tokenHash);
            }
        });
        passwordResets.entrySet().removeIf(entry -> !entry.getValue().expiresAt().isAfter(now));
    }

    private static boolean isLoopback(String address) {
        return "127.0.0.1".equals(address)
                || "0:0:0:0:0:0:0:1".equals(address)
                || "::1".equals(address);
    }

    private static String normalizeEmail(String destination) {
        return destination == null ? "" : destination.trim().toLowerCase(Locale.ROOT);
    }

    private static boolean secureEquals(String expected, String supplied) {
        if (supplied == null) {
            return false;
        }
        return MessageDigest.isEqual(
                expected.getBytes(StandardCharsets.UTF_8),
                supplied.getBytes(StandardCharsets.UTF_8));
    }

    private record DemoCode(String code, Instant expiresAt, String destinationHash) {
    }

    private record DemoReset(String resetUrl, Instant expiresAt) {
    }
}
