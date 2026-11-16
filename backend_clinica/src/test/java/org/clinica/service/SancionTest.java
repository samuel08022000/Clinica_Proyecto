package org.clinica.service;

import com.fasterxml.jackson.databind.node.IntNode;
import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.config.LogConfig;
import io.restassured.config.RestAssuredConfig;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import org.clinica.config.DbActorHelper;
import org.clinica.dto.sancion.SancionDtos.CrearSancion;
import org.clinica.dto.recepcion.RecepcionDtos.CheckinSolicitud;
import org.clinica.entity.Bitacora;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.service.PersonalTestFixtures.Account;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;
import org.postgresql.util.PSQLException;

import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.time.Duration;
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
class SancionTest {
    private static final String ADMIN = "/api/v1/administracion/pacientes";
    private static final String RECEPCION = "/api/v1/secretaria";
    @Inject EntityManager em;
    @Inject SancionService sanciones;
    @Inject CitaFunctionRepository funciones;
    @Inject AtencionCheckinService checkin;
    @Inject DbActorHelper actores;

    @Test
    void administratorCreatesAttributedSanctionAndReadsBoundedAdministrativeViews() {
        Patient p = patient();
        Account admin = createUser(em, "ROL004");
        String bearer = login(admin, "ROL004");
        String otherAdmin = createUser(em, "ROL004").id();
        var result = request(bearer).header("X-Actor-Cod-Usu", otherAdmin)
                .body(Map.of("motivo", "  Incumplimientos revisados  ", "dias", 3)).post(url(p) + "/sanciones")
                .then().statusCode(201).header("Cache-Control", containsString("no-store"))
                .body("tipoIncidencia", equalTo("SANCION_BLOQUEO")).body("codPac", equalTo(p.code()))
                .body("motivo", equalTo("Incumplimientos revisados")).body("registradoPorUsu", equalTo(admin.id()))
                .body("vigente", equalTo(true)).extract().response();
        String inc = result.path("codInc");
        assertTrue(result.header("Location").endsWith(url(p) + "/sanciones/" + inc));
        OffsetDateTime start = OffsetDateTime.parse(result.path("fechaRegistro"));
        OffsetDateTime end = OffsetDateTime.parse(result.path("fechaFinSancion"));
        assertEquals(Duration.ofDays(3), Duration.between(start, end));
        assertEquals(Set.of("codInc", "codPac", "codCit", "tipoIncidencia", "motivo", "registradoPorUsu", "fechaRegistro", "fechaFinSancion", "vigente"), result.jsonPath().getMap("$").keySet());
        request(bearer).get(url(p)).then().statusCode(200).body("estadoCuenta", equalTo("Bloqueado_Temporal"))
                .body("habilitadoParaReservar", equalTo(false)).body("motivoInhabilitacion", equalTo("PACIENTE_BLOQUEADO"));
        request(bearer).queryParam("q", p.account().email()).queryParam("estado", "Bloqueado_Temporal").get(ADMIN).then().statusCode(200)
                .body("pacientes.codPac", equalTo(List.of(p.code()))).body("size", equalTo(20));
        request(bearer).get(url(p) + "/incidencias").then().statusCode(200).body("incidencias.codInc", equalTo(List.of(inc)));
        request(bearer).queryParam("vigente", "true").get(url(p) + "/sanciones").then().statusCode(200)
                .body("incidencias.codInc", equalTo(List.of(inc)));
        request(bearer).get(url(p) + "/sanciones/" + inc).then().statusCode(200).body("vigente", equalTo(true));
        var audits = audits("incidencias_sanciones", inc);
        assertEquals(1, audits.size());
        assertEquals(admin.id(), audits.getFirst().codUsu);
        assertEquals("SANCION_BLOQUEO", audits.getFirst().datosNuevos.get("tipo_incidencia"));
        assertEquals(p.code(), audits.getFirst().datosNuevos.get("cod_pac"));
        assertEquals(admin.id(), audits("pacientes", p.code()).getLast().codUsu);
        assertFalse(result.asString().contains("password"));
    }

    @ParameterizedTest
    @ValueSource(strings = {"Web", "Ventanilla"})
    void activeSanctionBlocksBothReservationChannelsAndPreservesTheOriginalSlot(String channel) {
        Patient p = patient();
        Appointment f = appointment(p, 1);
        funciones.cancelarCita(f.code(), p.account().id(), "Liberar turno de prueba");
        Account secretary = createUser(em, "ROL002");
        String receptionBearer = login(secretary, "ROL002");
        apply(p, createUser(em, "ROL004"), 2);
        if (channel.equals("Web")) {
            assertSqlState("P4030", () -> funciones.reservarCita(p.code(), f.slot(), p.account().id(), "Web", null));
        } else {
            request(receptionBearer).body(Map.of("codPac", p.code(), "codSlot", f.slot())).post(RECEPCION + "/citas")
                    .then().statusCode(403).body("error", equalTo("PACIENTE_BLOQUEADO"));
        }
        assertEquals("0", scalar("SELECT count(*)::text FROM public.citas WHERE cod_slot=:id AND estado='Confirmada'", f.slot()));
        assertEquals("Cancelada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", f.code()));
        assertEquals("Disponible", scalar("SELECT estado_disponibilidad FROM public.v_agenda_disponibilidad WHERE cod_slot=:id", f.slot()));
        request(receptionBearer).get(RECEPCION + "/pacientes/" + p.code()).then().statusCode(200).body("habilitadoParaReservar", equalTo(false));
        request(null).body(Map.of("email", p.account().email(), "password", p.account().password()))
                .post("/api/v1/auth/login").then().statusCode(423).body("error.code", equalTo("ACCOUNT_TEMPORARILY_BLOCKED"));
    }

    @Test
    void sanctionKeepsExistingAppointmentsCheckinsCancellationsAndNoShows() {
        Patient p = patient();
        Account secretary = createUser(em, "ROL002");
        String bearer = login(secretary, "ROL002");
        Appointment missed = appointment(p, -1);
        funciones.registrarInasistencia(missed.code(), secretary.id());
        Appointment arrived = appointment(p, 0);
        var qr = new CheckinSolicitud(); qr.tokenQr = scalar("SELECT token_qr FROM public.citas WHERE cod_cit=:id", arrived.code());
        checkin.registrar(qr, secretary.id());
        Appointment cancelled = appointment(p, 1);
        funciones.cancelarCita(cancelled.code(), secretary.id(), "Cancelación administrativa previa");
        Appointment future = appointment(p, 1);
        String futureQr = scalar("SELECT token_qr FROM public.citas WHERE cod_cit=:id", future.code());
        long historyBefore = citaAuditCount(p);
        apply(p, createUser(em, "ROL004"), 2);
        assertEquals(historyBefore, citaAuditCount(p));
        assertEquals("No Asistió", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", missed.code()));
        assertEquals("En Espera", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", arrived.code()));
        assertEquals("Cancelada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", cancelled.code()));
        assertEquals("Confirmada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", future.code()));
        assertTrue(futureQr.equals(scalar("SELECT token_qr FROM public.citas WHERE cod_cit=:id", future.code())));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_pac=:id AND tipo_incidencia='INASISTENCIA_NO_SHOW'", p.code()));
        request(bearer).get(RECEPCION + "/pacientes/" + p.code() + "/historial").then().statusCode(200).body("citas.size()", equalTo(4));
    }

    @ParameterizedTest
    @ValueSource(strings = {"Web", "Ventanilla"})
    void expiryRestoresEligibilityAndLoginWithoutWaitingForScheduler(String channel) {
        Patient p = patient();
        Appointment f = appointment(p, 1);
        funciones.cancelarCita(f.code(), p.account().id(), "Liberar turno");
        Account admin = createUser(em, "ROL004");
        String adminBearer = login(admin, "ROL004");
        String secretaryBearer = login(createUser(em, "ROL002"), "ROL002");
        String inc = shortSanction(p, admin);
        assertEquals("Bloqueado_Temporal", scalar("SELECT public.estado_reserva_paciente(:id)", p.code()));
        waitUntilExpired(inc);
        assertEquals("Bloqueado_Temporal", scalar("SELECT estado_cuenta FROM public.pacientes WHERE cod_pac=:id", p.code()));
        request(adminBearer).get(url(p)).then().statusCode(200).body("estadoCuenta", equalTo("Activo"))
                .body("habilitadoParaReservar", equalTo(true));
        request(adminBearer).get(url(p) + "/sanciones/" + inc).then().statusCode(200).body("vigente", equalTo(false));
        request(adminBearer).queryParam("vigente", "false").get(url(p) + "/sanciones").then().statusCode(200)
                .body("incidencias.codInc", equalTo(List.of(inc)));
        login(p.account(), "ROL001");
        if (channel.equals("Web")) assertNotNull(funciones.reservarCita(p.code(), f.slot(), p.account().id(), "Web", null));
        else request(secretaryBearer).body(Map.of("codPac", p.code(), "codSlot", f.slot())).post(RECEPCION + "/citas").then().statusCode(201);
        assertEquals("1", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_inc=:id", inc));
    }

    @Test
    void expiryWorkerAndReservationRemainConsistentAndDoNotReactivateInactiveProfiles() throws Exception {
        Patient p = patient();
        Appointment f = appointment(p, 1);
        funciones.cancelarCita(f.code(), p.account().id(), "Liberar turno");
        Account admin = createUser(em, "ROL004");
        String inc = shortSanction(p, admin);
        waitUntilExpired(inc);
        List<Integer> result = race(() -> { funciones.desbloquearSancionesVencidas(); return 200; }, () -> {
            funciones.reservarCita(p.code(), f.slot(), p.account().id(), "Web", null); return 201;
        });
        assertEquals(List.of(200, 201), result);
        assertEquals("Activo", scalar("SELECT estado_cuenta FROM public.pacientes WHERE cod_pac=:id", p.code()));
        assertNull(audits("pacientes", p.code()).getLast().codUsu);
        assertEquals("1", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_inc=:id", inc));

        Patient inactive = patient();
        String old = shortSanction(inactive, admin);
        waitUntilExpired(old);
        mutate("UPDATE public.pacientes SET estado_cuenta='Inactivo' WHERE cod_pac=:id", inactive.code());
        funciones.desbloquearSancionesVencidas();
        assertEquals("Inactivo", scalar("SELECT public.estado_reserva_paciente(:id)", inactive.code()));
        request(login(admin, "ROL004")).body(Map.of("motivo", "Intento sobre inactivo", "dias", 1))
                .post(url(inactive) + "/sanciones").then().statusCode(409);
    }

    @Test
    void duplicateSanctionsAndInvalidBlockedStatePreserveIntegrity() {
        Patient p = patient();
        Account admin = createUser(em, "ROL004");
        String bearer = login(admin, "ROL004");
        apply(p, admin, 1);
        request(bearer).body(Map.of("motivo", "Otra sanción", "dias", 5)).post(url(p) + "/sanciones").then().statusCode(409)
                .body("error", equalTo("CONFLICTO_SANCION"));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_pac=:id", p.code()));
        Patient invalid = patient();
        assertSqlState("23514", () -> mutate("UPDATE public.pacientes SET estado_cuenta='Bloqueado_Temporal',bloqueado_hasta=NULL WHERE cod_pac=:id", invalid.code()));
        assertEquals("Activo", scalar("SELECT estado_cuenta FROM public.pacientes WHERE cod_pac=:id", invalid.code()));
        assertEquals("0", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_pac=:id", invalid.code()));
    }

    @Test
    void invalidBodiesCannotSupplyActorDatesRolesOrNonIntegralDurations() {
        Patient p = patient();
        String bearer = login(createUser(em, "ROL004"), "ROL004");
        for (var body : List.of(Map.of(), Map.of("dias", 1), Map.of("motivo", "Motivo"),
                Map.of("motivo", "  ", "dias", 1), Map.of("motivo", "a".repeat(2001), "dias", 1))) {
            request(bearer).body(body).post(url(p) + "/sanciones").then().statusCode(400);
        }
        for (Object duration : List.of(0, -1, 366, 1.5, "3", true, "2026-12-01", 999999999999L)) {
            request(bearer).body(Map.of("motivo", "Motivo", "dias", duration)).post(url(p) + "/sanciones").then().statusCode(400);
        }
        request(bearer).body("null").post(url(p) + "/sanciones").then().statusCode(400);
        for (String field : List.of("codAdmin", "codUsu", "codRol", "estado", "codPac", "fechaRegistro", "fechaFin")) {
            request(bearer).body(Map.of("motivo", "Motivo", "dias", 1, field, "valor")).post(url(p) + "/sanciones").then().statusCode(400);
        }
        assertEquals("Activo", scalar("SELECT estado_cuenta FROM public.pacientes WHERE cod_pac=:id", p.code()));
        assertEquals("0", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_pac=:id", p.code()));
    }

    @Test
    void missingPatientsSanctionsAndMismatchedOwnershipReturnNotFound() {
        Patient p = patient();
        Patient other = patient();
        Account admin = createUser(em, "ROL004");
        String bearer = login(admin, "ROL004");
        String inc = apply(p, admin, 1);
        for (String suffix : List.of("", "/incidencias", "/sanciones")) {
            request(bearer).get(ADMIN + "/PAC999999" + suffix).then().statusCode(404);
        }
        request(bearer).body(Map.of("motivo", "Motivo", "dias", 1)).post(ADMIN + "/PAC999999/sanciones").then().statusCode(404);
        request(bearer).get(url(p) + "/sanciones/INC999999").then().statusCode(404);
        request(bearer).get(url(other) + "/sanciones/" + inc).then().statusCode(404);
    }

    @Test
    void failedTransactionRollsBackSanctionPatientAndAudit() {
        Patient p = patient();
        Account admin = createUser(em, "ROL004");
        long before = audits("pacientes", p.code()).size();
        CrearSancion body = new CrearSancion(); body.motivo = "Rollback de sanción"; body.leerDias(IntNode.valueOf(2));
        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            sanciones.registrar(p.code(), body, admin.id());
            throw new IllegalStateException("Rollback de prueba");
        }));
        assertEquals("Activo", scalar("SELECT estado_cuenta FROM public.pacientes WHERE cod_pac=:id", p.code()));
        assertEquals("0", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_pac=:id", p.code()));
        assertEquals(before, audits("pacientes", p.code()).size());
        assertNotNull(apply(p, admin, 1));
    }

    @Test
    void administrativeIncidencesAndPatientListsArePagedAndFilteredWithoutSecrets() {
        Patient p = patient();
        Account admin = createUser(em, "ROL004");
        String bearer = login(admin, "ROL004");
        QuarkusTransaction.requiringNew().run(() -> {
            for (int i = 0; i < 3; i++) em.createNativeQuery("INSERT INTO public.incidencias_sanciones(cod_pac,tipo_incidencia,motivo,registrado_por_usu) VALUES (:pac,'OTRA','Nota administrativa',:actor)")
                    .setParameter("pac", p.code()).setParameter("actor", admin.id()).executeUpdate();
        });
        String sanction = apply(p, admin, 1);
        var first = request(bearer).queryParam("size", 2).get(url(p) + "/incidencias").then().statusCode(200)
                .body("hayMas", equalTo(true)).extract().jsonPath().getList("incidencias.codInc", String.class);
        var second = request(bearer).queryParam("size", 2).queryParam("page", 1).get(url(p) + "/incidencias").then().statusCode(200)
                .body("hayMas", equalTo(false)).extract().jsonPath().getList("incidencias.codInc", String.class);
        assertEquals(4, java.util.stream.Stream.concat(first.stream(), second.stream()).distinct().count());
        assertEquals(sanction, first.getFirst());
        request(bearer).queryParam("tipo", "OTRA").get(url(p) + "/incidencias").then().statusCode(200).body("incidencias.size()", equalTo(3));
        request(bearer).get(url(p) + "/sanciones").then().statusCode(200).body("incidencias.size()", equalTo(1));
        request(bearer).queryParam("size", 1).get(ADMIN).then().statusCode(200).body("pacientes.size()", equalTo(1)).body("hayMas", equalTo(true));
        request(bearer).queryParam("q", p.account().email()).get(ADMIN).then().statusCode(200).body("pacientes.codPac", equalTo(List.of(p.code())));
    }

    @ParameterizedTest
    @CsvSource({"page,-1", "page,10001", "page,abc", "size,0", "size,51", "size,999999999999", "estado,Otro", "q,ab"})
    void invalidPatientFiltersReturnBadRequest(String parameter, String value) {
        request(login(createUser(em, "ROL004"), "ROL004")).queryParam(parameter, value).get(ADMIN).then().statusCode(400);
    }

    @Test
    void invalidHistoryFiltersAndReadOnlyHistoryCannotModifySanctions() {
        Patient p = patient();
        Account admin = createUser(em, "ROL004");
        String bearer = login(admin, "ROL004");
        String inc = apply(p, admin, 1);
        request(bearer).queryParam("vigente", "quizas").get(url(p) + "/sanciones").then().statusCode(400);
        request(bearer).queryParam("tipo", "TIPO_INVENTADO").get(url(p) + "/incidencias").then().statusCode(400);
        request(bearer).queryParam("size", 51).get(url(p) + "/sanciones").then().statusCode(400);
        assertThrows(PersistenceException.class, () -> mutate("UPDATE public.incidencias_sanciones SET motivo='Ocultar falta' WHERE cod_inc=:id", inc));
        request(bearer).body(Map.of()).post(url(p) + "/sanciones/" + inc + "/levantar").then().statusCode(404);
        assertEquals("1", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_inc=:id", inc));
    }

    @Test
    void twoAdministratorsCannotCreateOverlappingSanctions() throws Exception {
        Patient p = patient();
        Account one = createUser(em, "ROL004"), two = createUser(em, "ROL004");
        String first = login(one, "ROL004"), second = login(two, "ROL004");
        List<Integer> result = race(() -> post(first, p).statusCode(), () -> post(second, p).statusCode());
        assertEquals(List.of(201, 409), result.stream().sorted().toList());
        assertEquals("1", scalar("SELECT count(*)::text FROM public.incidencias_sanciones WHERE cod_pac=:id AND tipo_incidencia='SANCION_BLOQUEO'", p.code()));
        String actor = scalar("SELECT registrado_por_usu FROM public.incidencias_sanciones WHERE cod_pac=:id", p.code());
        assertEquals(result.getFirst() == 201 ? one.id() : two.id(), actor);
        assertEquals(actor, audits("pacientes", p.code()).getLast().codUsu);
    }

    @ParameterizedTest
    @ValueSource(strings = {"Web", "Ventanilla"})
    void sanctionAndReservationSerializeOnTheSamePatient(String channel) throws Exception {
        Patient p = patient();
        Appointment f = appointment(p, 1);
        funciones.cancelarCita(f.code(), p.account().id(), "Liberar turno");
        String admin = login(createUser(em, "ROL004"), "ROL004");
        String secretary = login(createUser(em, "ROL002"), "ROL002");
        List<Integer> result = race(() -> post(admin, p).statusCode(), () -> {
            if (channel.equals("Ventanilla")) return request(secretary).body(Map.of("codPac", p.code(), "codSlot", f.slot())).post(RECEPCION + "/citas").statusCode();
            try { funciones.reservarCita(p.code(), f.slot(), p.account().id(), "Web", null); return 201; }
            catch (PersistenceException exception) { assertEquals("P4030", sqlState(exception)); return 403; }
        });
        assertEquals(201, result.getFirst());
        assertTrue(Set.of(201, 403).contains(result.get(1)));
        assertEquals(result.get(1) == 201 ? "1" : "0", scalar("SELECT count(*)::text FROM public.citas WHERE cod_slot=:id AND estado='Confirmada'", f.slot()));
        assertSqlState("P4030", () -> funciones.reservarCita(p.code(), f.slot(), p.account().id(), "Web", null));
        request(secretary).body(Map.of("codPac", p.code(), "codSlot", f.slot())).post(RECEPCION + "/citas").then().statusCode(403);
    }

    @ParameterizedTest
    @CsvSource({"GET,", "GET,/PAC999999", "GET,/PAC999999/incidencias", "GET,/PAC999999/sanciones", "GET,/PAC999999/sanciones/INC999999", "POST,/PAC999999/sanciones"})
    void administrativeRoutesRejectMissingAndInvalidSessions(String method, String suffix) {
        for (String bearer : List.of("", UUID.randomUUID().toString())) {
            request(bearer.isEmpty() ? null : bearer).header("X-Actor-Rol", "ROL004").header("X-Actor-Cod-Usu", "USU999999")
                    .body(Map.of("motivo", "Motivo", "dias", 1)).request(method, ADMIN + (suffix == null ? "" : suffix)).then().statusCode(401);
        }
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL002", "ROL003"})
    void patientSecretaryAndDoctorCannotManageSanctionsEvenWithSpoofedHeaders(String role) {
        String bearer = login(createUser(em, role), role);
        for (String path : List.of("", "/PAC999999", "/PAC999999/incidencias", "/PAC999999/sanciones", "/PAC999999/sanciones/INC999999")) {
            request(bearer).header("X-Actor-Rol", "ROL004").get(ADMIN + path).then().statusCode(403);
        }
        request(bearer).header("X-Actor-Rol", "ROL004").body(Map.of("motivo", "Motivo", "dias", 1))
                .post(ADMIN + "/PAC999999/sanciones").then().statusCode(403);
    }

    private Patient patient() {
        Account account = createUser(em, "ROL001");
        String code = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:id").setParameter("id", account.id()).getSingleResult();
        return new Patient(code, account);
    }

    private Appointment appointment(Patient p, int offset) {
        Account doctor = createUser(em, "ROL003");
        String specialty = createSpecialty(em);
        return QuarkusTransaction.requiringNew().call(() -> {
            String med = (String) em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu=:id").setParameter("id", doctor.id()).getSingleResult();
            em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)").setParameter("med", med).setParameter("esp", specialty).executeUpdate();
            em.createNativeQuery("INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin) VALUES (:med,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),TIME '08:00',TIME '10:00')").setParameter("med", med).executeUpdate();
            String slot = (String) em.createNativeQuery("SELECT cod_slot FROM public.slots_agenda WHERE cod_med=:med AND fecha=public.fecha_clinica()+1 ORDER BY inicio_at LIMIT 1").setParameter("med", med).getSingleResult();
            String code = funciones.reservarCita(p.code(), slot, p.account().id(), "Web", null);
            if (offset != 1) {
                // Reservas históricas propias, manteniendo triggers y restricciones activos.
                LocalDate date = ((LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult()).plusDays(offset);
                em.createNativeQuery("UPDATE public.slots_agenda SET inicio_at=CAST(:date AS date)+hora_inicio,fin_at=CAST(:date AS date)+hora_fin WHERE cod_slot=:slot").setParameter("date", date).setParameter("slot", slot).executeUpdate();
                em.createNativeQuery("UPDATE public.citas SET fecha=:date,token_qr=public.crear_token_qr(cod_cit,CAST(:date AS date)) WHERE cod_cit=:id").setParameter("date", date).setParameter("id", code).executeUpdate();
            }
            return new Appointment(code, slot);
        });
    }

    private String apply(Patient p, Account admin, int days) { return funciones.registrarSancion(p.code(), admin.id(), "Sanción administrativa revisada", days); }
    private String shortSanction(Patient p, Account admin) {
        // Periodo breve admitido por el esquema, para probar el vencimiento real sin editar incidencias inmutables.
        // La API mantiene exclusivamente duraciones enteras de 1..365 días.
        return QuarkusTransaction.requiringNew().call(() -> {
            actores.fijarActor(admin.id());
            return (String) em.createNativeQuery("INSERT INTO public.incidencias_sanciones(cod_pac,registrado_por_usu,tipo_incidencia,motivo,fecha_fin_sancion) VALUES (:pac,:actor,'SANCION_BLOQUEO','Periodo breve de prueba',clock_timestamp()+interval '4 seconds') RETURNING cod_inc")
                    .setParameter("pac", p.code()).setParameter("actor", admin.id()).getSingleResult();
        });
    }
    private void waitUntilExpired(String code) {
        em.createNativeQuery("SELECT 1 FROM pg_sleep(CAST(GREATEST(0,(SELECT extract(epoch FROM (fecha_fin_sancion-clock_timestamp())) FROM public.incidencias_sanciones WHERE cod_inc=:id))+0.05 AS double precision))")
                .setParameter("id", code).getSingleResult();
    }
    private List<Bitacora> audits(String table, String id) { return em.createQuery("FROM Bitacora WHERE nombreTabla=:tabla AND claveRegistro=:id ORDER BY secuencial", Bitacora.class).setParameter("tabla", table).setParameter("id", id).getResultList(); }
    private long citaAuditCount(Patient p) { return ((Number) em.createNativeQuery("SELECT count(*) FROM public.bitacora b JOIN public.citas c ON c.cod_cit=b.clave_registro WHERE b.nombre_tabla='citas' AND c.cod_pac=:id").setParameter("id", p.code()).getSingleResult()).longValue(); }
    private String scalar(String sql, String id) { return (String) em.createNativeQuery(sql).setParameter("id", id).getSingleResult(); }
    private void mutate(String sql, String id) { QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery(sql).setParameter("id", id).executeUpdate()); }
    private static String url(Patient p) { return ADMIN + "/" + p.code(); }
    private static io.restassured.response.Response post(String bearer, Patient p) { return request(bearer).body(Map.of("motivo", "Sanción revisada", "dias", 1)).post(url(p) + "/sanciones"); }
    private static RequestSpecification request(String bearer) {
        var spec = given().contentType("application/json").accept("application/json").config(RestAssuredConfig.config().logConfig(LogConfig.logConfig()));
        return bearer == null ? spec : spec.header("Authorization", "Bearer " + bearer);
    }
    private static String sqlState(Throwable error) { for (Throwable t = error; t != null; t = t.getCause()) if (t instanceof PSQLException psql) return psql.getSQLState(); return null; }
    private static void assertSqlState(String expected, Runnable operation) { assertEquals(expected, sqlState(assertThrows(PersistenceException.class, operation::run))); }
    private static List<Integer> race(Callable<Integer> first, Callable<Integer> second) throws Exception {
        var gate = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var a = executor.submit(() -> { gate.await(10, TimeUnit.SECONDS); return first.call(); });
            var b = executor.submit(() -> { gate.await(10, TimeUnit.SECONDS); return second.call(); });
            return List.of(a.get(40, TimeUnit.SECONDS), b.get(40, TimeUnit.SECONDS));
        }
    }
    private record Patient(String code, Account account) { }
    private record Appointment(String code, String slot) { }
}
