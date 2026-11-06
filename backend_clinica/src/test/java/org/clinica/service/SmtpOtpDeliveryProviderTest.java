package org.clinica.service;

import io.quarkus.mailer.Mail;
import io.quarkus.mailer.Mailer;
import org.junit.jupiter.api.Test;

import java.lang.reflect.Proxy;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

class SmtpOtpDeliveryProviderTest {

    @Test
    void configuredProviderSendsTheExistingOtpThroughASimulatedMailer() {
        List<Mail> mailbox = new ArrayList<>();
        Mailer simulatedMailer = simulatedMailer(mailbox, false);
        SmtpOtpDeliveryProvider provider = new SmtpOtpDeliveryProvider(
                simulatedMailer,
                "sender@gmail.com",
                "sender@gmail.com",
                "application-password",
                false);

        var receipt = provider.deliver(message("654321"));

        assertEquals("SMTP", receipt.mode());
        assertTrue(receipt.deliveryConfirmed());
        assertEquals(1, mailbox.size());
        assertEquals(List.of("patient@example.test"), mailbox.getFirst().getTo());
        assertEquals("Codigo de verificacion - Clinica", mailbox.getFirst().getSubject());
        assertTrue(mailbox.getFirst().getText().contains("654321"));
    }

    @Test
    void missingCredentialsNeverReportsDelivery() {
        SmtpOtpDeliveryProvider provider = new SmtpOtpDeliveryProvider(
                simulatedMailer(new ArrayList<>(), false),
                "",
                "",
                "",
                false);

        AuthException error = assertThrows(AuthException.class, () -> provider.deliver(message("123456")));

        assertEquals(503, error.status());
        assertEquals("OTP_SMTP_NOT_CONFIGURED", error.code());
    }

    @Test
    void transportFailureIsReturnedAsAControlledError() {
        SmtpOtpDeliveryProvider provider = new SmtpOtpDeliveryProvider(
                simulatedMailer(new ArrayList<>(), true),
                "sender@gmail.com",
                "sender@gmail.com",
                "application-password",
                false);

        AuthException error = assertThrows(AuthException.class, () -> provider.deliver(message("123456")));

        assertEquals("OTP_DELIVERY_FAILED", error.code());
        assertFalse(error.getMessage().contains("123456"));
    }

    private static OtpDeliveryProvider.OtpMessage message(String code) {
        return new OtpDeliveryProvider.OtpMessage(
                "opaque-verification-token",
                "patient@example.test",
                code,
                Instant.parse("2026-11-06T00:05:00Z"));
    }

    private static Mailer simulatedMailer(List<Mail> mailbox, boolean fail) {
        return (Mailer) Proxy.newProxyInstance(
                Mailer.class.getClassLoader(),
                new Class<?>[]{Mailer.class},
                (proxy, method, arguments) -> {
                    if ("send".equals(method.getName())) {
                        if (fail) {
                            throw new IllegalStateException("simulated transport failure");
                        }
                        if (arguments != null && arguments.length > 0 && arguments[0] instanceof Mail[] mails) {
                            mailbox.addAll(Arrays.asList(mails));
                        }
                        return null;
                    }
                    if ("toString".equals(method.getName())) {
                        return "SimulatedMailer";
                    }
                    return null;
                });
    }
}
