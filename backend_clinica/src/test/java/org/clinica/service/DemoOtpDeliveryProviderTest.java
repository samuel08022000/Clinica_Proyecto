package org.clinica.service;

import org.junit.jupiter.api.Test;

import java.time.Instant;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class DemoOtpDeliveryProviderTest {

    private static final String ACCESS_KEY = "local-demo-access-key-123456";

    @Test
    void demoStoresTheCodeOnlyForControlledLocalRetrieval() {
        DemoOtpDeliveryProvider provider = new DemoOtpDeliveryProvider(ACCESS_KEY, true);
        var receipt = provider.deliver(message());

        assertEquals("DEMO", receipt.mode());
        assertFalse(receipt.deliveryConfirmed());
        assertEquals("482915", provider.readDemoCodeForDestination(
                "PATIENT@EXAMPLE.TEST",
                ACCESS_KEY,
                "127.0.0.1").orElseThrow().code());
        assertTrue(provider.readDemoCodeForDestination(
                "patient@example.test",
                "wrong-key",
                "127.0.0.1").isEmpty());
        assertTrue(provider.readDemoCodeForDestination(
                "patient@example.test",
                ACCESS_KEY,
                "192.0.2.10").isEmpty());
    }

    @Test
    void discardRemovesTheTransientDemoCode() {
        DemoOtpDeliveryProvider provider = new DemoOtpDeliveryProvider(ACCESS_KEY, true);
        provider.deliver(message());

        provider.discard("opaque-verification-token");

        assertTrue(provider.readDemoCode(
                "opaque-verification-token",
                ACCESS_KEY,
                "::1").isEmpty());
    }

    private static OtpDeliveryProvider.OtpMessage message() {
        return new OtpDeliveryProvider.OtpMessage(
                "opaque-verification-token",
                "patient@example.test",
                "482915",
                Instant.now().plusSeconds(300));
    }
}
