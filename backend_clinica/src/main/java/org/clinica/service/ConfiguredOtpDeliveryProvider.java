package org.clinica.service;

import io.quarkus.runtime.LaunchMode;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.clinica.dto.AuthDtos.DemoOtpData;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.Optional;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Proveedor configurable. DEMO guarda el codigo solo en memoria y exige perfil local,
 * acceso desde loopback y una clave externa para consultarlo.
 */
@ApplicationScoped
public class ConfiguredOtpDeliveryProvider implements OtpDeliveryProvider {

    private final ConcurrentHashMap<String, DemoCode> demoCodes = new ConcurrentHashMap<>();
    private final ConcurrentHashMap<String, String> destinationIndex = new ConcurrentHashMap<>();
    private final String provider;
    private final String demoAccessKey;

    @Inject
    public ConfiguredOtpDeliveryProvider(
            @ConfigProperty(name = "otp.delivery.provider", defaultValue = "disabled") String provider,
            @ConfigProperty(name = "otp.demo.access-key", defaultValue = "") String demoAccessKey) {
        this.provider = provider == null ? "disabled" : provider.trim().toLowerCase();
        this.demoAccessKey = demoAccessKey == null ? "" : demoAccessKey;
    }

    @Override
    public void deliver(OtpMessage message) {
        if (!"demo".equals(provider)) {
            throw unavailable();
        }
        if (LaunchMode.current() == LaunchMode.NORMAL || demoAccessKey.length() < 24) {
            throw new AuthException(
                    503,
                    "OTP_DEMO_NOT_ALLOWED",
                    "El proveedor OTP de desarrollo no esta habilitado de forma segura.");
        }
        purgeExpired();
        String tokenHash = RegistrationChallengeStore.hash(message.verificationToken());
        String destinationHash = RegistrationChallengeStore.hash(message.destination());
        demoCodes.put(tokenHash, new DemoCode(message.code(), message.expiresAt(), destinationHash));
        destinationIndex.put(destinationHash, tokenHash);
    }

    @Override
    public void discard(String verificationToken) {
        if (verificationToken != null && !verificationToken.isBlank()) {
            String tokenHash = RegistrationChallengeStore.hash(verificationToken);
            DemoCode removed = demoCodes.remove(tokenHash);
            if (removed != null) {
                destinationIndex.remove(removed.destinationHash(), tokenHash);
            }
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
        String tokenHash = destinationIndex.get(RegistrationChallengeStore.hash(normalizeDestination(destination)));
        return tokenHash == null ? Optional.empty() : readByTokenHash(tokenHash);
    }

    private Optional<DemoOtpData> readByTokenHash(String tokenHash) {
        DemoCode demoCode = demoCodes.get(tokenHash);
        if (demoCode == null || !demoCode.expiresAt().isAfter(java.time.Instant.now())) {
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
        return "demo".equals(provider)
                && LaunchMode.current() != LaunchMode.NORMAL
                && isLoopback(remoteAddress)
                && demoAccessKey.length() >= 24
                && secureEquals(demoAccessKey, suppliedAccessKey);
    }

    private void purgeExpired() {
        java.time.Instant now = java.time.Instant.now();
        demoCodes.forEach((tokenHash, code) -> {
            if (!code.expiresAt().isAfter(now) && demoCodes.remove(tokenHash, code)) {
                destinationIndex.remove(code.destinationHash(), tokenHash);
            }
        });
    }

    private static boolean isLoopback(String address) {
        return "127.0.0.1".equals(address)
                || "0:0:0:0:0:0:0:1".equals(address)
                || "::1".equals(address);
    }

    private static String normalizeDestination(String destination) {
        String trimmed = destination.trim();
        boolean international = trimmed.startsWith("+");
        String digits = trimmed.replaceAll("[^0-9]", "");
        return (international ? "+" : "") + digits;
    }

    private static boolean secureEquals(String expected, String supplied) {
        if (supplied == null) {
            return false;
        }
        return MessageDigest.isEqual(
                expected.getBytes(StandardCharsets.UTF_8),
                supplied.getBytes(StandardCharsets.UTF_8));
    }

    private static AuthException unavailable() {
        return new AuthException(
                503,
                "OTP_PROVIDER_UNAVAILABLE",
                "La entrega del codigo de verificacion no esta disponible.");
    }

    private record DemoCode(String code, java.time.Instant expiresAt, String destinationHash) {
    }
}
