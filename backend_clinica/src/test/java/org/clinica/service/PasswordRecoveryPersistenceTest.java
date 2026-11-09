package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import io.quarkus.test.TestTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.quarkus.test.junit.QuarkusTestProfile;
import io.quarkus.test.junit.TestProfile;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import org.clinica.repository.GoogleAccountGateway;
import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;
import org.clinica.repository.GoogleAccountGateway.GooglePatientDetails;
import org.junit.jupiter.api.Test;

import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.util.Arrays;
import java.util.Map;
import java.util.UUID;
import java.util.stream.Collectors;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

@QuarkusTest
@TestProfile(PasswordRecoveryPersistenceTest.DemoRecoveryProfile.class)
class PasswordRecoveryPersistenceTest {

    private static final String DEMO_KEY = "phase-2c-test-key-123456789";

    @Inject PasswordRecoveryService recoveryService;
    @Inject ConfiguredOtpDeliveryProvider deliveryProvider;
    @Inject GoogleAccountGateway googleAccounts;
    @Inject AuthService authService;
    @Inject EntityManager entityManager;

    @Test
    @TestTransaction
    void demoRecoveryUsesRealDatabaseHashConsumesTokenAndRevokesSession() {
        String suffix = UUID.randomUUID().toString().replace("-", "");
        String email = "recovery-" + suffix + "@example.test";
        String userId = googleAccounts.registerPatient(
                new GoogleIdentity("recovery-sub-" + suffix, email, "Paciente Recuperacion", true),
                new GooglePatientDetails(
                        "CI-R-" + suffix.substring(0, 12),
                        LocalDate.of(1992, 5, 20),
                        "+59170000002",
                        "La Paz"));
        String previousSession = authService.loginTrustedUser(userId).session().accessToken();

        var response = recoveryService.request(email);
        var demoLink = deliveryProvider.readDemoPasswordReset(email, DEMO_KEY, "127.0.0.1").orElseThrow();
        Map<String, String> query = queryParameters(demoLink.resetUrl());
        var reset = recoveryService.reset(email, query.get("token"), "NuevaSegura123");
        entityManager.clear();

        assertTrue(response.message().startsWith("Si la cuenta es elegible"));
        assertTrue(reset.reset());
        assertTrue(BcryptUtil.matches(
                "NuevaSegura123",
                googleAccounts.findAccountById(userId).orElseThrow().passwordHash()));
        assertEquals("INVALID_SESSION", assertThrows(
                AuthException.class,
                () -> authService.me(previousSession)).code());
        assertEquals("PASSWORD_RESET_TOKEN_INVALID", assertThrows(
                AuthException.class,
                () -> recoveryService.reset(email, query.get("token"), "OtraSegura123")).code());
    }

    private static Map<String, String> queryParameters(String url) {
        return Arrays.stream(URI.create(url).getRawQuery().split("&"))
                .map(pair -> pair.split("=", 2))
                .collect(Collectors.toMap(
                        pair -> URLDecoder.decode(pair[0], StandardCharsets.UTF_8),
                        pair -> URLDecoder.decode(pair[1], StandardCharsets.UTF_8)));
    }

    public static final class DemoRecoveryProfile implements QuarkusTestProfile {
        @Override
        public Map<String, String> getConfigOverrides() {
            return Map.of(
                    "otp.delivery.provider", "demo",
                    "otp.demo.access-key", DEMO_KEY,
                    "auth.frontend.base-url", "http://127.0.0.1:8000");
        }
    }
}
