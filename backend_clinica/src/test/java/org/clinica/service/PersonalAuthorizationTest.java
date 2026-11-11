package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.quarkus.test.junit.QuarkusTestProfile;
import io.quarkus.test.junit.TestProfile;
import io.restassured.specification.RequestSpecification;
import jakarta.enterprise.context.ApplicationScoped;
import jakarta.enterprise.inject.Alternative;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import org.clinica.config.TriggerExceptionMapper;
import org.clinica.dto.personal.CrearMedicoDTO;
import org.clinica.entity.Bitacora;
import org.clinica.service.PersonalTestFixtures.Account;
import org.eclipse.microprofile.config.inject.ConfigProperty;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.parallel.Execution;
import org.junit.jupiter.api.parallel.ExecutionMode;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;

import java.time.Duration;
import java.time.Instant;
import java.time.InstantSource;
import java.time.ZoneOffset;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicReference;

import static org.hamcrest.Matchers.equalTo;
import static org.hamcrest.Matchers.hasItem;
import static org.hamcrest.Matchers.hasKey;
import static org.hamcrest.Matchers.not;
import static org.clinica.service.PersonalTestFixtures.AUTH;
import static org.clinica.service.PersonalTestFixtures.PERSONAL;
import static org.clinica.service.PersonalTestFixtures.authenticated;
import static org.clinica.service.PersonalTestFixtures.json;
import static org.clinica.service.PersonalTestFixtures.login;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;

@QuarkusTest
@TestProfile(PersonalAuthorizationTest.Profile.class)
@Execution(ExecutionMode.SAME_THREAD)
class PersonalAuthorizationTest {

    private static final String MEDICOS = PERSONAL + "/medicos";
    @Inject EntityManager em;
    @Inject PersonalService personalService;
    @Inject TestSessionStore testSessionStore;

    @ConfigProperty(name = "otp.demo.access-key")
    String demoKey;

    @ParameterizedTest
    @CsvSource({
            "GET, /medicos",
            "GET, /medicos/MED999999",
            "GET, /especialidades",
            "POST, /medicos",
            "PUT, /medicos/MED999999",
            "PUT, /medicos/MED999999/estado"
    })
    void allAdministrativeRoutesRequireBearerEvenWithLegacyHeaders(String method, String path) {
        RequestSpecification request = json().header("X-Actor-Rol", "ROL004")
                .header("X-Actor-Cod-Usu", "USU999999");
        if (!"GET".equals(method)) {
            request.body(Map.of());
        }
        request.request(method, PERSONAL + path).then().statusCode(401)
                .body("error.code", equalTo("AUTHENTICATION_REQUIRED"));
    }

    @Test
    void unknownTokenIsRejected() {
        authenticated(UUID.randomUUID().toString()).get(MEDICOS).then().statusCode(401)
                .body("error.code", equalTo("INVALID_SESSION"));
    }

    @Test
    void expiredTokenIsRejected() {
        String token = login(createUser("ROL004"), "ROL004");
        testSessionStore.expireIssuedSessions();

        authenticated(token).get(MEDICOS).then().statusCode(401)
                .body("error.code", equalTo("INVALID_SESSION"));
    }

    @Test
    void logoutRevokesAccessToPersonal() {
        String token = login(createUser("ROL004"), "ROL004");
        authenticated(token).get(MEDICOS).then().statusCode(200);
        authenticated(token).post(AUTH + "/logout").then().statusCode(200)
                .body("data.revoked", equalTo(true));

        authenticated(token).get(MEDICOS).then().statusCode(401)
                .body("error.code", equalTo("INVALID_SESSION"));
    }

    @ParameterizedTest
    @CsvSource({"ROL001, false", "ROL002, false", "ROL003, false", "ROL001, true", "ROL002, true"})
    void otherRolesCannotManageDoctors(String role, boolean forgeRole) {
        String token = login(createUser(role), role);
        RequestSpecification request = authenticated(token).body(Map.of());
        if (forgeRole) {
            request.header("X-Actor-Rol", "ROL004");
        }

        request.post(MEDICOS).then().statusCode(403)
                .body("ok", equalTo(false)).body("error", equalTo("SIN_PERMISO"));
    }

    @Test
    void accountDisabledAfterLoginIsRejectedAndSessionIsRevoked() {
        Account admin = createUser("ROL004");
        String token = login(admin, "ROL004");
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery(
                "UPDATE public.usuarios SET estado = 'Inactivo' WHERE cod_usu = :id")
                .setParameter("id", admin.id()).executeUpdate());

        authenticated(token).get(MEDICOS).then().statusCode(403)
                .body("error.code", equalTo("ACCOUNT_DISABLED"));
        authenticated(token).get(MEDICOS).then().statusCode(401)
                .body("error.code", equalTo("INVALID_SESSION"));
    }

    @Test
    void administratorRetainsCrudContractsAndAuditAlwaysUsesAuthenticatedActor() {
        Account admin = createUser("ROL004");
        String spoofedId = createUser("ROL004").id();
        String token = login(admin, "ROL004");
        String specialty = createSpecialty();
        var doctor = doctorData(specialty);

        authenticated(token).get(PERSONAL + "/especialidades").then().statusCode(200)
                .body("codEsp", hasItem(specialty));
        authenticated(token).queryParam("estado", "otro").get(MEDICOS).then().statusCode(400)
                .body("error", equalTo("VALIDACION"));
        authenticated(token).get(MEDICOS + "/MED999999").then().statusCode(404)
                .body("error", equalTo("NO_ENCONTRADO"));

        var created = withSpoofedActor(token, spoofedId).body(doctor).post(MEDICOS).then().statusCode(201)
                .body("nombreCompleto", equalTo(doctor.nombreCompleto))
                .body("estado", equalTo("Activo"))
                .body("especialidades.codEsp", hasItem(specialty))
                .body("$", not(hasKey("password")))
                .body("$", not(hasKey("passwordHash")))
                .body("$", not(hasKey("password_hash")))
                .body("$", not(hasKey("accessToken")))
                .extract().jsonPath();
        String doctorCode = created.getString("codMed");
        String doctorUserId = created.getString("codUsu");
        String path = MEDICOS + "/" + doctorCode;

        authenticated(token).queryParam("estado", "Activo").get(MEDICOS).then().statusCode(200)
                .body("codMed", hasItem(doctorCode));
        authenticated(token).get(path).then().statusCode(200).body("codMed", equalTo(doctorCode));
        authenticated(token).body(doctor).post(MEDICOS).then().statusCode(409)
                .body("error", equalTo("DUPLICADO"));

        doctor.nombreCompleto = "Médico actualizado";
        doctor.duracionConsultaMin = 45;
        doctor.password = null;
        withSpoofedActor(token, spoofedId).body(doctor).put(path).then().statusCode(200)
                .body("nombreCompleto", equalTo(doctor.nombreCompleto))
                .body("especialidades[0].duracionConsultaMin", equalTo(45));
        for (String status : List.of("Inactivo", "Activo")) {
            withSpoofedActor(token, spoofedId).body(Map.of("estado", status)).put(path + "/estado")
                    .then().statusCode(200).body("estado", equalTo(status));
        }

        QuarkusTransaction.requiringNew().run(() -> {
            var rows = em.createQuery("""
                    FROM Bitacora
                    WHERE (nombreTabla = 'usuarios' AND claveRegistro = :userId)
                       OR (nombreTabla IN ('medicos', 'medico_especialidades') AND claveRegistro = :doctorCode)
                    ORDER BY secuencial
                    """, Bitacora.class)
                    .setParameter("userId", doctorUserId).setParameter("doctorCode", doctorCode)
                    .getResultList();
            assertEquals(List.of("usuarios:INSERT", "medicos:INSERT", "medico_especialidades:INSERT",
                            "usuarios:UPDATE", "medicos:UPDATE", "medico_especialidades:UPDATE",
                            "medicos:UPDATE", "usuarios:UPDATE", "medicos:UPDATE", "usuarios:UPDATE"),
                    rows.stream().map(row -> row.nombreTabla + ":" + row.accion).toList());
            rows.forEach(row -> assertEquals(admin.id(), row.codUsu));
            assertNull(em.createNativeQuery("SELECT public.actor_actual()").getSingleResult());
        });
    }

    @Test
    void doctorPasswordChangeAndInactivationRevokeSessions() {
        String adminToken = login(createUser("ROL004"), "ROL004");
        var doctor = doctorData(createSpecialty());
        var created = authenticated(adminToken).body(doctor).post(MEDICOS).then().statusCode(201).extract().jsonPath();
        String codUsu = created.getString("codUsu");
        String path = MEDICOS + "/" + created.getString("codMed");
        String initialToken = login(new Account(codUsu, doctor.email, doctor.password), "ROL003");

        doctor.password = UUID.randomUUID().toString();
        authenticated(adminToken).body(doctor).put(path).then().statusCode(200);
        authenticated(initialToken).get(AUTH + "/me").then().statusCode(401)
                .body("error.code", equalTo("INVALID_SESSION"));
        String newToken = login(new Account(codUsu, doctor.email, doctor.password), "ROL003");

        authenticated(adminToken).body(Map.of("estado", "Inactivo")).put(path + "/estado").then().statusCode(200);
        authenticated(newToken).get(AUTH + "/me").then().statusCode(401)
                .body("error.code", equalTo("INVALID_SESSION"));
    }

    @Test
    void auditFailureRollsBackWriteAndDoesNotExposeDatabaseDetails() {
        CrearMedicoDTO doctor = doctorData(createSpecialty());
        String nonexistentActor = UUID.randomUUID().toString();

        var failure = assertThrows(PersistenceException.class,
                () -> personalService.registrarMedico(doctor, nonexistentActor));
        var response = new TriggerExceptionMapper().toResponse(failure);
        assertEquals(500, response.getStatus());
        assertEquals(Map.of("ok", false, "error", "ERROR_INTERNO",
                "mensaje", "Ocurrió un error interno inesperado."), response.getEntity());
        QuarkusTransaction.requiringNew().run(() -> {
            assertEquals(0L, em.createNativeQuery("SELECT count(*) FROM public.usuarios WHERE email = :email")
                    .setParameter("email", doctor.email).getSingleResult());
            assertNull(em.createNativeQuery("SELECT public.actor_actual()").getSingleResult());
        });
    }

    @Test
    void publicPatientRegistrationAndOtpStillLeadToLogin() {
        String suffix = UUID.randomUUID().toString();
        Account patient = new Account(null, suffix + "@example.test", "Aa9" + UUID.randomUUID());
        String verification = json().body(Map.of(
                "nombreCompleto", "Paciente registro", "email", patient.email(), "password", patient.password(),
                "ci", suffix.substring(0, 18), "fechaNacimiento", "1990-01-01", "telefono", "+59170000001",
                "ciudadResidencia", "La Paz"))
                .post(AUTH + "/register").then().statusCode(201)
                .body("data.roleCode", equalTo("ROL001"))
                .extract().path("data.verification.verificationToken");
        String code = json().header("X-Demo-Otp-Key", demoKey).body(Map.of("verificationToken", verification))
                .post(AUTH + "/otp/demo-code").then().statusCode(200).extract().path("data.code");
        json().body(Map.of("verificationToken", verification, "code", code))
                .post(AUTH + "/otp/verify").then().statusCode(200).body("data.verified", equalTo(true));

        authenticated(login(patient, "ROL001")).get(AUTH + "/me").then().statusCode(200)
                .body("data.user.email", equalTo(patient.email()));
    }

    private Account createUser(String role) {
        return PersonalTestFixtures.createUser(em, role);
    }

    private String createSpecialty() {
        return PersonalTestFixtures.createSpecialty(em);
    }

    private static CrearMedicoDTO doctorData(String specialty) {
        var doctor = new CrearMedicoDTO();
        doctor.nombreCompleto = "Médico RBAC";
        doctor.email = UUID.randomUUID() + "@example.test";
        doctor.password = UUID.randomUUID().toString();
        doctor.matriculaProfesional = UUID.randomUUID().toString();
        doctor.duracionConsultaMin = 30;
        doctor.codigosEspecialidades = List.of(specialty);
        return doctor;
    }

    private static RequestSpecification withSpoofedActor(String token, String id) {
        return authenticated(token).header("X-Actor-Cod-Usu", id).header("X-Actor-Rol", "ROL002");
    }

    public static class Profile implements QuarkusTestProfile {
        @Override
        public Set<Class<?>> getEnabledAlternatives() {
            return Set.of(TestSessionStore.class);
        }

        @Override
        public Map<String, String> getConfigOverrides() {
            return Map.of("otp.delivery.provider", "demo", "otp.demo.access-key", UUID.randomUUID().toString());
        }
    }

    @Alternative
    @ApplicationScoped
    public static class TestSessionStore extends AuthSessionStore {
        private static final Duration TTL = Duration.ofMinutes(30);
        private final AtomicReference<Instant> now;

        public TestSessionStore() {
            this(new AtomicReference<>(Instant.now()));
        }

        private TestSessionStore(AtomicReference<Instant> now) {
            super(TTL, ((InstantSource) now::get).withZone(ZoneOffset.UTC));
            this.now = now;
        }

        public void expireIssuedSessions() {
            now.updateAndGet(time -> time.plus(TTL));
        }
    }
}
