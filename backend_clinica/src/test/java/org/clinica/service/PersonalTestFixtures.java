package org.clinica.service;

import io.quarkus.elytron.security.common.BcryptUtil;
import io.quarkus.narayana.jta.QuarkusTransaction;
import io.restassured.specification.RequestSpecification;
import jakarta.persistence.EntityManager;

import java.util.Map;
import java.util.UUID;

import static io.restassured.RestAssured.given;
import static org.hamcrest.Matchers.equalTo;

final class PersonalTestFixtures {

    static final String AUTH = "/api/v1/auth";
    static final String PERSONAL = "/api/v1/personal";

    private PersonalTestFixtures() {
    }

    static Account createUser(EntityManager em, String role) {
        String suffix = UUID.randomUUID().toString();
        String email = suffix + "@example.test";
        String password = UUID.randomUUID().toString();
        String id = QuarkusTransaction.requiringNew().call(() -> {
            String userId = (String) em.createNativeQuery("""
                    INSERT INTO public.usuarios (nombre_completo, email, password_hash, cod_rol)
                    VALUES ('Usuario RBAC', :email, :hash, :role) RETURNING cod_usu
                    """).setParameter("email", email).setParameter("hash", BcryptUtil.bcryptHash(password))
                    .setParameter("role", role).getSingleResult();
            switch (role) {
                case "ROL001" -> em.createNativeQuery("""
                        INSERT INTO public.pacientes (cod_usu, ci, fecha_nacimiento, telefono,
                            ciudad_residencia, email_verificado)
                        VALUES (:id, :ci, DATE '1990-01-01', '+59170000001', 'La Paz', true)
                        """).setParameter("id", userId).setParameter("ci", suffix.substring(0, 18)).executeUpdate();
                case "ROL002" -> em.createNativeQuery("INSERT INTO public.secretarias (cod_usu) VALUES (:id)")
                        .setParameter("id", userId).executeUpdate();
                case "ROL003" -> em.createNativeQuery("""
                        INSERT INTO public.medicos (cod_usu, matricula_profesional) VALUES (:id, :matricula)
                        """).setParameter("id", userId).setParameter("matricula", suffix).executeUpdate();
                case "ROL004" -> { }
                default -> throw new IllegalArgumentException("Rol de prueba no reconocido: " + role);
            }
            return userId;
        });
        return new Account(id, email, password);
    }

    static String createSpecialty(EntityManager em) {
        return QuarkusTransaction.requiringNew().call(() -> (String) em.createNativeQuery("""
                INSERT INTO public.especialidades (nombre_especialidad, duracion_consulta_min)
                VALUES (:name, 30) RETURNING cod_esp
                """).setParameter("name", "Especialidad RBAC " + UUID.randomUUID()).getSingleResult());
    }

    static String login(Account account, String role) {
        return json().body(Map.of("email", account.email(), "password", account.password()))
                .post(AUTH + "/login").then().statusCode(200)
                .body("data.user.role.code", equalTo(role))
                .body("data.session.tokenType", equalTo("Bearer"))
                .extract().path("data.session.accessToken");
    }

    static RequestSpecification json() {
        return given().contentType("application/json").accept("application/json");
    }

    static RequestSpecification authenticated(String token) {
        return json().header("Authorization", "Bearer " + token);
    }

    record Account(String id, String email, String password) {
    }
}
