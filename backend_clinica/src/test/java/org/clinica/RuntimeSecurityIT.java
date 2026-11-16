package org.clinica;

import io.quarkus.test.junit.QuarkusIntegrationTest;
import org.junit.jupiter.api.Test;

import static io.restassured.RestAssured.given;

/** Verifica la aplicación empaquetada, sin publicar herramientas de desarrollo. */
@QuarkusIntegrationTest
class RuntimeSecurityIT {
    @Test
    void packagedApplicationRequiresAuthenticationAndHasNoDemoEndpoints() {
        given().get("/api/v1/auth/me").then().statusCode(401);
        given().get("/hello").then().statusCode(404);
        for (String endpoint : new String[]{"/otp/demo-code", "/password/demo-link"}) {
            given().contentType("application/json").body("{}")
                    .post("/api/v1/auth" + endpoint).then().statusCode(404);
        }
    }
}
