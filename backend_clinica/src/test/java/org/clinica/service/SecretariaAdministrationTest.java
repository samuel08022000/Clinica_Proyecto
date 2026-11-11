package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import org.clinica.entity.Bitacora;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.service.PersonalTestFixtures.Account;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;

import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.UUID;

import static org.clinica.service.PersonalTestFixtures.AUTH;
import static org.clinica.service.PersonalTestFixtures.PERSONAL;
import static org.clinica.service.PersonalTestFixtures.authenticated;
import static org.clinica.service.PersonalTestFixtures.createSpecialty;
import static org.clinica.service.PersonalTestFixtures.createUser;
import static org.clinica.service.PersonalTestFixtures.json;
import static org.clinica.service.PersonalTestFixtures.login;
import static org.hamcrest.Matchers.equalTo;
import static org.hamcrest.Matchers.hasItem;
import static org.hamcrest.Matchers.not;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;

@QuarkusTest
class SecretariaAdministrationTest {

    private static final String SECRETARIAS = PERSONAL + "/secretarias";

    @Inject EntityManager em;
    @Inject SecretariaService secretariaService;
    @Inject CitaFunctionRepository citas;

    @Test
    void createsListsReadsAndUpdatesSecretaryWithAuditedIdentity() {
        Account admin = createUser(em, "ROL004");
        String fakeActor = createUser(em, "ROL004").id();
        String token = login(admin, "ROL004");
        var input = newSecretary();
        var created = withActorHeaders(token, fakeActor).body(input).post(SECRETARIAS).then().statusCode(201)
                .body("nombreCompleto", equalTo(input.get("nombreCompleto")))
                .body("email", equalTo(input.get("email")))
                .body("estado", equalTo("Activo")).extract().jsonPath();
        assertEquals(7, created.getMap("$").size());
        String codSec = created.getString("codSec");
        String codUsu = created.getString("codUsu");
        String path = SECRETARIAS + "/" + codSec;
        String staffToken = login(new Account(codUsu, (String) input.get("email"), (String) input.get("password")), "ROL002");

        authenticated(token).get(path).then().statusCode(200).body("codUsu", equalTo(codUsu));
        authenticated(token).get(SECRETARIAS).then().statusCode(200).body("codSec", hasItem(codSec));
        authenticated(token).queryParam("estado", "Activo").get(SECRETARIAS).then().statusCode(200)
                .body("codSec", hasItem(codSec));
        authenticated(token).queryParam("estado", "Inactivo").get(SECRETARIAS).then().statusCode(200)
                .body("codSec", not(hasItem(codSec)));

        String newEmail = newEmail();
        withActorHeaders(token, fakeActor).body(Map.of("nombreCompleto", "Secretaría actualizada", "email", newEmail,
                        "ci", input.get("ci"), "telefono", "+59170000005"))
                .put(path).then().statusCode(200)
                .body("codSec", equalTo(codSec)).body("codUsu", equalTo(codUsu))
                .body("nombreCompleto", equalTo("Secretaría actualizada"))
                .body("email", equalTo(newEmail)).body("telefono", equalTo("+59170000005"));
        authenticated(staffToken).get(AUTH + "/me").then().statusCode(200)
                .body("data.user.email", equalTo(newEmail));

        assertAudit(admin.id(), codSec, codUsu,
                List.of("secretarias:INSERT", "secretarias:UPDATE", "usuarios:INSERT", "usuarios:UPDATE"));
    }

    @Test
    void inactivationRevokesSessionAndRetainsAppointmentAndAccount() {
        Account admin = createUser(em, "ROL004");
        String token = login(admin, "ROL004");
        var staff = register(token, newSecretary());
        String staffToken = login(staff.account(), "ROL002");
        String cita = createCounterAppointment(staff.account().id());
        String statePath = SECRETARIAS + "/" + staff.codSec() + "/estado";

        withActorHeaders(token, createUser(em, "ROL004").id()).body(Map.of("estado", "Inactivo"))
                .put(statePath).then().statusCode(200).body("estado", equalTo("Inactivo"));
        assertRevoked(staffToken);
        credentials(staff.account()).post(AUTH + "/login").then().statusCode(403)
                .body("error.code", equalTo("ACCOUNT_DISABLED"));
        authenticated(token).queryParam("estado", "Inactivo").get(SECRETARIAS).then().statusCode(200)
                .body("codSec", hasItem(staff.codSec()));
        assertEquals(staff.codSec(), em.createNativeQuery("SELECT cod_sec FROM public.citas WHERE cod_cit = :id")
                .setParameter("id", cita).getSingleResult());
        assertAudit(admin.id(), staff.codSec(), staff.account().id(),
                List.of("secretarias:INSERT", "secretarias:UPDATE", "usuarios:INSERT", "usuarios:UPDATE"));

        authenticated(token).body(Map.of("estado", "Activo")).put(statePath).then().statusCode(200)
                .body("codUsu", equalTo(staff.account().id())).body("estado", equalTo("Activo"));
        assertRevoked(staffToken);
        authenticated(login(staff.account(), "ROL002")).get(AUTH + "/me").then().statusCode(200);
    }

    @Test
    void resettingPasswordRevokesSessionAndRejectsOldPassword() {
        Account admin = createUser(em, "ROL004");
        String token = login(admin, "ROL004");
        var staff = register(token, newSecretary());
        String oldToken = login(staff.account(), "ROL002");
        String newPassword = newPassword();

        authenticated(token).body(Map.of("password", newPassword))
                .put(SECRETARIAS + "/" + staff.codSec() + "/password").then().statusCode(204);
        assertRevoked(oldToken);
        credentials(staff.account()).post(AUTH + "/login").then().statusCode(401)
                .body("error.code", equalTo("INVALID_CREDENTIALS"));
        Account updated = new Account(staff.account().id(), staff.account().email(), newPassword);
        authenticated(login(updated, "ROL002")).get(AUTH + "/me").then().statusCode(200);
        assertAudit(admin.id(), staff.codSec(), staff.account().id(),
                List.of("secretarias:INSERT", "usuarios:INSERT", "usuarios:UPDATE"));
    }

    @Test
    void rollbackPreservesExistingSessionAndAuditHistory() {
        Account admin = createUser(em, "ROL004");
        var staff = register(login(admin, "ROL004"), newSecretary());
        String staffToken = login(staff.account(), "ROL002");

        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            secretariaService.cambiarEstado(staff.codSec(), "Inactivo", admin.id());
            em.flush();
            throw new IllegalStateException("Revertir la transacción de prueba");
        }));

        authenticated(staffToken).get(AUTH + "/me").then().statusCode(200)
                .body("data.user.accountStatus", equalTo("Activo"));
        assertAudit(admin.id(), staff.codSec(), staff.account().id(), List.of("secretarias:INSERT", "usuarios:INSERT"));
    }

    @Test
    void rejectsDuplicateEmailAcrossRolesAndWhenUpdating() {
        Account admin = createUser(em, "ROL004");
        String token = login(admin, "ROL004");
        var input = newSecretary();
        input.put("email", " " + admin.email().toUpperCase(Locale.ROOT) + " ");
        authenticated(token).body(input).post(SECRETARIAS).then().statusCode(409)
                .body("error", equalTo("DUPLICADO"));

        var staff = register(token, newSecretary());
        input.remove("password");
        authenticated(token).body(input).put(SECRETARIAS + "/" + staff.codSec()).then().statusCode(409)
                .body("error", equalTo("DUPLICADO"));
        authenticated(token).get(SECRETARIAS + "/" + staff.codSec()).then().statusCode(200)
                .body("email", equalTo(staff.account().email()));
    }

    @Test
    void normalizedDocumentConflictRollsBackAccountCreation() {
        String token = adminToken();
        String ci = UUID.randomUUID().toString().substring(0, 12);
        var original = newSecretary();
        original.put("ci", ci);
        register(token, original);
        var duplicate = newSecretary();
        duplicate.put("ci", ci.replace("-", "").toUpperCase(Locale.ROOT));

        authenticated(token).body(duplicate).post(SECRETARIAS).then().statusCode(409)
                .body("error", equalTo("DUPLICADO"));
        assertEquals(0L, em.createNativeQuery("SELECT count(*) FROM public.usuarios WHERE email = :email")
                .setParameter("email", duplicate.get("email")).getSingleResult());
    }

    @ParameterizedTest
    @CsvSource({"nombreCompleto, xy", "email, sin-arroba", "password, debil", "telefono, 12345678901234567890123456"})
    void rejectsInvalidInput(String field, String invalid) {
        var input = newSecretary();
        input.put(field, invalid);
        authenticated(adminToken()).body(input).post(SECRETARIAS).then().statusCode(400)
                .body("error", equalTo("VALIDACION"));
    }

    @Test
    void rejectsChangesToRoleIdentifiersAndInternalFields() {
        String token = adminToken();
        var input = newSecretary();
        input.put("codRol", "ROL004");
        authenticated(token).body(input).post(SECRETARIAS).then().statusCode(400)
                .body("error", equalTo("VALIDACION"));

        var staff = register(token, newSecretary());
        String path = SECRETARIAS + "/" + staff.codSec();
        for (String field : List.of("codRol", "codUsu", "codSec", "actualizadoEn", "passwordHash")) {
            var update = new HashMap<>(Map.of("nombreCompleto", "Nombre cambiado", "email", staff.account().email()));
            update.put(field, "ROL004");
            authenticated(token).body(update).put(path).then().statusCode(400).body("error", equalTo("VALIDACION"));
        }
        authenticated(token).body(Map.of("estado", "Inactivo", "codRol", "ROL004"))
                .put(path + "/estado").then().statusCode(400);
        authenticated(token).body(Map.of("password", newPassword(), "codRol", "ROL004"))
                .put(path + "/password").then().statusCode(400);
        authenticated(login(staff.account(), "ROL002")).get(AUTH + "/me").then().statusCode(200)
                .body("data.user.name", equalTo("Secretaría de prueba"));
    }

    @Test
    void reportsInvalidStatusAndMissingProfile() {
        String token = adminToken();
        authenticated(token).queryParam("estado", "Otro").get(SECRETARIAS).then().statusCode(400)
                .body("error", equalTo("VALIDACION"));
        authenticated(token).get(SECRETARIAS + "/SEC999999").then().statusCode(404)
                .body("error", equalTo("NO_ENCONTRADO"));
        var staff = register(token, newSecretary());
        authenticated(token).body(Map.of("estado", "Otro"))
                .put(SECRETARIAS + "/" + staff.codSec() + "/estado").then().statusCode(400)
                .body("error", equalTo("VALIDACION"));
    }

    @ParameterizedTest
    @CsvSource({"GET, ''", "GET, /SEC999999", "POST, ''", "PUT, /SEC999999", "PUT, /SEC999999/estado", "PUT, /SEC999999/password"})
    void everyRouteRequiresSession(String method, String path) {
        RequestSpecification request = json().header("X-Actor-Rol", "ROL004").header("X-Actor-Cod-Usu", "USU999999");
        if (!"GET".equals(method)) {
            request.body(Map.of());
        }
        request.request(method, SECRETARIAS + path).then().statusCode(401)
                .body("error.code", equalTo("AUTHENTICATION_REQUIRED"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL002", "ROL003"})
    void otherRolesCannotManageSecretariesEvenWithForgedHeaders(String role) {
        String token = login(createUser(em, role), role);
        withActorHeaders(token, "USU999999").get(SECRETARIAS).then().statusCode(403)
                .body("error", equalTo("SIN_PERMISO"));
        withActorHeaders(token, "USU999999").body(newSecretary()).post(SECRETARIAS).then().statusCode(403)
                .body("error", equalTo("SIN_PERMISO"));
    }

    private String adminToken() {
        return login(createUser(em, "ROL004"), "ROL004");
    }

    private String createCounterAppointment(String secretaryUser) {
        String patientUser = createUser(em, "ROL001").id();
        String doctorUser = createUser(em, "ROL003").id();
        String specialty = createSpecialty(em);
        return QuarkusTransaction.requiringNew().call(() -> {
            String patient = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu = :id")
                    .setParameter("id", patientUser).getSingleResult();
            String doctor = (String) em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu = :id")
                    .setParameter("id", doctorUser).getSingleResult();
            em.createNativeQuery("INSERT INTO public.medico_especialidades (cod_med, cod_esp) VALUES (:doctor, :specialty)")
                    .setParameter("doctor", doctor).setParameter("specialty", specialty).executeUpdate();
            em.createNativeQuery("""
                    INSERT INTO public.horarios (cod_med, dia_semana, hora_inicio, hora_fin)
                    VALUES (:doctor, CAST(EXTRACT(ISODOW FROM public.fecha_clinica() + 1) AS smallint),
                        TIME '08:00', TIME '10:00')
                    """).setParameter("doctor", doctor).executeUpdate();
            String slot = (String) em.createNativeQuery("""
                    SELECT cod_slot FROM public.slots_agenda
                    WHERE cod_med = :doctor AND cod_esp = :specialty AND fecha = public.fecha_clinica() + 1
                    ORDER BY inicio_at LIMIT 1
                    """).setParameter("doctor", doctor).setParameter("specialty", specialty).getSingleResult();
            return citas.reservarCita(patient, slot, secretaryUser, "Ventanilla", null);
        });
    }

    private void assertAudit(String actor, String codSec, String codUsu, List<String> expectedActions) {
        var records = em.createQuery("""
                FROM Bitacora
                WHERE (nombreTabla = 'usuarios' AND claveRegistro = :usuario)
                   OR (nombreTabla = 'secretarias' AND claveRegistro = :secretaria)
                ORDER BY nombreTabla, accion
                """, Bitacora.class).setParameter("usuario", codUsu).setParameter("secretaria", codSec)
                .getResultList();
        assertEquals(expectedActions, records.stream().map(row -> row.nombreTabla + ":" + row.accion).toList());
        for (Bitacora row : records) {
            assertEquals(actor, row.codUsu);
            assertFalse(row.datosNuevos.containsKey("password_hash"));
        }
    }

    private static Staff register(String token, Map<String, Object> input) {
        var created = authenticated(token).body(input).post(SECRETARIAS).then().statusCode(201).extract().jsonPath();
        return new Staff(created.getString("codSec"), new Account(created.getString("codUsu"),
                created.getString("email"), (String) input.get("password")));
    }

    private static void assertRevoked(String token) {
        authenticated(token).get(AUTH + "/me").then().statusCode(401).body("error.code", equalTo("INVALID_SESSION"));
    }

    private static RequestSpecification credentials(Account account) {
        return json().body(Map.of("email", account.email(), "password", account.password()));
    }

    private static RequestSpecification withActorHeaders(String token, String actor) {
        return authenticated(token).header("X-Actor-Cod-Usu", actor).header("X-Actor-Rol", "ROL004");
    }

    private static Map<String, Object> newSecretary() {
        return new HashMap<>(Map.of("nombreCompleto", "Secretaría de prueba", "email", newEmail(),
                "ci", UUID.randomUUID().toString().substring(0, 16), "password", newPassword()));
    }

    private static String newEmail() {
        return UUID.randomUUID() + "@example.test";
    }

    private static String newPassword() {
        return "Aa9" + UUID.randomUUID();
    }

    private record Staff(String codSec, Account account) {
    }
}
