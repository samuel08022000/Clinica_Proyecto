package org.clinica.service;

import java.time.Instant;

/** Puerto de entrega OTP. Una integracion SMS real debe implementar este contrato. */
public interface OtpDeliveryProvider {

    void deliver(OtpMessage message);

    void discard(String verificationToken);

    record OtpMessage(
            String verificationToken,
            String destination,
            String code,
            Instant expiresAt) {
    }
}
