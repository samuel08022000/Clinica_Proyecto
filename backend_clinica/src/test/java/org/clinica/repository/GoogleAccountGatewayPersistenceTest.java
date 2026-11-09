package org.clinica.repository;

import io.quarkus.test.TestTransaction;
import io.quarkus.test.junit.QuarkusTest;
import jakarta.inject.Inject;
import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.clinica.repository.GoogleAccountGateway.GooglePatientDetails;
import org.junit.jupiter.api.Test;

import java.time.LocalDate;
import java.util.UUID;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

@QuarkusTest
class GoogleAccountGatewayPersistenceTest {

    @Inject
    GoogleAccountGateway gateway;

    @Test
    @TestTransaction
    void googlePatientAndExternalIdentityAreCreatedAtomicallyAndRolledBack() {
        String suffix = UUID.randomUUID().toString().replace("-", "");
        GoogleIdentity identity = new GoogleIdentity(
                "google-sub-" + suffix,
                "google-" + suffix + "@example.test",
                "Paciente Integracion",
                true);

        String userId = gateway.registerPatient(identity, new GooglePatientDetails(
                "CI-G-" + suffix.substring(0, 12),
                LocalDate.of(1994, 7, 16),
                "+59170000000",
                "Santa Cruz"));

        var account = gateway.findAccountById(userId).orElseThrow();
        var linked = gateway.findBySubject(identity.subject()).orElseThrow();
        assertEquals("ROL001", account.roleCode());
        assertEquals(userId, linked.userId());
        assertEquals(identity.subject(), linked.subject());
        assertNull(account.passwordHash());
        assertTrue(Boolean.TRUE.equals(account.emailVerified()));
    }
}
