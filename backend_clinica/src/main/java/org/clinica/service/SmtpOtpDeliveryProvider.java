package org.clinica.service;

import io.quarkus.mailer.Mail;
import io.quarkus.mailer.Mailer;
import io.quarkus.runtime.LaunchMode;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.enterprise.inject.Typed;
import jakarta.inject.Inject;
import org.eclipse.microprofile.config.inject.ConfigProperty;

import java.util.Optional;

/** Entrega el mismo OTP generado por PostgreSQL mediante el Mailer SMTP de Quarkus. */
@ApplicationScoped
@Typed(SmtpOtpDeliveryProvider.class)
public class SmtpOtpDeliveryProvider implements OtpDeliveryProvider {

    private static final String SUBJECT = "Codigo de verificacion - Clinica";

    private final Mailer mailer;
    private final String username;
    private final String from;
    private final String password;
    private final boolean mock;

    @Inject
    public SmtpOtpDeliveryProvider(
            Mailer mailer,
            @ConfigProperty(name = "quarkus.mailer.username") Optional<String> username,
            @ConfigProperty(name = "quarkus.mailer.from") Optional<String> from,
            @ConfigProperty(name = "quarkus.mailer.password") Optional<String> password,
            @ConfigProperty(name = "quarkus.mailer.mock", defaultValue = "true") boolean mock) {
        this(mailer, username.orElse(""), from.orElse(""), password.orElse(""), mock);
    }

    SmtpOtpDeliveryProvider(Mailer mailer, String username, String from, String password, boolean mock) {
        this.mailer = mailer;
        this.username = trim(username);
        this.from = trim(from);
        this.password = trim(password);
        this.mock = mock;
    }

    @Override
    public DeliveryReceipt deliver(OtpMessage message) {
        if (username.isBlank() || from.isBlank() || password.isBlank()
                || (mock && LaunchMode.current() != LaunchMode.TEST)) {
            throw new AuthException(
                    503,
                    "OTP_SMTP_NOT_CONFIGURED",
                    "El proveedor SMTP no esta configurado para realizar envios reales.");
        }

        String body = String.format(
                "Hola,%n%nTu codigo de verificacion para completar el registro en la Clinica es: %s%n%n"
                        + "Este codigo es valido por 5 minutos. Si no solicitaste el registro, ignora este mensaje.",
                message.code());
        try {
            mailer.send(Mail.withText(message.destination(), SUBJECT, body));
        } catch (RuntimeException exception) {
            throw new AuthException(
                    503,
                    "OTP_DELIVERY_FAILED",
                    "No fue posible entregar el codigo de verificacion por correo.");
        }
        return new DeliveryReceipt("SMTP", true);
    }

    @Override
    public void discard(String verificationToken) {
        // Un correo ya entregado no puede retirarse. El OTP queda invalidado en PostgreSQL.
    }

    private static String trim(String value) {
        return value == null ? "" : value.trim();
    }
}
