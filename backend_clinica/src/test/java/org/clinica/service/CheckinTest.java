package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.config.LogConfig;
import io.restassured.config.RestAssuredConfig;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import org.clinica.dto.agenda.AgendaDtos.BloqueoSolicitud;
import org.clinica.dto.recepcion.RecepcionDtos.CheckinSolicitud;
import org.clinica.entity.Bitacora;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.service.PersonalTestFixtures.Account;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;

import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.LocalDate;
import java.time.OffsetDateTime;
import java.util.Base64;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import static io.restassured.RestAssured.given;
import static org.clinica.service.PersonalTestFixtures.createSpecialty;
import static org.clinica.service.PersonalTestFixtures.createUser;
import static org.clinica.service.PersonalTestFixtures.login;
import static org.hamcrest.Matchers.containsString;
import static org.hamcrest.Matchers.equalTo;
import static org.junit.jupiter.api.Assertions.*;

@QuarkusTest
class CheckinTest {
    private static final String RECEPCION = "/api/v1/secretaria";

    @Inject EntityManager em;
    @Inject CitaFunctionRepository funciones;
    @Inject AtencionCheckinService checkin;
    @Inject AgendaHorarioService agenda;

    @ParameterizedTest
    @ValueSource(strings = {"Web", "Ventanilla"})
    void authenticatedSecretaryChecksInExistingQrAndPreservesAdministrativeHistory(String canal) {
        Account secretary = createUser(em, "ROL002");
        String bearer = login(secretary, "ROL002");
        Fixture fixture = appointment(0, canal);
        long before = auditCount(fixture.code());
        var accreditation = request(bearer).get(path(fixture) + "/acreditacion").then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("qrValido", equalTo(true)).body("admiteCheckin", equalTo(true)).extract().jsonPath().getMap("$");
        assertEquals(Set.of("codCit", "nombrePaciente", "fecha", "estado", "qrValido", "admiteCheckin"), accreditation.keySet());
        request(bearer).get(path(fixture) + "/asistencia").then().statusCode(200)
                .body("checkinRegistrado", equalTo(false));
        assertEquals(before, auditCount(fixture.code()));

        String payload = new String(Base64.getMimeDecoder().decode(fixture.qr().split("\\.")[0]), StandardCharsets.UTF_8);
        String[] components = payload.split("\\|");
        assertEquals(3, components.length);
        assertTrue(components[0].equals(fixture.code()));
        assertTrue(components[1].equals(fixture.date().toString()));
        assertTrue(components[2].matches("[0-9a-f]{32}"));

        Instant started = Instant.now();
        var response = request(bearer).header("X-Actor-Cod-Usu", createUser(em, "ROL002").id())
                .body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin").then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("codCit", equalTo(fixture.code())).body("estado", equalTo("En Espera"))
                .body("checkinRegistrado", equalTo(true)).body("checkinPorUsu", equalTo(secretary.id()))
                .extract().jsonPath();
        Instant arrived = OffsetDateTime.parse(response.getString("horaLlegadaCheckin")).toInstant();
        assertFalse(arrived.isBefore(started.minusSeconds(1)));
        assertFalse(arrived.isAfter(Instant.now().plusSeconds(1)));
        assertFalse(response.getMap("$").containsKey("tokenQr"));
        request(bearer).get(path(fixture) + "/asistencia").then().statusCode(200)
                .body("horaLlegadaCheckin", equalTo(response.getString("horaLlegadaCheckin")));
        request(bearer).get(path(fixture) + "/acreditacion").then().statusCode(200)
                .body("qrValido", equalTo(true)).body("admiteCheckin", equalTo(false));
        request(bearer).get(path(fixture)).then().statusCode(200)
                .body("canalReserva", equalTo(canal)).body("estado", equalTo("En Espera"));
        var transitions = arrivalAudit(fixture.code());
        assertEquals(1, transitions.size());
        Bitacora record = transitions.getFirst();
        assertEquals(secretary.id(), record.codUsu);
        assertEquals("Confirmada", record.datosAnteriores.get("estado"));
        assertEquals("En Espera", record.datosNuevos.get("estado"));
        assertEquals(secretary.id(), record.datosNuevos.get("checkin_por_usu"));
        assertFalse(record.datosAnteriores.containsKey("token_qr"));
        assertFalse(record.datosNuevos.containsKey("token_qr"));
    }

    @Test
    void emptyMalformedManipulatedAndMismatchedQrAreRejectedWithoutTokenDisclosure() {
        String bearer = secretaryToken();
        Fixture fixture = appointment(0, "Web");
        String changedSignature = fixture.qr().substring(0, fixture.qr().length() - 1)
                + (fixture.qr().endsWith("0") ? "1" : "0");
        String differentPayload = Base64.getEncoder().encodeToString(
                ("CIT99999998|" + fixture.date() + "|" + UUID.randomUUID().toString().replace("-", "")).getBytes(StandardCharsets.UTF_8))
                + "." + fixture.qr().split("\\.")[1];
        long before = auditCount(fixture.code());
        for (String qr : List.of("", " ", fixture.code(), "%%%..bad", "bad." + "0".repeat(64),
                "A".repeat(4097), fixture.qr() + ".extra", changedSignature, differentPayload)) {
            var response = request(bearer).body(Map.of("tokenQr", qr)).post(RECEPCION + "/checkin").then().statusCode(400)
                    .extract().response();
            assertFalse(response.asString().contains(fixture.qr()));
            assertFalse(response.asString().contains("SQL"));
        }
        request(bearer).body(Map.of()).post(RECEPCION + "/checkin").then().statusCode(400);
        request(bearer).body("{\"tokenQr\":null}").post(RECEPCION + "/checkin").then().statusCode(400);
        assertEquals(before, auditCount(fixture.code()));
        assertEquals("Confirmada", status(fixture.code()));

        String wrongDate = signedQr(fixture.code(), fixture.date().plusDays(1));
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public.citas SET token_qr=:qr WHERE cod_cit=:cit")
                .setParameter("qr", wrongDate).setParameter("cit", fixture.code()).executeUpdate());
        request(bearer).get(path(fixture) + "/acreditacion").then().statusCode(200)
                .body("qrValido", equalTo(false)).body("admiteCheckin", equalTo(false));
        request(bearer).body(Map.of("tokenQr", wrongDate)).post(RECEPCION + "/checkin").then().statusCode(400);
        assertTrue(arrivalAudit(fixture.code()).isEmpty());
    }

    @Test
    void authenticQrForMissingAppointmentAndUnknownReadResourcesReturnNotFound() {
        String bearer = secretaryToken();
        String token = signedQr("CIT99999999", clinicDate());
        request(bearer).body(Map.of("tokenQr", token)).post(RECEPCION + "/checkin").then().statusCode(404)
                .body("error", equalTo("ACREDITACION_NO_ENCONTRADA"));
        for (String suffix : List.of("acreditacion", "asistencia")) {
            request(bearer).get(RECEPCION + "/citas/CIT99999999/" + suffix).then().statusCode(404);
        }
    }

    @ParameterizedTest
    @ValueSource(ints = {-1, 1})
    void authenticQrOutsideCurrentClinicalDayCannotRegisterArrival(int offset) {
        String bearer = secretaryToken();
        Fixture fixture = appointment(offset, "Web");
        request(bearer).get(path(fixture) + "/acreditacion").then().statusCode(200)
                .body("qrValido", equalTo(true)).body("admiteCheckin", equalTo(false));
        request(bearer).body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin").then().statusCode(409)
                .body("error", equalTo("CHECKIN_NO_PERMITIDO"));
        request(bearer).get(path(fixture) + "/asistencia").then().statusCode(200)
                .body("estado", equalTo("Confirmada")).body("checkinRegistrado", equalTo(false));
        assertTrue(arrivalAudit(fixture.code()).isEmpty());
    }

    @ParameterizedTest
    @ValueSource(strings = {"Cancelada", "Bloqueada", "BloqueoConEstadoLegado"})
    void incompatibleAppointmentStatesAndBlocksRejectArrival(String condition) {
        String bearer = secretaryToken();
        Fixture fixture = appointment(0, "Ventanilla");
        String qr = fixture.qr();
        if (condition.equals("Cancelada")) {
            funciones.cancelarCita(fixture.code(), fixture.patient().id(), "Cancelación de prueba");
        } else {
            String actor = createUser(em, "ROL004").id();
            BloqueoSolicitud block = new BloqueoSolicitud();
            block.fechaBloqueo = fixture.date().toString();
            block.motivo = "Ausencia de prueba";
            agenda.crearBloqueo(fixture.doctor(), block, actor);
            if (condition.equals("BloqueoConEstadoLegado")) {
                // Comprueba el bloqueo aun si una confirmación anterior dejó un estado contradictorio.
                QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public.citas SET estado='Confirmada' WHERE cod_cit=:cit")
                        .setParameter("cit", fixture.code()).executeUpdate());
                qr = currentQr(fixture.code());
            }
        }
        long before = auditCount(fixture.code());
        request(bearer).get(path(fixture) + "/acreditacion").then().statusCode(200).body("admiteCheckin", equalTo(false));
        request(bearer).body(Map.of("tokenQr", qr)).post(RECEPCION + "/checkin").then().statusCode(409);
        request(bearer).get(path(fixture) + "/asistencia").then().statusCode(200).body("checkinRegistrado", equalTo(false));
        assertEquals(before, auditCount(fixture.code()));
    }

    @Test
    void repeatedAndConcurrentCheckinsKeepOriginalArrivalAndWinningActor() throws Exception {
        Account one = createUser(em, "ROL002");
        Account two = createUser(em, "ROL002");
        String firstBearer = login(one, "ROL002");
        String secondBearer = login(two, "ROL002");
        Fixture fixture = appointment(0, "Web");
        var barrier = new CyclicBarrier(2);
        String arrival;
        String actor;
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var first = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return request(firstBearer).body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin");
            });
            var second = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return request(secondBearer).body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin");
            });
            var firstResult = first.get(20, TimeUnit.SECONDS);
            var secondResult = second.get(20, TimeUnit.SECONDS);
            assertEquals(List.of(200, 409), List.of(firstResult.statusCode(), secondResult.statusCode()).stream().sorted().toList());
            var winner = firstResult.statusCode() == 200 ? firstResult : secondResult;
            actor = firstResult.statusCode() == 200 ? one.id() : two.id();
            arrival = winner.path("horaLlegadaCheckin");
            assertEquals(actor, winner.<String>path("checkinPorUsu"));
        }
        request(firstBearer).body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin").then().statusCode(409);
        request(secondBearer).get(path(fixture) + "/asistencia").then().statusCode(200)
                .body("horaLlegadaCheckin", equalTo(arrival)).body("checkinPorUsu", equalTo(actor));
        var transitions = arrivalAudit(fixture.code());
        assertEquals(1, transitions.size());
        assertEquals(actor, transitions.getFirst().codUsu);
    }

    @Test
    void checkinAndAdministrativeBlockSerializeWithoutPartialArrivals() throws Exception {
        Account secretary = createUser(em, "ROL002");
        String bearer = login(secretary, "ROL002");
        String admin = createUser(em, "ROL004").id();
        Fixture fixture = appointment(0, "Ventanilla");
        var block = new BloqueoSolicitud();
        block.fechaBloqueo = fixture.date().toString();
        block.motivo = "Bloqueo concurrente de prueba";
        var barrier = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var arrival = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return request(bearer).body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin");
            });
            var blocking = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return agenda.crearBloqueo(fixture.doctor(), block, admin);
            });
            var response = arrival.get(20, TimeUnit.SECONDS);
            var registeredBlock = blocking.get(20, TimeUnit.SECONDS);
            assertTrue(registeredBlock.trazabilidadCompleta());
            if (response.statusCode() == 200) {
                assertEquals("En Espera", status(fixture.code()));
                assertEquals(0, registeredBlock.cantidadCitasAfectadas());
                assertEquals(1, arrivalAudit(fixture.code()).size());
                assertEquals(secretary.id(), arrivalAudit(fixture.code()).getFirst().codUsu);
            } else {
                assertEquals(409, response.statusCode());
                assertEquals("Afectada/Pendiente Reprogramación", status(fixture.code()));
                assertEquals(1, registeredBlock.cantidadCitasAfectadas());
                assertTrue(arrivalAudit(fixture.code()).isEmpty());
                request(bearer).get(path(fixture) + "/asistencia").then().statusCode(200)
                        .body("checkinRegistrado", equalTo(false));
            }
            assertEquals(1L, em.createQuery("SELECT count(h) FROM Horario h WHERE h.codMed=:med", Long.class)
                    .setParameter("med", fixture.doctor()).getSingleResult());
        }
    }

    @Test
    void failedOuterTransactionRollsBackArrivalAndAuditThenAllowsRetry() {
        Account secretary = createUser(em, "ROL002");
        Fixture fixture = appointment(0, "Web");
        long before = auditCount(fixture.code());
        var input = new CheckinSolicitud();
        input.tokenQr = fixture.qr();
        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            checkin.registrar(input, secretary.id());
            throw new IllegalStateException("Rollback de la llegada de prueba");
        }));
        assertEquals(before, auditCount(fixture.code()));
        assertEquals("Confirmada", status(fixture.code()));
        String bearer = login(secretary, "ROL002");
        request(bearer).get(path(fixture) + "/asistencia").then().statusCode(200).body("checkinRegistrado", equalTo(false));
        request(bearer).body(Map.of("tokenQr", fixture.qr())).post(RECEPCION + "/checkin").then().statusCode(200);
    }

    @Test
    void qrCannotOverrideAppointmentOrSecretaryNorActAsBearerAndRotatedQrIsRejected() {
        String bearer = secretaryToken();
        Fixture first = appointment(0, "Web");
        Fixture second = appointment(0, "Web");
        for (String field : List.of("codSec", "codCit", "rol", "codUsu", "checkinPorUsu")) {
            request(bearer).body(Map.of("tokenQr", second.qr(), field, first.code())).post(RECEPCION + "/checkin")
                    .then().statusCode(400);
        }
        request(first.qr()).get(path(first) + "/acreditacion").then().statusCode(401);
        request(bearer).body(Map.of("tokenQr", second.qr())).post(RECEPCION + "/checkin").then().statusCode(200)
                .body("codCit", equalTo(second.code()));
        assertEquals("Confirmada", status(first.code()));
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public.citas SET token_qr=public.crear_token_qr(cod_cit,fecha) WHERE cod_cit=:cit")
                .setParameter("cit", first.code()).executeUpdate());
        request(bearer).body(Map.of("tokenQr", first.qr())).post(RECEPCION + "/checkin").then().statusCode(404);
        request(bearer).body(Map.of("tokenQr", currentQr(first.code()))).post(RECEPCION + "/checkin").then().statusCode(200);
    }

    @ParameterizedTest
    @CsvSource({"GET,/citas/CIT99999999/acreditacion", "GET,/citas/CIT99999999/asistencia", "POST,/checkin"})
    void missingOrInvalidBearerCannotUseCheckinRoutes(String method, String route) {
        for (var input : List.of(request(null), request(UUID.randomUUID().toString()))) {
            if (method.equals("POST")) input.body(Map.of("tokenQr", "valor"));
            input.header("X-Actor-Rol", "ROL002").header("X-Actor-Cod-Usu", "USU999999")
                    .request(method, RECEPCION + route).then().statusCode(401);
        }
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL003", "ROL004"})
    void onlySecretariesMayAccreditEvenWithForgedRole(String role) {
        String bearer = login(createUser(em, role), role);
        request(bearer).header("X-Actor-Rol", "ROL002").body(Map.of("tokenQr", "valor"))
                .post(RECEPCION + "/checkin").then().statusCode(403);
        request(bearer).get(RECEPCION + "/citas/CIT99999999/acreditacion").then().statusCode(403);
        request(bearer).get(RECEPCION + "/citas/CIT99999999/asistencia").then().statusCode(403);
    }

    @Test
    void clinicalDateIsIndependentOfDatabaseSessionTimezone() {
        Account secretary = createUser(em, "ROL002");
        Fixture fixture = appointment(0, "Web");
        CheckinSolicitud input = new CheckinSolicitud();
        input.tokenQr = fixture.qr();
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("SET LOCAL TIME ZONE 'Asia/Tokyo'").executeUpdate();
            assertEquals("En Espera", checkin.registrar(input, secretary.id()).estado());
        });
        assertEquals(1, arrivalAudit(fixture.code()).size());
    }

    private Fixture appointment(int dayOffset, String channel) {
        Account patient = createUser(em, "ROL001");
        Account doctor = createUser(em, "ROL003");
        Account creator = channel.equals("Web") ? patient : createUser(em, "ROL002");
        String specialty = createSpecialty(em);
        LocalDate target = clinicDate().plusDays(dayOffset);
        return QuarkusTransaction.requiringNew().call(() -> {
            String med = (String) em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu=:usu")
                    .setParameter("usu", doctor.id()).getSingleResult();
            String pac = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:usu")
                    .setParameter("usu", patient.id()).getSingleResult();
            em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)")
                    .setParameter("med", med).setParameter("esp", specialty).executeUpdate();
            em.createNativeQuery("""
                    INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin)
                    VALUES (:med,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),TIME '08:00',TIME '10:00')
                    """).setParameter("med", med).executeUpdate();
            String slot = (String) em.createNativeQuery("""
                    SELECT cod_slot FROM public.slots_agenda WHERE cod_med=:med AND fecha=public.fecha_clinica()+1
                    ORDER BY inicio_at LIMIT 1
                    """).setParameter("med", med).getSingleResult();
            String code = funciones.reservarCita(pac, slot, creator.id(), channel, null);
            if (dayOffset != 1) {
                // Representa una reserva preexistente de hoy o de ayer, sin desactivar validaciones ni auditoría.
                em.createNativeQuery("""
                        UPDATE public.slots_agenda SET inicio_at=CAST(:fecha AS date)+hora_inicio,
                            fin_at=CAST(:fecha AS date)+hora_fin WHERE cod_slot=:slot
                        """).setParameter("fecha", target).setParameter("slot", slot).executeUpdate();
                em.createNativeQuery("""
                        UPDATE public.citas SET fecha=:fecha,token_qr=public.crear_token_qr(cod_cit,CAST(:fecha AS date))
                        WHERE cod_cit=:cit
                        """).setParameter("fecha", target).setParameter("cit", code).executeUpdate();
            }
            return new Fixture(code, med, target, patient, currentQr(code));
        });
    }

    private List<Bitacora> arrivalAudit(String code) {
        return em.createQuery("""
                FROM Bitacora WHERE nombreTabla='citas' AND claveRegistro=:cit AND accion='UPDATE'
                ORDER BY secuencial
                """, Bitacora.class).setParameter("cit", code).getResultList().stream()
                .filter(row -> "En Espera".equals(row.datosNuevos.get("estado"))).toList();
    }

    private long auditCount(String code) {
        return em.createQuery("SELECT count(b) FROM Bitacora b WHERE nombreTabla='citas' AND claveRegistro=:cit", Long.class)
                .setParameter("cit", code).getSingleResult();
    }

    private String currentQr(String code) {
        return (String) em.createNativeQuery("SELECT token_qr FROM public.citas WHERE cod_cit=:cit").setParameter("cit", code).getSingleResult();
    }

    private String signedQr(String code, LocalDate date) {
        return (String) em.createNativeQuery("SELECT public.crear_token_qr(CAST(:cit AS text),CAST(:fecha AS date))")
                .setParameter("cit", code).setParameter("fecha", date).getSingleResult();
    }

    private String status(String code) {
        return (String) em.createNativeQuery("SELECT estado FROM public.citas WHERE cod_cit=:cit").setParameter("cit", code).getSingleResult();
    }

    private LocalDate clinicDate() {
        return (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult();
    }

    private String secretaryToken() {
        return login(createUser(em, "ROL002"), "ROL002");
    }

    private static String path(Fixture fixture) {
        return RECEPCION + "/citas/" + fixture.code();
    }

    private static RequestSpecification request(String bearer) {
        var request = given().contentType("application/json").accept("application/json")
                .config(RestAssuredConfig.config().logConfig(LogConfig.logConfig()));
        return bearer == null ? request : request.header("Authorization", "Bearer " + bearer);
    }

    private record Fixture(String code, String doctor, LocalDate date, Account patient, String qr) { }
}
