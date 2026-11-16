package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.config.LogConfig;
import io.restassured.config.RestAssuredConfig;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.agenda.AgendaDtos.BloqueoSolicitud;
import org.clinica.dto.recepcion.RecepcionDtos.*;
import org.clinica.entity.Bitacora;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.scheduler.ClinicaDaemonScheduler;
import org.clinica.scheduler.DaemonTestAccess;
import org.clinica.service.PersonalTestFixtures.Account;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.LocalTime;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.Callable;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import static io.restassured.RestAssured.given;
import static org.clinica.service.PersonalTestFixtures.*;
import static org.hamcrest.Matchers.*;
import static org.junit.jupiter.api.Assertions.*;

@QuarkusTest
class GestionCitaTest {
    private static final String ROOT = "/api/v1/secretaria";
    @Inject EntityManager em;
    @Inject CitaFunctionRepository funciones;
    @Inject GestionCitaService gestion;
    @Inject AtencionCheckinService checkin;
    @Inject AgendaHorarioService agenda;
    @Inject DbActorHelper actores;
    @Inject ClinicaDaemonScheduler daemon;

    @Test
    void cancellationRecordsActorReasonTimeIncidentAndReleasesCentralAvailability() {
        Account secretary = createUser(em, "ROL002");
        String bearer = login(secretary, "ROL002");
        Fixture f = fixture("FUTURE");
        request(bearer).header("X-Actor-Cod-Usu", createUser(em, "ROL004").id())
                .body(Map.of("motivo", "  Solicitado por paciente  ")).post(path(f) + "/cancelar").then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("estado", equalTo("Cancelada")).body("cancelada", equalTo(true))
                .body("motivoCancelacion", equalTo("Solicitado por paciente"))
                .body("canceladoPorUsu", equalTo(secretary.id())).body("canceladoEn", notNullValue());
        assertEquals(1, incidents(f, "CANCELACION"));
        assertEquals(secretary.id(), incidentActor(f, "CANCELACION"));
        var audit = transitions(f, "Cancelada");
        assertEquals(1, audit.size());
        assertEquals(secretary.id(), audit.getFirst().codUsu);
        assertEquals("Confirmada", audit.getFirst().datosAnteriores.get("estado"));
        assertEquals("Solicitado por paciente", audit.getFirst().datosNuevos.get("motivo_cancelacion"));
        assertEquals("Disponible", availability(f));
        request(bearer).get(path(f)).then().statusCode(200).body("qrDisponible", equalTo(true));
        request(bearer).queryParam("estado", "Cancelada").get(history(f)).then().statusCode(200)
                .body("citas[0].motivoCancelacion", equalTo("Solicitado por paciente"))
                .body("citas[0].canceladoPorUsu", equalTo(secretary.id()));
        String time = scalar("SELECT cancelado_en::text FROM public.citas WHERE cod_cit=:cit", f.code());
        cancel(bearer, f).then().statusCode(409);
        assertEquals(time, scalar("SELECT cancelado_en::text FROM public.citas WHERE cod_cit=:cit", f.code()));
        assertEquals(1, transitions(f, "Cancelada").size());
    }

    @ParameterizedTest
    @ValueSource(strings = {"BLOCKED", "DOCTOR_INACTIVE", "SPECIALTY_INACTIVE", "RETIRED", "OUTSIDE_WINDOW", "PAST"})
    void cancellationCannotOfferSlotsThatRemainUnavailable(String condition) {
        Fixture f = fixture("FUTURE");
        String bearer = secretaryToken();
        switch (condition) {
            case "BLOCKED" -> block(f);
            case "DOCTOR_INACTIVE" -> mutate("UPDATE public.medicos SET estado='Inactivo' WHERE cod_med=:id", f.doctor());
            case "SPECIALTY_INACTIVE" -> mutate("UPDATE public.especialidades SET estado='Inactivo' WHERE cod_esp=:id", f.specialty());
            case "RETIRED" -> mutate("UPDATE public.especialidades SET duracion_consulta_min=20 WHERE cod_esp=:id", f.specialty());
            case "OUTSIDE_WINDOW" -> rebase(f, clinicDate().plusDays(15), LocalTime.of(8, 0), LocalTime.of(8, 30));
            case "PAST" -> rebase(f, clinicDate().minusDays(1), LocalTime.of(8, 0), LocalTime.of(8, 30));
            default -> fail("Condición desconocida");
        }
        cancel(bearer, f).then().statusCode(200);
        assertNotEquals("Disponible", availability(f));
        assertEquals(1, incidents(f, "CANCELACION"));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.slots_agenda WHERE cod_slot=(SELECT cod_slot FROM public.citas WHERE cod_cit=:cit)", f.code()));
        if (condition.equals("BLOCKED")) {
            assertEquals("1", scalar("SELECT count(*)::text FROM public.bloqueos_citas_afectadas WHERE cod_cit=:cit", f.code()));
        }
    }

    @ParameterizedTest
    @ValueSource(strings = {"En Espera", "Atendida", "No Asistió", "Reprogramada"})
    void closedOrArrivedAppointmentsCannotBeCancelled(String state) {
        Account secretary = createUser(em, "ROL002");
        Fixture f = fixture(state.equals("No Asistió") ? "EXPIRED" : state.equals("Reprogramada") ? "FUTURE" : "TODAY");
        changeState(f, state, secretary);
        long before = auditCount(f);
        cancel(login(secretary, "ROL002"), f).then().statusCode(409);
        assertEquals(state, state(f));
        assertEquals(before, auditCount(f));
        assertEquals(0, incidents(f, "CANCELACION"));
    }

    @Test
    void cancellationAndNoShowValidateRequestsAndMissingResources() {
        String bearer = secretaryToken();
        Fixture f = fixture("FUTURE");
        for (var body : List.of(Map.of(), Map.of("motivo", "   "), Map.of("motivo", "a".repeat(2001)))) {
            request(bearer).body(body).post(path(f) + "/cancelar").then().statusCode(400);
        }
        request(bearer).body("null").post(path(f) + "/cancelar").then().statusCode(400);
        for (String field : List.of("codSec", "codUsu", "codRol", "estado", "codCit", "canceladoEn")) {
            request(bearer).body(Map.of("motivo", "Motivo", field, "valor")).post(path(f) + "/cancelar").then().statusCode(400);
            request(bearer).body(Map.of(field, "valor")).post(path(f) + "/inasistencia").then().statusCode(400);
        }
        request(bearer).body(Map.of("motivo", "Motivo")).post(ROOT + "/citas/CIT99999999/cancelar").then().statusCode(404);
        request(bearer).body(Map.of()).post(ROOT + "/citas/CIT99999999/inasistencia").then().statusCode(404);
        request(bearer).get(ROOT + "/pacientes/PAC999999/historial").then().statusCode(404);
        assertEquals("Confirmada", state(f));
        assertEquals(0, incidents(f, "CANCELACION"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"CANCEL", "NO_SHOW"})
    void failedOuterTransactionRollsBackStateIncidentAndAudit(String operation) {
        Account secretary = createUser(em, "ROL002");
        Fixture f = fixture(operation.equals("NO_SHOW") ? "EXPIRED" : "FUTURE");
        long before = auditCount(f);
        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            if (operation.equals("CANCEL")) gestion.cancelar(f.code(), reason(), secretary.id());
            else gestion.registrarInasistencia(f.code(), null, secretary.id());
            throw new IllegalStateException("Rollback de prueba");
        }));
        assertEquals("Confirmada", state(f));
        assertEquals(before, auditCount(f));
        assertEquals(0, incidents(f, operation.equals("CANCEL") ? "CANCELACION" : "INASISTENCIA_NO_SHOW"));
        String bearer = login(secretary, "ROL002");
        if (operation.equals("CANCEL")) cancel(bearer, f).then().statusCode(200);
        else noShow(bearer, f).then().statusCode(200);
    }

    @Test
    void noShowUsesTurnEndClinicalTimeAndCreatesExactlyOneAttributedIncident() {
        Fixture f = fixture("EXPIRED");
        Account secretary = createUser(em, "ROL002");
        String bearer = login(secretary, "ROL002");
        request(bearer).header("X-Actor-Cod-Usu", createUser(em, "ROL002").id()).body(Map.of())
                .post(path(f) + "/inasistencia").then().statusCode(200)
                .body("estado", equalTo("No Asistió")).body("inasistenciaRegistrada", equalTo(true))
                .body("registradoPorUsu", equalTo(secretary.id())).body("codInc", notNullValue())
                .body("fechaRegistro", notNullValue());
        assertEquals(1, incidents(f, "INASISTENCIA_NO_SHOW"));
        assertEquals(secretary.id(), incidentActor(f, "INASISTENCIA_NO_SHOW"));
        assertEquals(secretary.id(), transitions(f, "No Asistió").getFirst().codUsu);
        noShow(bearer, f).then().statusCode(409);
        assertEquals(1, incidents(f, "INASISTENCIA_NO_SHOW"));
        request(bearer).get(history(f)).then().statusCode(200)
                .body("citas[0].inasistenciaPorUsu", equalTo(secretary.id()))
                .body("citas[0].motivoInasistencia", notNullValue());
    }

    @ParameterizedTest
    @ValueSource(strings = {"FUTURE", "En Espera", "Atendida", "Cancelada", "Afectada/Pendiente Reprogramación"})
    void noShowRejectsEarlyArrivedAndIncompatibleStates(String condition) {
        Account secretary = createUser(em, "ROL002");
        Fixture f = fixture(condition.equals("FUTURE") ? "FUTURE" : condition.equals("En Espera") || condition.equals("Atendida") ? "TODAY_EXPIRED" : "EXPIRED");
        if (condition.equals("Afectada/Pendiente Reprogramación")) block(f);
        else if (!condition.equals("FUTURE")) changeState(f, condition, secretary);
        long before = auditCount(f);
        noShow(login(secretary, "ROL002"), f).then().statusCode(409);
        assertEquals(before, auditCount(f));
        assertEquals(0, incidents(f, "INASISTENCIA_NO_SHOW"));
    }

    @Test
    void realDaemonClearsInheritedActorAndDoesNotDuplicateManualIncidents() {
        Fixture automatic = fixture("EXPIRED");
        Fixture manual = fixture("EXPIRED");
        Account secretary = createUser(em, "ROL002");
        gestion.registrarInasistencia(manual.code(), null, secretary.id());
        QuarkusTransaction.requiringNew().run(() -> {
            actores.fijarActor(secretary.id());
            em.createNativeQuery("SET LOCAL TIME ZONE 'Asia/Tokyo'").executeUpdate();
            DaemonTestAccess.ejecutar(daemon);
        });
        assertEquals("No Asistió", state(automatic));
        assertEquals(1, incidents(automatic, "INASISTENCIA_NO_SHOW"));
        assertNull(incidentActor(automatic, "INASISTENCIA_NO_SHOW"));
        assertNull(transitions(automatic, "No Asistió").getFirst().codUsu);
        assertEquals(1, incidents(manual, "INASISTENCIA_NO_SHOW"));
        assertEquals(secretary.id(), incidentActor(manual, "INASISTENCIA_NO_SHOW"));
    }

    @Test
    void patientOwnershipRemainsEnforcedByExistingSqlCancellationContract() {
        Fixture f = fixture("FUTURE");
        Account other = createUser(em, "ROL001");
        assertThrows(PersistenceException.class, () -> funciones.cancelarCita(f.code(), other.id(), "Solicitud ajena"));
        assertEquals("Confirmada", state(f));
        assertTrue(funciones.cancelarCita(f.code(), f.patient().id(), "Solicitud propia"));
        assertEquals(f.patient().id(), incidentActor(f, "CANCELACION"));
    }

    @Test
    void historyFiltersAndPaginationKeepInactiveProfilesAndHistoricalSlotVersions() {
        Account patient = createUser(em, "ROL001");
        Account secretary = createUser(em, "ROL002");
        String bearer = login(secretary, "ROL002");
        Fixture old = fixture("EXPIRED", patient);
        Fixture current = fixture("TODAY", patient);
        Fixture future = fixture("FUTURE", patient);
        gestion.registrarInasistencia(old.code(), null, secretary.id());
        changeState(current, "Atendida", secretary);
        gestion.cancelar(future.code(), reason(), secretary.id());
        mutate("UPDATE public.especialidades SET duracion_consulta_min=20 WHERE cod_esp=:id", future.specialty());
        mutate("UPDATE public.medicos SET estado='Inactivo' WHERE cod_med=:id", future.doctor());
        mutate("UPDATE public.especialidades SET estado='Inactivo' WHERE cod_esp=:id", future.specialty());
        mutate("UPDATE public.pacientes SET estado_cuenta='Bloqueado_Temporal',bloqueado_hasta=now()+interval '1 day' WHERE cod_pac=:id", future.patientCode());
        String url = history(future);
        var response = request(bearer).queryParam("size", 2).get(url).then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("page", equalTo(0)).body("size", equalTo(2)).body("hayMas", equalTo(true)).extract().jsonPath();
        assertEquals(List.of(old.code(), current.code()), response.getList("citas.codCit"));
        request(bearer).queryParam("size", 2).queryParam("page", 1).get(url).then().statusCode(200)
                .body("hayMas", equalTo(false)).body("citas.codCit", equalTo(List.of(future.code())));
        request(bearer).queryParam("desde", clinicDate().toString()).queryParam("hasta", clinicDate().toString()).get(url).then().statusCode(200)
                .body("citas.codCit", equalTo(List.of(current.code())));
        request(bearer).queryParam("estado", "Cancelada").get(url).then().statusCode(200)
                .body("citas.codCit", equalTo(List.of(future.code())))
                .body("citas[0].motivoCancelacion", equalTo("Solicitud del paciente"));
        request(bearer).queryParam("fecha", clinicDate().plusDays(1).toString()).queryParam("codPac", future.patientCode())
                .queryParam("codMed", future.doctor()).queryParam("estado", "Cancelada").get(ROOT + "/citas").then().statusCode(200)
                .body("citas.codCit", equalTo(List.of(future.code())));
        request(bearer).get(ROOT + "/citas").then().statusCode(200).body("citas.fecha", everyItem(equalTo(clinicDate().toString())));
        Set<String> allowed = Set.of("codCit", "codPac", "nombrePaciente", "codMed", "nombreMedico", "codEsp", "nombreEspecialidad",
                "fecha", "horaInicio", "horaFin", "estado", "canalReserva", "motivoCancelacion", "canceladoPorUsu", "canceladoEn",
                "horaLlegadaCheckin", "checkinPorUsu", "fechaAtencion", "codIncInasistencia", "inasistenciaRegistradaEn", "inasistenciaPorUsu", "motivoInasistencia");
        assertEquals(allowed, response.getMap("citas[0]").keySet());
        assertEquals("1", scalar("SELECT count(*)::text FROM public.slots_agenda WHERE cod_slot=(SELECT cod_slot FROM public.citas WHERE cod_cit=:cit)", future.code()));
    }

    @ParameterizedTest
    @CsvSource({"page,-1", "page,10001", "page,abc", "size,999999999999", "size,0", "size,51", "estado,Otro", "desde,incorrecto", "hasta,2026-02-30", "hasta,0000-01-01"})
    void invalidHistoryFiltersAreRejected(String name, String value) {
        request(secretaryToken()).queryParam(name, value).get(ROOT + "/citas").then().statusCode(400);
    }

    @Test
    void conflictingRangesAndUnknownFilterResourcesAreRejected() {
        String bearer = secretaryToken();
        request(bearer).queryParam("desde", "2026-02-02").queryParam("hasta", "2026-02-01").get(ROOT + "/citas").then().statusCode(400);
        request(bearer).queryParam("fecha", "2026-02-01").queryParam("desde", "2026-02-01").get(ROOT + "/citas").then().statusCode(400);
        request(bearer).queryParam("codPac", "PAC999999").get(ROOT + "/citas").then().statusCode(404);
        request(bearer).queryParam("codMed", "MED999999").get(ROOT + "/citas").then().statusCode(404);
    }

    @Test
    void paginationIsStableWhenHistoricalAppointmentsHaveTheSameDateAndTime() {
        Fixture f = fixture("FUTURE");
        Account secretary = createUser(em, "ROL002");
        funciones.cancelarCita(f.code(), secretary.id(), "Primera cancelación");
        String second = funciones.reservarCita(f.patientCode(), f.slot(), f.patient().id(), "Web", null);
        funciones.cancelarCita(second, secretary.id(), "Segunda cancelación");
        String bearer = login(secretary, "ROL002");
        List<String> expected = List.of(f.code(), second).stream().sorted().toList();
        for (int page = 0; page < 2; page++) {
            request(bearer).queryParam("size", 1).queryParam("page", page).get(history(f)).then().statusCode(200)
                    .body("citas.codCit", equalTo(List.of(expected.get(page)))).body("hayMas", equalTo(page == 0));
        }
    }

    @Test
    void concurrentCancellationsHaveOneTransitionAndOneIncident() throws Exception {
        Fixture f = fixture("FUTURE");
        Account one = createUser(em, "ROL002");
        Account two = createUser(em, "ROL002");
        String first = login(one, "ROL002");
        String second = login(two, "ROL002");
        List<Integer> statuses = race(() -> cancel(first, f).statusCode(), () -> cancel(second, f).statusCode());
        assertEquals(List.of(200, 409), statuses.stream().sorted().toList());
        assertEquals(1, incidents(f, "CANCELACION"));
        assertEquals(1, transitions(f, "Cancelada").size());
        assertEquals(statuses.getFirst() == 200 ? one.id() : two.id(), incidentActor(f, "CANCELACION"));
        assertEquals(incidentActor(f, "CANCELACION"), transitions(f, "Cancelada").getFirst().codUsu);
    }

    @Test
    void cancellationAndReservationCompeteOnTheCentralSlot() throws Exception {
        Fixture f = fixture("FUTURE");
        String first = secretaryToken();
        String second = secretaryToken();
        Account patient = createUser(em, "ROL001");
        String patientCode = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:usu")
                .setParameter("usu", patient.id()).getSingleResult();
        List<Integer> result = race(() -> cancel(first, f).statusCode(), () -> request(second)
                .body(Map.of("codPac", patientCode, "codSlot", f.slot())).post(ROOT + "/citas").statusCode());
        assertEquals(200, result.getFirst());
        assertTrue(Set.of(201, 409).contains(result.get(1)));
        if (result.get(1) == 409) {
            request(second).body(Map.of("codPac", patientCode, "codSlot", f.slot())).post(ROOT + "/citas").then().statusCode(201);
        }
        assertEquals("1", scalar("SELECT count(*)::text FROM public.citas WHERE cod_slot=(SELECT cod_slot FROM public.citas WHERE cod_cit=:cit) AND estado NOT IN ('Cancelada','Reprogramada')", f.code()));
        assertEquals("Cancelada", state(f));
    }

    @ParameterizedTest
    @ValueSource(strings = {"CANCEL", "NO_SHOW"})
    void checkinAndClosureCannotProduceIncompatibleStates(String action) throws Exception {
        Fixture f = fixture(action.equals("CANCEL") ? "TODAY" : "TODAY_EXPIRED");
        String one = secretaryToken();
        String two = secretaryToken();
        String tokenQr = qr(f);
        request(one).get(path(f) + "/acreditacion").then().statusCode(200).body("admiteCheckin", equalTo(true));
        List<Integer> result = race(() -> action.equals("CANCEL") ? cancel(one, f).statusCode() : noShow(one, f).statusCode(),
                () -> request(two).body(Map.of("tokenQr", tokenQr)).post(ROOT + "/checkin").statusCode());
        assertEquals(List.of(200, 409), result.stream().sorted().toList());
        if (result.get(1) == 200) {
            assertEquals("En Espera", state(f));
            assertEquals(0, incidents(f, action.equals("CANCEL") ? "CANCELACION" : "INASISTENCIA_NO_SHOW"));
        } else {
            assertEquals(action.equals("CANCEL") ? "Cancelada" : "No Asistió", state(f));
            assertEquals(1, incidents(f, action.equals("CANCEL") ? "CANCELACION" : "INASISTENCIA_NO_SHOW"));
            assertTrue(transitions(f, "En Espera").isEmpty());
        }
    }

    @Test
    void manualAndRealAutomaticJobCannotDuplicateIncidents() throws Exception {
        Fixture f = fixture("EXPIRED");
        String bearer = secretaryToken();
        List<Integer> result = race(() -> noShow(bearer, f).statusCode(), () -> {
            QuarkusTransaction.requiringNew().run(() -> DaemonTestAccess.ejecutar(daemon));
            return 200;
        });
        assertTrue(Set.of(200, 409).contains(result.getFirst()));
        assertEquals(200, result.get(1));
        assertEquals("No Asistió", state(f));
        assertEquals(1, incidents(f, "INASISTENCIA_NO_SHOW"));
        assertEquals(1, transitions(f, "No Asistió").size());
    }

    @ParameterizedTest
    @CsvSource({"GET,/citas", "GET,/pacientes/PAC999999/historial", "POST,/citas/CIT99999999/cancelar", "POST,/citas/CIT99999999/inasistencia"})
    void routesRequireValidBearerEvenWithForgedHeaders(String method, String route) {
        for (String token : List.of("", UUID.randomUUID().toString())) {
            request(token.isEmpty() ? null : token).header("X-Actor-Rol", "ROL002").header("X-Actor-Cod-Usu", "USU999999")
                    .body(Map.of()).request(method, ROOT + route).then().statusCode(401);
        }
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL003", "ROL004"})
    void otherRolesCannotUseAnyReceptionManagementRoute(String role) {
        String bearer = login(createUser(em, role), role);
        for (String route : List.of("/citas", "/pacientes/PAC999999/historial")) {
            request(bearer).header("X-Actor-Rol", "ROL002").get(ROOT + route).then().statusCode(403);
        }
        for (String route : List.of("cancelar", "inasistencia")) {
            request(bearer).header("X-Actor-Rol", "ROL002").body(Map.of()).post(ROOT + "/citas/CIT99999999/" + route).then().statusCode(403);
        }
    }

    private Fixture fixture(String timing) { return fixture(timing, createUser(em, "ROL001")); }

    private Fixture fixture(String timing, Account patient) {
        Account doctor = createUser(em, "ROL003");
        String specialty = createSpecialty(em);
        Fixture f = QuarkusTransaction.requiringNew().call(() -> {
            String med = (String) em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu=:id").setParameter("id", doctor.id()).getSingleResult();
            String pac = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:id").setParameter("id", patient.id()).getSingleResult();
            em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)").setParameter("med", med).setParameter("esp", specialty).executeUpdate();
            em.createNativeQuery("""
                    INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin)
                    VALUES (:med,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),TIME '08:00',TIME '10:00')
                    """).setParameter("med", med).executeUpdate();
            String slot = (String) em.createNativeQuery("SELECT cod_slot FROM public.slots_agenda WHERE cod_med=:med AND fecha=public.fecha_clinica()+1 ORDER BY inicio_at LIMIT 1").setParameter("med", med).getSingleResult();
            String code = funciones.reservarCita(pac, slot, patient.id(), "Web", null);
            return new Fixture(code, slot, med, specialty, pac, patient, doctor);
        });
        if (!timing.equals("FUTURE")) {
            LocalDate day = clinicDate();
            LocalTime start = LocalTime.of(8, 0), end = LocalTime.of(8, 30);
            if (timing.equals("EXPIRED")) day = day.minusDays(1);
            if (timing.equals("TODAY_EXPIRED")) {
                LocalDateTime now = (LocalDateTime) em.createNativeQuery("SELECT public.ahora_clinica()", LocalDateTime.class).getSingleResult();
                end = now.toLocalTime().minusNanos(1_000_000);
                start = now.toLocalTime().isBefore(LocalTime.of(0, 31)) ? LocalTime.MIDNIGHT : end.minusMinutes(30);
            }
            rebase(f, day, start, end);
        }
        return f;
    }

    private void rebase(Fixture f, LocalDate date, LocalTime start, LocalTime end) {
        // Solo datos propios aislados: representa citas preexistentes para no esperar al reloj.
        // Mantiene todos los triggers y restricciones; nunca usa la base de desarrollo.
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("UPDATE public.slots_agenda SET inicio_at=CAST(:fecha AS date)+CAST(:inicio AS time),fin_at=CAST(:fecha AS date)+CAST(:fin AS time) WHERE cod_slot=:slot")
                    .setParameter("fecha", date).setParameter("inicio", start).setParameter("fin", end).setParameter("slot", f.slot()).executeUpdate();
            em.createNativeQuery("UPDATE public.citas SET fecha=:fecha,token_qr=public.crear_token_qr(cod_cit,CAST(:fecha AS date)) WHERE cod_cit=:cit")
                    .setParameter("fecha", date).setParameter("cit", f.code()).executeUpdate();
        });
    }

    private void changeState(Fixture f, String state, Account secretary) {
        switch (state) {
            case "Cancelada" -> funciones.cancelarCita(f.code(), secretary.id(), "Cancelada en fixture");
            case "No Asistió" -> funciones.registrarInasistencia(f.code(), secretary.id());
            case "En Espera", "Atendida" -> {
                var input = new CheckinSolicitud(); input.tokenQr = qr(f);
                checkin.registrar(input, secretary.id());
                if (state.equals("Atendida")) funciones.marcarCitaAtendida(f.code(), f.doctorUser().id());
            }
            case "Reprogramada" -> {
                String other = (String) em.createNativeQuery("SELECT cod_slot FROM public.slots_agenda WHERE cod_med=:med AND cod_slot<>:slot ORDER BY inicio_at LIMIT 1")
                        .setParameter("med", f.doctor()).setParameter("slot", f.slot()).getSingleResult();
                funciones.reprogramarCita(f.code(), other, secretary.id(), "Reprogramación de fixture");
            }
            default -> fail("Estado de fixture desconocido");
        }
    }

    private void block(Fixture f) {
        BloqueoSolicitud dto = new BloqueoSolicitud();
        dto.fechaBloqueo = scalar("SELECT fecha::text FROM public.citas WHERE cod_cit=:cit", f.code());
        dto.motivo = "Bloqueo de prueba";
        // El servicio administrativo impide fechas pasadas; un bloqueo histórico se modela en SQL.
        if (LocalDate.parse(dto.fechaBloqueo).isBefore(clinicDate())) {
            String admin = createUser(em, "ROL004").id();
            QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("INSERT INTO public.bloqueos_agenda(cod_med,cod_usu_admin,fecha_bloqueo,motivo) VALUES (:med,:actor,CAST(:fecha AS date),'Bloqueo histórico de prueba')")
                    .setParameter("med", f.doctor()).setParameter("actor", admin).setParameter("fecha", dto.fechaBloqueo).executeUpdate());
        } else agenda.crearBloqueo(f.doctor(), dto, createUser(em, "ROL004").id());
    }

    private List<Bitacora> transitions(Fixture f, String state) {
        return em.createQuery("FROM Bitacora WHERE nombreTabla='citas' AND claveRegistro=:cit AND accion='UPDATE'", Bitacora.class)
                .setParameter("cit", f.code()).getResultList().stream().filter(b -> state.equals(b.datosNuevos.get("estado"))).toList();
    }
    private long auditCount(Fixture f) { return ((Number) em.createNativeQuery("SELECT count(*) FROM public.bitacora WHERE nombre_tabla='citas' AND clave_registro=:cit").setParameter("cit", f.code()).getSingleResult()).longValue(); }
    private int incidents(Fixture f, String type) { return ((Number) em.createNativeQuery("SELECT count(*) FROM public.incidencias_sanciones WHERE cod_cit=:cit AND tipo_incidencia=:type").setParameter("cit", f.code()).setParameter("type", type).getSingleResult()).intValue(); }
    private String incidentActor(Fixture f, String type) { return (String) em.createNativeQuery("SELECT registrado_por_usu FROM public.incidencias_sanciones WHERE cod_cit=:cit AND tipo_incidencia=:type").setParameter("cit", f.code()).setParameter("type", type).getSingleResult(); }
    private String scalar(String sql, String code) { return (String) em.createNativeQuery(sql).setParameter("cit", code).getSingleResult(); }
    private String state(Fixture f) { return scalar("SELECT estado FROM public.citas WHERE cod_cit=:cit", f.code()); }
    private String qr(Fixture f) { return scalar("SELECT token_qr FROM public.citas WHERE cod_cit=:cit", f.code()); }
    private String availability(Fixture f) {
        return (String) em.createNativeQuery("SELECT COALESCE((SELECT estado_disponibilidad FROM public.v_agenda_disponibilidad WHERE cod_slot=:slot),'Fuera de ventana')")
                .setParameter("slot", f.slot()).getSingleResult();
    }
    private void mutate(String sql, String id) { QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery(sql).setParameter("id", id).executeUpdate()); }
    private LocalDate clinicDate() { return (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult(); }
    private String secretaryToken() { return login(createUser(em, "ROL002"), "ROL002"); }
    private static String path(Fixture f) { return ROOT + "/citas/" + f.code(); }
    private static String history(Fixture f) { return ROOT + "/pacientes/" + f.patientCode() + "/historial"; }
    private static CancelacionSolicitud reason() { var dto = new CancelacionSolicitud(); dto.motivo = "Solicitud del paciente"; return dto; }
    private static io.restassured.response.Response cancel(String bearer, Fixture f) { return request(bearer).body(Map.of("motivo", "Solicitud del paciente")).post(path(f) + "/cancelar"); }
    private static io.restassured.response.Response noShow(String bearer, Fixture f) { return request(bearer).body(Map.of()).post(path(f) + "/inasistencia"); }
    private static RequestSpecification request(String bearer) {
        var spec = given().contentType("application/json").accept("application/json").config(RestAssuredConfig.config().logConfig(LogConfig.logConfig()));
        return bearer == null ? spec : spec.header("Authorization", "Bearer " + bearer);
    }
    private static List<Integer> race(Callable<Integer> first, Callable<Integer> second) throws Exception {
        var gate = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var one = executor.submit(() -> { gate.await(10, TimeUnit.SECONDS); return first.call(); });
            var two = executor.submit(() -> { gate.await(10, TimeUnit.SECONDS); return second.call(); });
            return List.of(one.get(40, TimeUnit.SECONDS), two.get(40, TimeUnit.SECONDS));
        }
    }
    private record Fixture(String code, String slot, String doctor, String specialty, String patientCode, Account patient, Account doctorUser) { }
}
