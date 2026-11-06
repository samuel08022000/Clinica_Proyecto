package org.clinica.service;

import java.time.Instant;

/** Puerto de entrega OTP. El codigo llega generado; ningun proveedor debe regenerarlo. */
public interface OtpDeliveryProvider {

    DeliveryReceipt deliver(OtpMessage message);

    void discard(String verificationToken);

    record OtpMessage(
            String verificationToken,
            String destination,
            String code,
            Instant expiresAt) {
    }

    record DeliveryReceipt(String mode, boolean deliveryConfirmed) {
    }
}
