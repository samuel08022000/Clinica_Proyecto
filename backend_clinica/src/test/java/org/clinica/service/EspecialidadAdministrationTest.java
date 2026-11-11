package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import org.clinica.entity.Bitacora;
import org.clinica.repository.AgendaRepository;
import org.clinica.repository.CitaFunctionRepository;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;

import java.time.LocalDate;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;

import static org.clinica.service.PersonalTestFixtures.PERSONAL;
import static org.clinica.service.PersonalTestFixtures.authenticated;
import static org.clinica.service.PersonalTestFixtures.createUser;
import static org.clinica.service.PersonalTestFixtures.json;
import static org.clinica.service.PersonalTestFixtures.login;
import static org.hamcrest.Matchers.equalTo;
import static org.hamcrest.Matchers.hasItem;
import static org.hamcrest.Matchers.not;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

@QuarkusTest
class EspecialidadAdministrationTest {

    private static final String ESPECIALIDADES = PERSONAL + "/especialidades";
    private static final String MEDICOS = PERSONAL + "/medicos";

    @Inject EntityManager em;
    @Inject EspecialidadService especialidades;
    @Inject AgendaRepository agenda;
    @Inject CitaFunctionRepository citas;

    @Test
    void preservesLegacyOptionsAndOffersAdministrativeDetailWithAuditedWrites() {
        var admin = createUser(em, "ROL004");
        String token = login(admin, "ROL004");
        String fakeActor = createUser(em, "ROL004").id();
        String nombre = newName();
        var created = withActorHeaders(token, fakeActor).body(Map.of(
                        "nombreEspecialidad", "  " + nombre + "  ", "duracionConsultaMin", 45))
                .post(ESPECIALIDADES).then().statusCode(201)
                .body("nombreEspecialidad", equalTo(nombre)).body("estado", equalTo("Activo"))
                .extract().jsonPath();
        String codEsp = created.getString("codEsp");
        String path = ESPECIALIDADES + "/" + codEsp;

        var options = authenticated(token).get(ESPECIALIDADES).then().statusCode(200)
                .extract().jsonPath().<Map<String, Object>>getList("$");
        var option = options.stream().filter(row -> codEsp.equals(row.get("codEsp"))).findFirst().orElseThrow();
        assertEquals(Set.of("codEsp", "nombreEspecialidad", "duracionConsultaMin"), option.keySet());
        assertEquals(45, option.get("duracionConsultaMin"));
        authenticated(token).get(path).then().statusCode(200).body("codEsp", equalTo(codEsp));
        authenticated(token).queryParam("estado", "Activo").get(ESPECIALIDADES).then().statusCode(200)
                .body("find { it.codEsp == '" + codEsp + "' }.estado", equalTo("Activo"));
        authenticated(token).get(path + "/medicos").then().statusCode(200).body("size()", equalTo(0));

        String renamed = newName();
        withActorHeaders(token, fakeActor).body(Map.of("nombreEspecialidad", renamed, "descripcion", "Consulta externa"))
                .put(path).then().statusCode(200).body("codEsp", equalTo(codEsp))
                .body("nombreEspecialidad", equalTo(renamed)).body("descripcion", equalTo("Consulta externa"))
                .body("duracionConsultaMin", equalTo(45));
        withActorHeaders(token, fakeActor).body(Map.of("estado", "Inactivo")).put(path + "/estado")
                .then().statusCode(200).body("estado", equalTo("Inactivo"));
        authenticated(token).get(ESPECIALIDADES).then().statusCode(200).body("codEsp", not(hasItem(codEsp)));
        for (String filter : List.of("Inactivo", "Todos")) {
            authenticated(token).queryParam("estado", filter).get(ESPECIALIDADES).then().statusCode(200)
                    .body("find { it.codEsp == '" + codEsp + "' }.descripcion", equalTo("Consulta externa"));
        }
        withActorHeaders(token, fakeActor).body(Map.of("estado", "Activo")).put(path + "/estado")
                .then().statusCode(200).body("estado", equalTo("Activo"));

        var audit = audit(codEsp);
        assertEquals(List.of("INSERT", "UPDATE", "UPDATE", "UPDATE"), audit.stream().map(row -> row.accion).toList());
        assertEquals(List.of("Activo", "Activo", "Inactivo", "Activo"),
                audit.stream().map(row -> row.datosNuevos.get("estado")).toList());
        audit.forEach(row -> assertEquals(admin.id(), row.codUsu));
    }

    @Test
    void rejectsNormalizedDuplicatesOnCreateAndRenameIncludingInactiveRecords() {
        String token = adminToken();
        String nombre = newName();
        authenticated(token).body(Map.of("nombreEspecialidad", nombre, "duracionConsultaMin", 20, "estado", "Inactivo"))
                .post(ESPECIALIDADES).then().statusCode(201).body("estado", equalTo("Inactivo"));
        String duplicado = "  " + nombre.toLowerCase(Locale.ROOT) + "  ";
        authenticated(token).body(Map.of("nombreEspecialidad", duplicado, "duracionConsultaMin", 60))
                .post(ESPECIALIDADES).then().statusCode(409).body("error", equalTo("DUPLICADO"));

        String otro = newName();
        String codEsp = create(token, otro);
        String path = ESPECIALIDADES + "/" + codEsp;
        authenticated(token).body(Map.of("nombreEspecialidad", duplicado)).put(path)
                .then().statusCode(409).body("error", equalTo("DUPLICADO"));
        authenticated(token).get(path).then().statusCode(200).body("nombreEspecialidad", equalTo(otro));
        String mismoNombre = otro.toLowerCase(Locale.ROOT);
        authenticated(token).body(Map.of("nombreEspecialidad", mismoNombre)).put(path)
                .then().statusCode(200).body("nombreEspecialidad", equalTo(mismoNombre));
    }

    @Test
    void concurrentCreatesOfEquivalentNamesCommitOnlyOneRow() throws Exception {
        String token = adminToken();
        String nombre = newName();
        var barrier = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var requests = new ArrayList<Future<Integer>>();
            for (String variante : List.of(nombre, nombre.toUpperCase(Locale.ROOT))) {
                requests.add(executor.submit(() -> {
                    barrier.await(10, TimeUnit.SECONDS);
                    return authenticated(token).body(Map.of("nombreEspecialidad", variante, "duracionConsultaMin", 30))
                            .post(ESPECIALIDADES).statusCode();
                }));
            }
            var codes = new ArrayList<Integer>();
            for (var request : requests) {
                codes.add(request.get(20, TimeUnit.SECONDS));
            }
            assertEquals(List.of(201, 409), codes.stream().sorted().toList());
        }
        assertEquals(1L, em.createQuery("SELECT count(e) FROM Especialidad e WHERE lower(e.nombreEspecialidad) = lower(:nombre)",
                Long.class).setParameter("nombre", nombre).getSingleResult());
    }

    @Test
    void invalidInputsAndForbiddenFieldsUseExistingErrorContract() {
        String token = adminToken();
        for (String nombre : List.of("", " \t ", "\u00a0", "A".repeat(121))) {
            expectValidation(authenticated(token).body(Map.of("nombreEspecialidad", nombre, "duracionConsultaMin", 30)),
                    "POST", ESPECIALIDADES);
        }
        expectValidation(authenticated(token).body(Map.of("nombreEspecialidad", newName())), "POST", ESPECIALIDADES);
        for (Number duracion : List.of(25, 30.5)) {
            expectValidation(authenticated(token).body(Map.of("nombreEspecialidad", newName(), "duracionConsultaMin", duracion)),
                    "POST", ESPECIALIDADES);
        }
        expectValidation(authenticated(token).body(Map.of("nombreEspecialidad", newName(), "duracionConsultaMin", 30,
                "codEsp", "ESP999999")), "POST", ESPECIALIDADES);
        String path = ESPECIALIDADES + "/" + create(token, newName());
        for (String field : List.of("codEsp", "codRol", "codMed", "secuencial", "creadoEn", "estado", "duracionConsultaMin")) {
            expectValidation(authenticated(token).body(Map.of("nombreEspecialidad", newName(), field, "valor")),
                    "PUT", path);
        }
        expectValidation(authenticated(token).body(Map.of("estado", "Inactivo", "codEsp", "ESP999999")), "PUT", path + "/estado");
        expectValidation(authenticated(token).body(Map.of("estado", "Otro")), "PUT", path + "/estado");
        expectValidation(authenticated(token).queryParam("estado", "Otro"), "GET", ESPECIALIDADES);
    }

    @Test
    void unknownCodesReturnNotFoundForDetailLinksAndWrites() {
        String token = adminToken();
        String path = ESPECIALIDADES + "/ESP999999";
        for (String route : List.of(path, path + "/medicos")) {
            authenticated(token).get(route).then().statusCode(404).body("error", equalTo("NO_ENCONTRADO"));
        }
        authenticated(token).body(Map.of("nombreEspecialidad", newName())).put(path)
                .then().statusCode(404).body("error", equalTo("NO_ENCONTRADO"));
        authenticated(token).body(Map.of("estado", "Inactivo")).put(path + "/estado")
                .then().statusCode(404).body("error", equalTo("NO_ENCONTRADO"));
    }

    @ParameterizedTest
    @CsvSource({"GET, ''", "GET, /ESP999999", "POST, ''", "PUT, /ESP999999",
            "PUT, /ESP999999/estado", "GET, /ESP999999/medicos"})
    void allRoutesRejectMissingOrInvalidTokensDespiteActorHeaders(String method, String suffix) {
        for (var request : List.of(json().header("X-Actor-Rol", "ROL004"),
                withActorHeaders(UUID.randomUUID().toString(), "USU999999"))) {
            if (!"GET".equals(method)) {
                request.body(Map.of());
            }
            request.request(method, ESPECIALIDADES + suffix).then().statusCode(401);
        }
    }

    @ParameterizedTest
    @CsvSource({"ROL001, POST, ''", "ROL002, PUT, /ESP999999", "ROL003, PUT, /ESP999999/estado"})
    void nonAdministratorsCannotWriteEvenWithForgedRole(String role, String method, String suffix) {
        String token = login(createUser(em, role), role);
        withActorHeaders(token, "USU999999").body(Map.of()).request(method, ESPECIALIDADES + suffix)
                .then().statusCode(403).body("error", equalTo("SIN_PERMISO"));
    }

    @Test
    void rollbackLeavesStateAndAuditUnchanged() {
        var admin = createUser(em, "ROL004");
        String token = login(admin, "ROL004");
        String codEsp = create(token, newName());
        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            especialidades.cambiarEstado(codEsp, "Inactivo", admin.id());
            em.flush();
            throw new IllegalStateException("Revertir la transacción de prueba");
        }));

        authenticated(token).get(ESPECIALIDADES + "/" + codEsp).then().statusCode(200).body("estado", equalTo("Activo"));
        assertEquals(List.of("INSERT"), audit(codEsp).stream().map(row -> row.accion).toList());
    }

    @Test
    void inactiveSpecialtyRejectsNewReservationsAndRetainsAppointmentsAndDoctorLinks() {
        String token = adminToken();
        String codEsp = create(token, newName());
        String path = ESPECIALIDADES + "/" + codEsp;
        var doctor = doctorData(codEsp);
        String codMed = createDoctor(token, doctor);
        String codMedInactivo = createDoctor(token, doctorData(codEsp));
        authenticated(token).body(Map.of("estado", "Inactivo")).put(MEDICOS + "/" + codMedInactivo + "/estado")
                .then().statusCode(200);
        String codMedVinculoInactivo = createDoctor(token, doctorData(codEsp));
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("""
                UPDATE public.medico_especialidades SET estado = 'Inactivo' WHERE cod_med = :med AND cod_esp = :esp
                """).setParameter("med", codMedVinculoInactivo).setParameter("esp", codEsp).executeUpdate());

        LocalDate tomorrow = (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica() + 1", LocalDate.class).getSingleResult();
        schedule(codMed, tomorrow);
        var available = agenda.buscarCuposDisponibles(codEsp, codMed, tomorrow);
        assertEquals(4, available.size());
        String freeSlot = available.get(2).codSlot;
        var patient = createUser(em, "ROL001");
        String codPac = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu = :usu")
                .setParameter("usu", patient.id()).getSingleResult();
        String confirmed = citas.reservarCita(codPac, available.get(0).codSlot, patient.id(), "Web", null);
        String cancelled = citas.reservarCita(codPac, available.get(1).codSlot, patient.id(), "Web", null);
        assertTrue(citas.cancelarCita(cancelled, patient.id(), "Cancelada antes del cambio de especialidad"));

        authenticated(token).body(Map.of("estado", "Inactivo")).put(path + "/estado").then().statusCode(200);
        assertTrue(agenda.buscarCuposDisponibles(codEsp, null, tomorrow).isEmpty());
        assertThrows(PersistenceException.class, () -> citas.reservarCita(codPac, freeSlot, patient.id(), "Web", null));
        var rejected = assertThrows(PersistenceException.class, () -> QuarkusTransaction.requiringNew().run(() ->
                em.createNativeQuery("INSERT INTO public.citas (cod_pac, cod_slot) VALUES (:pac, :slot)")
                        .setParameter("pac", codPac).setParameter("slot", freeSlot).executeUpdate()));
        assertTrue(rejected.getMessage().contains("especialidad no está activa"));
        assertEquals("Confirmada", appointmentStatus(confirmed, codEsp));
        assertEquals("Cancelada", appointmentStatus(cancelled, codEsp));

        doctor.put("nombreCompleto", "Médico actualizado");
        authenticated(token).body(doctor).put(MEDICOS + "/" + codMed).then().statusCode(200)
                .body("especialidades[0].codEsp", equalTo(codEsp)).body("especialidades[0].estado", equalTo("Activo"));
        authenticated(token).body(doctorData(codEsp)).post(MEDICOS).then().statusCode(400)
                .body("error", equalTo("VALIDACION"));
        var links = authenticated(token).get(path + "/medicos").then().statusCode(200)
                .extract().jsonPath().<Map<String, Object>>getList("$");
        assertEquals(3, links.size());
        assertEquals("Activo", link(links, codMed).get("estadoVinculo"));
        assertEquals("Inactivo", link(links, codMedInactivo).get("estadoMedico"));
        assertEquals("Inactivo", link(links, codMedVinculoInactivo).get("estadoVinculo"));

        authenticated(token).body(Map.of("estado", "Activo")).put(path + "/estado").then().statusCode(200);
        assertTrue(agenda.buscarCuposDisponibles(codEsp, null, tomorrow).stream()
                .allMatch(slot -> codMed.equals(slot.codMed)));
        authenticated(token).get(MEDICOS + "/" + codMedInactivo).then().statusCode(200).body("estado", equalTo("Inactivo"));
        String newAppointment = citas.reservarCita(codPac, freeSlot, patient.id(), "Web", null);
        assertEquals("Confirmada", appointmentStatus(newAppointment, codEsp));
    }

    private void schedule(String codMed, LocalDate date) {
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("""
                INSERT INTO public.horarios (cod_med, dia_semana, hora_inicio, hora_fin)
                VALUES (:med, :dia, TIME '08:00', TIME '10:00')
                """).setParameter("med", codMed).setParameter("dia", (short) date.getDayOfWeek().getValue()).executeUpdate());
    }

    private String appointmentStatus(String codCit, String codEsp) {
        return (String) em.createNativeQuery("SELECT estado FROM public.v_historial_citas WHERE cod_cit = :cit AND cod_esp = :esp")
                .setParameter("cit", codCit).setParameter("esp", codEsp).getSingleResult();
    }

    private List<Bitacora> audit(String codEsp) {
        return em.createQuery("FROM Bitacora WHERE nombreTabla = 'especialidades' AND claveRegistro = :esp ORDER BY secuencial",
                Bitacora.class).setParameter("esp", codEsp).getResultList();
    }

    private String adminToken() {
        return login(createUser(em, "ROL004"), "ROL004");
    }

    private static String create(String token, String nombre) {
        return authenticated(token).body(Map.of("nombreEspecialidad", nombre, "duracionConsultaMin", 30))
                .post(ESPECIALIDADES).then().statusCode(201).extract().path("codEsp");
    }

    private static String createDoctor(String token, Map<String, Object> doctor) {
        return authenticated(token).body(doctor).post(MEDICOS).then().statusCode(201).extract().path("codMed");
    }

    private static Map<String, Object> doctorData(String codEsp) {
        return new HashMap<>(Map.of("nombreCompleto", "Médico especialidad", "email", UUID.randomUUID() + "@example.test",
                "password", UUID.randomUUID().toString(), "matriculaProfesional", UUID.randomUUID().toString(),
                "duracionConsultaMin", 30, "codigosEspecialidades", List.of(codEsp)));
    }

    private static Map<String, Object> link(List<Map<String, Object>> links, String codMed) {
        return links.stream().filter(row -> codMed.equals(row.get("codMed"))).findFirst().orElseThrow();
    }

    private static void expectValidation(RequestSpecification request, String method, String path) {
        request.request(method, path).then().statusCode(400).body("error", equalTo("VALIDACION"));
    }

    private static RequestSpecification withActorHeaders(String token, String actor) {
        return authenticated(token).header("X-Actor-Cod-Usu", actor).header("X-Actor-Rol", "ROL004");
    }

    private static String newName() {
        return "Especialidad " + UUID.randomUUID();
    }
}
