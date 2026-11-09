package org.clinica.service;

import java.time.Instant;

/** Puerto de entrega OTP. El codigo llega generado; ningun proveedor debe regenerarlo. */
public interface OtpDeliveryProvider {

    DeliveryReceipt deliver(OtpMessage message);

    DeliveryReceipt deliverPasswordReset(PasswordResetMessage message);

    void discard(String verificationToken);

    record OtpMessage(
            String verificationToken,
            String destination,
            String code,
            Instant expiresAt) {
    }

    record PasswordResetMessage(
            String destination,
            String resetUrl,
            Instant expiresAt) {
    }

    record DeliveryReceipt(String mode, boolean deliveryConfirmed) {
    }
}
