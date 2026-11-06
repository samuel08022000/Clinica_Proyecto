package org.clinica.service;

import jakarta.enterprise.context.ApplicationScoped;
import jakarta.inject.Inject;
import org.clinica.dto.AuthDtos.DemoOtpData;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.util.Locale;
import java.util.Optional;

/** Selecciona explicitamente entre entrega DEMO, SMTP o deshabilitada. */
@ApplicationScoped
public class ConfiguredOtpDeliveryProvider implements OtpDeliveryProvider {

    private final String provider;
    private final DemoOtpDeliveryProvider demoProvider;
    private final SmtpOtpDeliveryProvider smtpProvider;

    @Inject
    public ConfiguredOtpDeliveryProvider(
            @ConfigProperty(name = "otp.delivery.provider", defaultValue = "disabled") String provider,
            DemoOtpDeliveryProvider demoProvider,
            SmtpOtpDeliveryProvider smtpProvider) {
        this.provider = provider == null ? "disabled" : provider.trim().toLowerCase(Locale.ROOT);
        this.demoProvider = demoProvider;
        this.smtpProvider = smtpProvider;
    }

    @Override
    public DeliveryReceipt deliver(OtpMessage message) {
        return switch (provider) {
            case "demo" -> demoProvider.deliver(message);
            case "smtp" -> smtpProvider.deliver(message);
            default -> throw unavailable();
        };
    }

    @Override
    public void discard(String verificationToken) {
        if ("demo".equals(provider)) {
            demoProvider.discard(verificationToken);
        }
    }

    public Optional<DemoOtpData> readDemoCode(
            String verificationToken,
            String suppliedAccessKey,
            String remoteAddress) {
        return "demo".equals(provider)
                ? demoProvider.readDemoCode(verificationToken, suppliedAccessKey, remoteAddress)
                : Optional.empty();
    }

    public Optional<DemoOtpData> readDemoCodeForDestination(
            String destination,
            String suppliedAccessKey,
            String remoteAddress) {
        return "demo".equals(provider)
                ? demoProvider.readDemoCodeForDestination(destination, suppliedAccessKey, remoteAddress)
                : Optional.empty();
    }

    private static AuthException unavailable() {
        return new AuthException(
                503,
                "OTP_PROVIDER_UNAVAILABLE",
                "La entrega del codigo de verificacion no esta disponible.");
    }
}
