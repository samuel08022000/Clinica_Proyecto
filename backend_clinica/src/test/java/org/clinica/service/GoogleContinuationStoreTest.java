package org.clinica.service;

import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.junit.jupiter.api.Test;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class GoogleContinuationStoreTest {

    @Test
    void ticketIsOpaqueSingleUseAndBoundToItsPurpose() {
        GoogleContinuationStore store = new GoogleContinuationStore(
                Duration.ofMinutes(10),
                Clock.fixed(Instant.parse("2026-11-06T12:00:00Z"), ZoneOffset.UTC));
        var issued = store.issue(
                GoogleContinuationStore.Purpose.REGISTER,
                new GoogleIdentity("google-sub", "patient@example.test", "Patient Test", true));

        assertTrue(store.claim(issued.rawToken(), GoogleContinuationStore.Purpose.LINK).isEmpty());
        assertEquals("google-sub", store.claim(
                issued.rawToken(), GoogleContinuationStore.Purpose.REGISTER).orElseThrow().identity().subject());
    }

    @Test
    void validTicketCanOnlyBeClaimedOnce() {
        GoogleContinuationStore store = new GoogleContinuationStore(
                Duration.ofMinutes(10),
                Clock.fixed(Instant.parse("2026-11-06T12:00:00Z"), ZoneOffset.UTC));
        var issued = store.issue(
                GoogleContinuationStore.Purpose.LINK,
                new GoogleIdentity("google-sub", "patient@example.test", "Patient Test", true));

        var claimed = store.claim(issued.rawToken(), GoogleContinuationStore.Purpose.LINK).orElseThrow();
        assertEquals("google-sub", claimed.identity().subject());
        assertTrue(store.claim(issued.rawToken(), GoogleContinuationStore.Purpose.LINK).isEmpty());
    }
}
