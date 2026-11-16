package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.config.LogConfig;
import io.restassured.config.RestAssuredConfig;
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

import java.time.Instant;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import static io.restassured.RestAssured.given;
import static org.clinica.service.PersonalTestFixtures.*;
import static org.hamcrest.Matchers.*;
import static org.junit.jupiter.api.Assertions.*;

@QuarkusTest
class MedicoAtencionTest {
    private static final String ROOT = "/api/v1/medico";
    private static final String SECRETARIA = "/api/v1/secretaria";
    private static final Set<String> CAMPOS = Set.of("codCit", "codPac", "nombrePaciente", "codEsp",
            "nombreEspecialidad", "fecha", "horaInicio", "horaFin", "estado", "canalReserva",
            "horaLlegadaCheckin", "checkinPorUsu", "fechaAtencion", "atendidaPorUsu", "checkinRegistrado");

    @Inject EntityManager em;
    @Inject CitaFunctionRepository funciones;

    @Test
    void agendaIsChronologicalScopedBySessionAndReadOnly() {
        Fixture first = appointment();
        Fixture second = nextAppointment(first);
        Fixture foreign = appointment();
        String token = login(first.doctor(), "ROL003");
        long audits = auditCount(first);
        var response = request(token).queryParam("fecha", clinicDate().plusDays(1).toString())
                .queryParam("codMed", foreign.med()).header("X-Actor-Cod-Usu", foreign.doctor().id())
                .get(ROOT + "/agenda").then().statusCode(200).header("Cache-Control", containsString("no-store"))
                .body("codCit", equalTo(List.of(first.cit(), second.cit())))
                .body("estado", everyItem(equalTo("Confirmada")))
                .body("checkinRegistrado", everyItem(equalTo(false))).extract().jsonPath();
        assertEquals(CAMPOS, response.getMap("[0]").keySet());
        assertEquals(audits, auditCount(first));
        request(token).queryParam("fecha", clinicDate().plusDays(2).toString()).get(ROOT + "/agenda")
                .then().statusCode(200).body("size()", equalTo(0));
        rebase(second, clinicDate().plusDays(2));
        request(token).queryParam("fecha", clinicDate().plusDays(1).toString()).get(ROOT + "/agenda")
                .then().statusCode(200).body("codCit", equalTo(List.of(first.cit())));
        request(token).queryParam("fecha", clinicDate().plusDays(2).toString()).get(ROOT + "/agenda")
                .then().statusCode(200).body("codCit", equalTo(List.of(second.cit())));
    }

    @Test
    void agendaDefaultsToClinicDateAndEmptyHistoryIsACollection() {
        Fixture f = appointment();
        String token = login(f.doctor(), "ROL003");
        request(token).get(ROOT + "/agenda").then().statusCode(200).body("size()", equalTo(0));
        rebase(f, clinicDate());
        request(token).get(ROOT + "/agenda").then().statusCode(200).body("codCit", equalTo(List.of(f.cit())));
        request(token).get(ROOT + "/historial").then().statusCode(200)
                .body("citas", empty()).body("page", equalTo(0)).body("size", equalTo(20)).body("hayMas", equalTo(false));
    }

    @ParameterizedTest
    @ValueSource(strings = {"2026-02-30", "2026-2-01", "texto", "0000-01-01", "", "2026-01-01T10:00:00"})
    void invalidDatesHaveControlledErrors(String date) {
        String token = login(createUser(em, "ROL003"), "ROL003");
        request(token).queryParam("fecha", date).get(ROOT + "/agenda").then().statusCode(400)
                .body("ok", equalTo(false)).body("error", equalTo("VALIDACION"));
        request(token).queryParam("desde", date).get(ROOT + "/historial").then().statusCode(400);
    }

    @Test
    void detailOnlyExposesAdministrativeFieldsAndRejectsForeignOrMissingAppointments() {
        Fixture f = appointment(), foreign = appointment();
        String token = login(f.doctor(), "ROL003");
        var detail = request(token).get(path(f)).then().statusCode(200)
                .body("codCit", equalTo(f.cit())).body("codPac", equalTo(f.pac()))
                .body("codEsp", equalTo(f.esp())).body("nombrePaciente", not(emptyOrNullString()))
                .extract().jsonPath().getMap("$");
        assertEquals(CAMPOS, detail.keySet());
        for (String route : List.of(path(foreign), ROOT + "/citas/CIT99999999")) {
            request(token).get(route).then().statusCode(404).body("error", equalTo("NO_ENCONTRADO"));
            request(token).post(route + "/atender").then().statusCode(404);
        }
        request(token).get(ROOT + "/citas/incorrecto!").then().statusCode(400);
    }

    @Test
    void secretaryCheckinThenDoctorClosureShareTheSameRecordsAndAudit() {
        Fixture f = appointment();
        rebase(f, clinicDate());
        String doctor = login(f.doctor(), "ROL003");
        Account secretary = createUser(em, "ROL002");
        String reception = login(secretary, "ROL002");
        checkin(f, reception);
        request(doctor).get(ROOT + "/agenda").then().statusCode(200)
                .body("[0].estado", equalTo("En Espera")).body("[0].checkinRegistrado", equalTo(true))
                .body("[0].checkinPorUsu", equalTo(secretary.id()));
        Instant start = Instant.now();
        var attended = request(doctor).header("X-Actor-Cod-Usu", secretary.id()).body(Map.of())
                .post(path(f) + "/atender").then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("estado", equalTo("Atendida")).body("atendidaPorUsu", equalTo(f.doctor().id()))
                .body("checkinPorUsu", equalTo(secretary.id())).body("checkinRegistrado", equalTo(true))
                .extract().jsonPath();
        OffsetDateTime timestamp = OffsetDateTime.parse(attended.getString("fechaAtencion"));
        assertFalse(timestamp.toInstant().isBefore(start.minusSeconds(1)));
        assertFalse(timestamp.toInstant().isAfter(Instant.now().plusSeconds(1)));
        assertEquals(f.doctor().id(), scalar("SELECT atendida_por_usu FROM public.citas WHERE cod_cit=:cit", f));
        assertEquals(timestamp.toInstant(), ((OffsetDateTime) em.createNativeQuery(
                "SELECT fecha_atencion FROM public.citas WHERE cod_cit=:cit", OffsetDateTime.class)
                .setParameter("cit", f.cit()).getSingleResult()).toInstant());
        var audit = transitions(f);
        assertEquals(1, audit.size());
        assertEquals(f.doctor().id(), audit.getFirst().codUsu);
        assertEquals("En Espera", audit.getFirst().datosAnteriores.get("estado"));
        assertEquals(f.doctor().id(), audit.getFirst().datosNuevos.get("atendida_por_usu"));
        assertNotNull(audit.getFirst().datosNuevos.get("fecha_atencion"));
        request(reception).get(SECRETARIA + "/citas/" + f.cit()).then().statusCode(200).body("estado", equalTo("Atendida"));
        request(reception).queryParam("fecha", clinicDate().toString()).queryParam("codMed", f.med())
                .get(SECRETARIA + "/citas").then().statusCode(200)
                .body("citas[0].fechaAtencion", notNullValue()).body("citas[0].estado", equalTo("Atendida"));
        request(doctor).post(path(f) + "/atender").then().statusCode(409).body("error", equalTo("ATENCION_NO_PERMITIDA"));
        request(doctor).get(path(f)).then().statusCode(200).body("fechaAtencion", equalTo(attended.getString("fechaAtencion")))
                .body("atendidaPorUsu", equalTo(f.doctor().id()));
        assertEquals(1, transitions(f).size());
    }

    @ParameterizedTest
    @ValueSource(strings = {"Confirmada", "Cancelada", "No Asistió", "Afectada/Pendiente Reprogramación", "Reprogramada", "En Espera"})
    void invalidStatesOrIncompleteCheckinCannotBeClosed(String state) {
        Fixture f = appointment();
        switch (state) {
            case "Cancelada" -> funciones.cancelarCita(f.cit(), createUser(em, "ROL002").id(), "Cancelación de prueba");
            case "No Asistió" -> {
                rebase(f, clinicDate().minusDays(1));
                funciones.registrarInasistencia(f.cit(), createUser(em, "ROL002").id());
            }
            case "En Espera" -> {
                // La base actual impide persistir En Espera sin ambos datos de check-in.
                // Comprueba esa defensa sin desactivar restricciones ni triggers.
                assertThrows(RuntimeException.class, () -> QuarkusTransaction.requiringNew().run(() ->
                        em.createNativeQuery("UPDATE public.citas SET estado='En Espera' WHERE cod_cit=:cit")
                                .setParameter("cit", f.cit()).executeUpdate()));
            }
            case "Afectada/Pendiente Reprogramación", "Reprogramada" -> QuarkusTransaction.requiringNew().run(() ->
                    em.createNativeQuery("UPDATE public.citas SET estado=:state WHERE cod_cit=:cit")
                            .setParameter("state", state).setParameter("cit", f.cit()).executeUpdate());
            default -> { }
        }
        long audits = auditCount(f);
        request(login(f.doctor(), "ROL003")).post(path(f) + "/atender").then().statusCode(409);
        assertEquals(state.equals("En Espera") ? "Confirmada" : state, scalar("SELECT estado FROM public.citas WHERE cod_cit=:cit", f));
        assertNull(scalar("SELECT atendida_por_usu FROM public.citas WHERE cod_cit=:cit", f));
        assertNull(scalar("SELECT fecha_atencion::text FROM public.citas WHERE cod_cit=:cit", f));
        assertEquals(audits, auditCount(f));
    }

    @Test
    void clientCannotChooseResponsibleOrTimestamp() {
        Fixture f = appointment();
        rebase(f, clinicDate());
        checkin(f, login(createUser(em, "ROL002"), "ROL002"));
        String token = login(f.doctor(), "ROL003");
        for (String field : List.of("atendidaPorUsu", "fechaAtencion", "codMed", "estado")) {
            request(token).body(Map.of(field, "arbitrario")).post(path(f) + "/atender").then().statusCode(400);
        }
        assertEquals("En Espera", scalar("SELECT estado FROM public.citas WHERE cod_cit=:cit", f));
        assertTrue(transitions(f).isEmpty());
    }

    @Test
    void concurrentClosuresHaveExactlyOneWinnerWithoutOverwritingStamps() throws Exception {
        Fixture f = appointment();
        rebase(f, clinicDate());
        checkin(f, login(createUser(em, "ROL002"), "ROL002"));
        String token = login(f.doctor(), "ROL003");
        var gate = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var one = executor.submit(() -> { gate.await(10, TimeUnit.SECONDS); return request(token).post(path(f) + "/atender").statusCode(); });
            var two = executor.submit(() -> { gate.await(10, TimeUnit.SECONDS); return request(token).post(path(f) + "/atender").statusCode(); });
            assertEquals(List.of(200, 409), List.of(one.get(40, TimeUnit.SECONDS), two.get(40, TimeUnit.SECONDS)).stream().sorted().toList());
        }
        assertEquals(1, transitions(f).size());
        assertEquals(f.doctor().id(), scalar("SELECT atendida_por_usu FROM public.citas WHERE cod_cit=:cit", f));
        String stamp = scalar("SELECT fecha_atencion::text FROM public.citas WHERE cod_cit=:cit", f);
        request(token).post(path(f) + "/atender").then().statusCode(409);
        assertEquals(stamp, scalar("SELECT fecha_atencion::text FROM public.citas WHERE cod_cit=:cit", f));
        assertEquals(1, transitions(f).size());
    }

    @Test
    void historyIsOwnAttendedOnlyPaginatedAndDateFiltered() {
        Fixture first = appointment(), foreign = appointment();
        Fixture second = nextAppointment(first);
        Fixture pending = nextAppointment(first);
        String secretary = login(createUser(em, "ROL002"), "ROL002");
        String token = login(first.doctor(), "ROL003");
        for (Fixture f : List.of(first, second, foreign)) {
            rebase(f, clinicDate());
            checkin(f, secretary);
            String bearer = f == foreign ? login(f.doctor(), "ROL003") : token;
            request(bearer).post(path(f) + "/atender").then().statusCode(200);
        }
        request(token).queryParam("codMed", foreign.med()).get(ROOT + "/historial").then().statusCode(200)
                .body("citas.codCit", equalTo(List.of(second.cit(), first.cit())))
                .body("citas.estado", everyItem(equalTo("Atendida")));
        request(token).queryParam("size", 1).get(ROOT + "/historial").then().statusCode(200)
                .body("citas.codCit", equalTo(List.of(second.cit()))).body("hayMas", equalTo(true));
        request(token).queryParam("size", 1).queryParam("page", 1).get(ROOT + "/historial").then().statusCode(200)
                .body("citas.codCit", equalTo(List.of(first.cit()))).body("hayMas", equalTo(false));
        request(token).queryParam("page", 2).get(ROOT + "/historial").then().statusCode(200).body("citas", empty());
        request(token).queryParam("desde", clinicDate().plusDays(1).toString()).get(ROOT + "/historial")
                .then().statusCode(200).body("citas", empty());
        request(token).queryParam("desde", clinicDate().toString()).queryParam("hasta", clinicDate().toString())
                .get(ROOT + "/historial").then().statusCode(200).body("citas.codCit", equalTo(List.of(second.cit(), first.cit())));
        assertEquals("Confirmada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:cit", pending));
    }

    @ParameterizedTest
    @CsvSource({"page,-1", "page,10001", "page,no", "size,0", "size,51", "size,999999999999"})
    void invalidPaginationIsRejected(String param, String value) {
        request(login(createUser(em, "ROL003"), "ROL003")).queryParam(param, value).get(ROOT + "/historial")
                .then().statusCode(400);
    }

    @Test
    void reversedHistoryRangeIsRejected() {
        request(login(createUser(em, "ROL003"), "ROL003")).queryParam("desde", "2026-02-02")
                .queryParam("hasta", "2026-02-01").get(ROOT + "/historial").then().statusCode(400);
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL002", "ROL004"})
    void otherRolesCannotUseMedicalEndpointsEvenWithForgedHeaders(String role) {
        assertRoutesDenied(login(createUser(em, role), role), 403);
    }

    @Test
    void missingInvalidAndRevokedTokensAreRejected() {
        assertRoutesDenied(null, 401);
        assertRoutesDenied(UUID.randomUUID().toString(), 401);
        String token = login(createUser(em, "ROL003"), "ROL003");
        request(token).post("/api/v1/auth/logout").then().statusCode(200);
        assertRoutesDenied(token, 401);
    }

    @ParameterizedTest
    @ValueSource(strings = {"usuario", "perfil"})
    void inactiveAccountOrMedicalProfileIsRejectedAfterLogin(String target) {
        Fixture f = appointment();
        String token = login(f.doctor(), "ROL003");
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery(target.equals("usuario")
                ? "UPDATE public.usuarios SET estado='Inactivo' WHERE cod_usu=:id"
                : "UPDATE public.medicos SET estado='Inactivo' WHERE cod_usu=:id")
                .setParameter("id", f.doctor().id()).executeUpdate());
        request(token).post(path(f) + "/atender").then().statusCode(403);
        assertEquals("Confirmada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:cit", f));
    }

    private void assertRoutesDenied(String token, int status) {
        for (String route : List.of("/agenda", "/historial", "/citas/CIT99999999")) {
            request(token).header("X-Actor-Rol", "ROL003").header("X-Actor-Cod-Usu", "USU999999")
                    .get(ROOT + route).then().statusCode(status);
        }
        request(token).header("X-Actor-Rol", "ROL003").body(Map.of())
                .post(ROOT + "/citas/CIT99999999/atender").then().statusCode(status);
    }

    private Fixture appointment() {
        Account doctor = createUser(em, "ROL003");
        String esp = createSpecialty(em);
        String med = QuarkusTransaction.requiringNew().call(() -> {
            String id = (String) em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu=:usu")
                    .setParameter("usu", doctor.id()).getSingleResult();
            em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)")
                    .setParameter("med", id).setParameter("esp", esp).executeUpdate();
            em.createNativeQuery("""
                    INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin)
                    VALUES (:med,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),TIME '08:00',TIME '10:00')
                    """).setParameter("med", id).executeUpdate();
            return id;
        });
        return reserve(doctor, med, esp);
    }

    private Fixture nextAppointment(Fixture f) { return reserve(f.doctor(), f.med(), f.esp()); }

    private Fixture reserve(Account doctor, String med, String esp) {
        Account patient = createUser(em, "ROL001");
        return QuarkusTransaction.requiringNew().call(() -> {
            String pac = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:usu")
                    .setParameter("usu", patient.id()).getSingleResult();
            String slot = (String) em.createNativeQuery("""
                    SELECT s.cod_slot FROM public.slots_agenda s WHERE s.cod_med=:med AND s.fecha=public.fecha_clinica()+1
                    AND NOT EXISTS (SELECT 1 FROM public.citas c WHERE c.cod_slot=s.cod_slot)
                    ORDER BY s.inicio_at LIMIT 1
                    """).setParameter("med", med).getSingleResult();
            String cit = funciones.reservarCita(pac, slot, patient.id(), "Web", null);
            return new Fixture(cit, slot, med, esp, pac, doctor);
        });
    }

    private void rebase(Fixture f, LocalDate date) {
        // Representa citas preexistentes solo en PostgreSQL aislado; conserva triggers y restricciones.
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("UPDATE public.slots_agenda SET inicio_at=CAST(:date AS date)+hora_inicio,fin_at=CAST(:date AS date)+hora_fin WHERE cod_slot=:slot")
                    .setParameter("date", date).setParameter("slot", f.slot()).executeUpdate();
            em.createNativeQuery("UPDATE public.citas SET fecha=:date,token_qr=public.crear_token_qr(cod_cit,CAST(:date AS date)) WHERE cod_cit=:cit")
                    .setParameter("date", date).setParameter("cit", f.cit()).executeUpdate();
        });
    }

    private void checkin(Fixture f, String secretary) {
        String qr = scalar("SELECT token_qr FROM public.citas WHERE cod_cit=:cit", f);
        request(secretary).body(Map.of("tokenQr", qr)).post(SECRETARIA + "/checkin").then().statusCode(200)
                .body("estado", equalTo("En Espera")).body("checkinRegistrado", equalTo(true));
    }

    private List<Bitacora> transitions(Fixture f) {
        return em.createQuery("FROM Bitacora WHERE nombreTabla='citas' AND claveRegistro=:cit AND accion='UPDATE'", Bitacora.class)
                .setParameter("cit", f.cit()).getResultList().stream().filter(b -> "Atendida".equals(b.datosNuevos.get("estado"))).toList();
    }

    private long auditCount(Fixture f) {
        return ((Number) em.createNativeQuery("SELECT count(*) FROM public.bitacora WHERE nombre_tabla='citas' AND clave_registro=:cit")
                .setParameter("cit", f.cit()).getSingleResult()).longValue();
    }

    private String scalar(String sql, Fixture f) { return (String) em.createNativeQuery(sql).setParameter("cit", f.cit()).getSingleResult(); }
    private LocalDate clinicDate() { return (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult(); }
    private static String path(Fixture f) { return ROOT + "/citas/" + f.cit(); }
    private static RequestSpecification request(String token) {
        var spec = given().contentType("application/json").accept("application/json")
                .config(RestAssuredConfig.config().logConfig(LogConfig.logConfig()));
        return token == null ? spec : spec.header("Authorization", "Bearer " + token);
    }
    private record Fixture(String cit, String slot, String med, String esp, String pac, Account doctor) { }
}
