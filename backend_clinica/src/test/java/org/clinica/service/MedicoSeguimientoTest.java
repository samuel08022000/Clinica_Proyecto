package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.config.LogConfig;
import io.restassured.config.RestAssuredConfig;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.service.PersonalTestFixtures.Account;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;
import org.junit.jupiter.params.provider.CsvSource;

import java.time.LocalDate;
import java.time.OffsetDateTime;
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
class MedicoSeguimientoTest {
    private static final String MED = "/api/v1/medico", SEC = "/api/v1/secretaria";
    private static final Set<String> DER_FIELDS = Set.of("codDer", "codCitOrigen", "codPac", "nombrePaciente",
            "codMedEmisor", "nombreMedicoEmisor", "codEspDestino", "nombreEspecialidad", "codMedDestino",
            "nombreMedicoDestino", "estado", "fechaDerivacion", "codCitHija");
    private static final Set<String> ANA_FIELDS = Set.of("codAna", "codCit", "solicitadoPorUsu", "tipoAnalisis",
            "estado", "resultado", "fechaRegistro", "fechaEntregaEstimada", "fechaEntregaReal");
    @Inject EntityManager em;
    @Inject CitaFunctionRepository funciones;

    @Test
    void completeReferralBookingArrivalAndAttentionUseCentralAgendaAndExistingRules() {
        Fixture f = fixture(true);
        String der = referral(f);
        var detail = request(f.sourceToken()).get(MED + "/derivaciones/" + der).then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("codCitOrigen", equalTo(f.cit())).body("codPac", equalTo(f.pac()))
                .body("codMedEmisor", equalTo(f.sourceMed())).body("codEspDestino", equalTo(f.esp()))
                .body("codMedDestino", equalTo(f.targetMed())).body("estado", equalTo("Pendiente"))
                .body("codCitHija", nullValue()).extract().jsonPath().getMap("$");
        assertEquals(DER_FIELDS, detail.keySet());
        assertNotNull(OffsetDateTime.parse((String) detail.get("fechaDerivacion")));
        request(f.secretaryToken()).queryParam("size", 50).get(SEC + "/derivaciones").then().statusCode(200)
                .body("derivaciones.codDer", hasItem(der));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.bitacora WHERE nombre_tabla='derivaciones' AND clave_registro=:id AND accion='INSERT'", der));
        assertEquals(f.source().id(), scalar("SELECT cod_usu FROM public.bitacora WHERE nombre_tabla='derivaciones' AND clave_registro=:id AND accion='INSERT'", der));
        request(f.secretaryToken()).queryParam("codEsp", f.esp()).queryParam("codMed", f.targetMed())
                .queryParam("codPac", f.pac()).queryParam("fecha", today().plusDays(1).toString())
                .get(SEC + "/disponibilidad").then().statusCode(200).body("codSlot", hasItem(f.slots().getFirst()));
        String child = book(f, der, f.slots().getFirst()).then().statusCode(201)
                .body("codPac", equalTo(f.pac())).body("codMed", equalTo(f.targetMed()))
                .body("codEsp", equalTo(f.esp())).body("estado", equalTo("Confirmada"))
                .body("canalReserva", equalTo("Derivacion")).body("qrDisponible", equalTo(true)).extract().path("codCit");
        assertEquals(f.cit(), scalar("SELECT cod_cit_padre FROM public.citas WHERE cod_cit=:id", child));
        request(f.sourceToken()).get(MED + "/derivaciones/" + der).then().statusCode(200)
                .body("codCitHija", equalTo(child)).body("estado", equalTo("Agendada"));
        request(f.targetToken()).queryParam("fecha", today().plusDays(1).toString()).get(MED + "/agenda")
                .then().statusCode(200).body("codCit", hasItem(child));
        request(f.sourceToken()).get(MED + "/citas/" + child).then().statusCode(404);
        request(f.targetToken()).get(MED + "/citas/" + f.cit()).then().statusCode(404);
        request(f.targetToken()).get(MED + "/derivaciones/" + der).then().statusCode(404);
        request(f.targetToken()).post(MED + "/citas/" + child + "/atender").then().statusCode(409);
        rebase(child, f.slots().getFirst(), today());
        checkin(child, f.secretaryToken());
        request(f.targetToken()).post(MED + "/citas/" + child + "/atender").then().statusCode(200)
                .body("estado", equalTo("Atendida")).body("atendidaPorUsu", equalTo(f.target().id()));
        assertEquals(child, scalar("SELECT cod_cit_hija FROM public.derivaciones WHERE cod_der=:id", der));
    }

    @Test
    void referralWithoutSpecificDoctorCanBeScheduledAndTraditionalBookingRemainsUnchanged() {
        Fixture f = fixture(true);
        String der = request(f.sourceToken()).body(Map.of("codEspDestino", f.esp(), "motivo", "Interconsulta"))
                .post(cita(f) + "/derivaciones").then().statusCode(201).body("codMedDestino", nullValue()).extract().path("codDer");
        book(f, der, f.slots().getFirst()).then().statusCode(201);
        request(f.secretaryToken()).body(Map.of("codPac", f.pac(), "codSlot", f.slots().get(1)))
                .post(SEC + "/citas").then().statusCode(201).body("canalReserva", equalTo("Ventanilla"));
    }

    @Test
    void retriesAreIdempotentButDifferentInterconsultationsAreAllowed() {
        Fixture f = fixture(true);
        String first = referral(f);
        String repeated = referral(f);
        assertEquals(first, repeated);
        String different = request(f.sourceToken()).body(Map.of("codEspDestino", f.esp(), "codMedDestino", f.targetMed(), "motivo", "Otra interconsulta"))
                .post(cita(f) + "/derivaciones").then().statusCode(201).extract().path("codDer");
        assertNotEquals(first, different);
        assertEquals("2", scalar("SELECT count(*)::text FROM public.derivaciones WHERE cod_cit_origen=:id", f.cit()));
        request(f.sourceToken()).queryParam("size", 1).queryParam("estado", "Pendiente").get(MED + "/derivaciones")
                .then().statusCode(200).body("derivaciones.codDer", equalTo(List.of(different))).body("hayMas", equalTo(true));
        request(f.sourceToken()).queryParam("size", 1).queryParam("page", 1).get(MED + "/derivaciones")
                .then().statusCode(200).body("derivaciones.codDer", equalTo(List.of(first))).body("hayMas", equalTo(false));
    }

    @Test
    void simultaneousIdenticalReferralsHaveOneRecordAndOneAudit() throws Exception {
        Fixture f = fixture(true);
        List<String> codes = race(() -> referral(f), () -> referral(f));
        assertEquals(codes.getFirst(), codes.get(1));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.derivaciones WHERE cod_cit_origen=:id", f.cit()));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.bitacora WHERE nombre_tabla='derivaciones' AND accion='INSERT' AND clave_registro=:id", codes.getFirst()));
    }

    @ParameterizedTest
    @ValueSource(strings = {"Confirmada", "En Espera", "Cancelada"})
    void referralRequiresFinishedAttention(String state) {
        Fixture f = fixture(false);
        if (state.equals("En Espera")) checkin(f.cit(), f.secretaryToken());
        if (state.equals("Cancelada")) funciones.cancelarCita(f.cit(), f.secretary().id(), "Prueba de cancelación");
        request(f.sourceToken()).body(payload(f)).post(cita(f) + "/derivaciones").then().statusCode(409);
        assertEquals("0", scalar("SELECT count(*)::text FROM public.derivaciones WHERE cod_cit_origen=:id", f.cit()));
    }

    @ParameterizedTest
    @ValueSource(strings = {"especialidad", "medico", "usuario", "vinculo"})
    void inactiveDestinationIsRejected(String type) {
        Fixture f = fixture(true);
        String sql = switch (type) {
            case "especialidad" -> "UPDATE public.especialidades SET estado='Inactivo' WHERE cod_esp=:id";
            case "medico" -> "UPDATE public.medicos SET estado='Inactivo' WHERE cod_med=:id";
            case "usuario" -> "UPDATE public.usuarios SET estado='Inactivo' WHERE cod_usu=:id";
            default -> "UPDATE public.medico_especialidades SET estado='Inactivo' WHERE cod_med=:id";
        };
        mutate(sql, type.equals("especialidad") ? f.esp() : type.equals("usuario") ? f.target().id() : f.targetMed());
        request(f.sourceToken()).body(payload(f)).post(cita(f) + "/derivaciones").then().statusCode(409);
    }

    @Test
    void missingOrIncompatibleDestinationsAndForgedFieldsAreRejected() {
        Fixture f = fixture(true);
        request(f.sourceToken()).body(Map.of("codEspDestino", "ESP999999", "motivo", "Interconsulta"))
                .post(cita(f) + "/derivaciones").then().statusCode(404);
        for (String medico : List.of("MED999999", f.sourceMed())) {
            request(f.sourceToken()).body(Map.of("codEspDestino", f.esp(), "codMedDestino", medico, "motivo", "Interconsulta"))
                    .post(cita(f) + "/derivaciones").then().statusCode(409);
        }
        var forged = new java.util.HashMap<>(payload(f)); forged.put("codMedEmisor", f.targetMed());
        request(f.sourceToken()).body(forged).post(cita(f) + "/derivaciones").then().statusCode(400);
        for (String reason : List.of("", " ", "x".repeat(2001))) {
            request(f.sourceToken()).body(Map.of("codEspDestino", f.esp(), "motivo", reason))
                    .post(cita(f) + "/derivaciones").then().statusCode(400);
        }
    }

    @Test
    void foreignOrMissingOriginAndReferralNeverLeakToAnotherDoctor() {
        Fixture f = fixture(true);
        String der = referral(f);
        request(f.targetToken()).body(payload(f)).post(cita(f) + "/derivaciones").then().statusCode(404);
        request(f.targetToken()).get(MED + "/derivaciones/" + der).then().statusCode(404);
        request(f.targetToken()).queryParam("codMed", f.sourceMed()).get(MED + "/derivaciones")
                .then().statusCode(200).body("derivaciones", empty());
        request(f.sourceToken()).body(payload(f)).post(MED + "/citas/CIT99999999/derivaciones").then().statusCode(404);
        request(f.sourceToken()).get(MED + "/derivaciones/DER999999").then().statusCode(404);
    }

    @ParameterizedTest
    @ValueSource(strings = {"paciente", "especialidad", "medico", "cancelada", "agendada", "ocupado", "bloqueado", "inactivo", "vencido"})
    void invalidDerivedBookingsDoNotConsumeReferral(String reason) {
        Fixture f = fixture(true);
        String der = referral(f);
        String slot = f.slots().getFirst();
        Map<String, String> input = new java.util.HashMap<>(Map.of("codSlot", slot, "codDer", der));
        int status = 409;
        switch (reason) {
            case "paciente" -> input.put("codPac", "PAC999999");
            case "especialidad" -> input.put("codSlot", f.sourceSlot());
            case "medico" -> {
                Account other = createUser(em, "ROL003");
                String med = doctorCode(other);
                configure(med, f.esp(), "10:00", "12:00");
                input.put("codSlot", slots(med).getFirst());
            }
            case "cancelada" -> mutate("UPDATE public.derivaciones SET estado='Cancelada' WHERE cod_der=:id", der);
            case "agendada" -> book(f, der, slot).then().statusCode(201);
            case "ocupado" -> request(f.secretaryToken()).body(Map.of("codPac", f.pac(), "codSlot", slot)).post(SEC + "/citas").then().statusCode(201);
            case "bloqueado" -> QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("INSERT INTO public.bloqueos_agenda(cod_med,cod_usu_admin,fecha_bloqueo,motivo) VALUES (:med,:admin,public.fecha_clinica()+1,'Prueba')")
                    .setParameter("med", f.targetMed()).setParameter("admin", createUser(em, "ROL004").id()).executeUpdate());
            case "inactivo" -> mutate("UPDATE public.medicos SET estado='Inactivo' WHERE cod_med=:id", f.targetMed());
            case "vencido" -> {
                QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public.slots_agenda SET inicio_at=public.fecha_clinica()-1+hora_inicio,fin_at=public.fecha_clinica()-1+hora_fin WHERE cod_slot=:id").setParameter("id", slot).executeUpdate());
                status = 400;
            }
            default -> fail("Caso desconocido");
        }
        request(f.secretaryToken()).body(input).post(SEC + "/citas").then().statusCode(status);
        if (!Set.of("cancelada", "agendada").contains(reason)) {
            assertEquals("Pendiente", scalar("SELECT estado FROM public.derivaciones WHERE cod_der=:id", der));
            assertNull(scalar("SELECT cod_cit_hija FROM public.derivaciones WHERE cod_der=:id", der));
            assertEquals("0", scalar("SELECT count(*)::text FROM public.citas WHERE canal_reserva='Derivacion' AND cod_cit_padre=:id", f.cit()));
        }
    }

    @Test
    void missingReferralHasControlledError() {
        Fixture f = fixture(true);
        book(f, "DER999999", f.slots().getFirst()).then().statusCode(404).body("error", equalTo("NO_ENCONTRADO"));
    }

    @Test
    void concurrentBookingOfOneReferralOnDifferentSlotsHasOneWinner() throws Exception {
        Fixture f = fixture(true);
        String der = referral(f);
        List<Integer> statuses = race(() -> book(f, der, f.slots().getFirst()).statusCode(),
                () -> book(f, der, f.slots().get(1)).statusCode());
        assertEquals(List.of(201, 409), statuses.stream().sorted().toList());
        assertEquals("1", scalar("SELECT count(*)::text FROM public.citas WHERE cod_cit_padre=:id AND canal_reserva='Derivacion'", f.cit()));
        assertEquals("1", scalar("SELECT count(*)::text FROM public.bitacora WHERE nombre_tabla='derivaciones' AND accion='UPDATE' AND clave_registro=:id", der));
    }

    @Test
    void failedTransactionDoesNotConsumeReferralOrPersistChildAndRetryWorks() {
        Fixture f = fixture(true);
        String der = referral(f);
        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            funciones.reservarCita(f.pac(), f.slots().getFirst(), f.secretary().id(), "Ventanilla", der);
            throw new IllegalStateException("Rollback de prueba");
        }));
        assertEquals("Pendiente", scalar("SELECT estado FROM public.derivaciones WHERE cod_der=:id", der));
        assertNull(scalar("SELECT cod_cit_hija FROM public.derivaciones WHERE cod_der=:id", der));
        assertEquals("0", scalar("SELECT count(*)::text FROM public.citas WHERE cod_cit_padre=:id AND canal_reserva='Derivacion'", f.cit()));
        book(f, der, f.slots().getFirst()).then().statusCode(201);
    }

    @Test
    void reschedulingPreservesReferralAndOriginalChainAndRejectsWrongDestination() {
        Fixture f = fixture(true);
        String der = referral(f);
        String child = book(f, der, f.slots().getFirst()).then().statusCode(201).extract().path("codCit");
        assertThrows(RuntimeException.class, () -> funciones.reprogramarCita(child, f.sourceSlot(), f.secretary().id(), "Destino incorrecto"));
        assertEquals("Confirmada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", child));
        String next = funciones.reprogramarCita(child, f.slots().get(1), f.secretary().id(), "Nuevo horario");
        assertEquals(next, scalar("SELECT cod_cit_hija FROM public.derivaciones WHERE cod_der=:id", der));
        assertEquals("Agendada", scalar("SELECT estado FROM public.derivaciones WHERE cod_der=:id", der));
        assertEquals("Reprogramada", scalar("SELECT estado FROM public.citas WHERE cod_cit=:id", child));
        assertEquals(child, scalar("SELECT cod_cit_padre FROM public.citas WHERE cod_cit=:id", next));
        assertEquals(f.cit(), scalar("SELECT cod_cit_padre FROM public.citas WHERE cod_cit=:id", child));
        assertEquals("Derivacion", scalar("SELECT canal_reserva FROM public.citas WHERE cod_cit=:id", next));
        book(f, der, f.slots().get(2)).then().statusCode(409);
    }

    @Test
    void analysisRequestAndResultHaveScopedAccessServerIdentityAuditAndFinalImmutability() {
        Fixture f = fixture(true);
        String ana = analysis(f, "Hemograma");
        var list = request(f.sourceToken()).get(cita(f) + "/analisis").then().statusCode(200)
                .header("Cache-Control", containsString("no-store"))
                .body("[0].codCit", equalTo(f.cit())).body("[0].solicitadoPorUsu", equalTo(f.source().id()))
                .body("[0].tipoAnalisis", equalTo("Hemograma")).body("[0].estado", equalTo("Pendiente"))
                .body("[0].resultado", nullValue()).body("[0].fechaRegistro", notNullValue()).extract().jsonPath();
        assertEquals(ANA_FIELDS, list.getMap("[0]").keySet());
        assertEquals(f.source().id(), scalar("SELECT cod_usu FROM public.bitacora WHERE nombre_tabla='analisis' AND accion='INSERT' AND clave_registro=:id", ana));
        request(f.targetToken()).get(cita(f) + "/analisis").then().statusCode(404);
        request(f.targetToken()).body(Map.of("estado", "Completado", "resultado", "No autorizado"))
                .patch(MED + "/analisis/" + ana).then().statusCode(404);
        request(f.sourceToken()).body(Map.of("estado", "En Proceso")).patch(MED + "/analisis/" + ana)
                .then().statusCode(200).body("estado", equalTo("En Proceso")).body("resultado", nullValue());
        request(f.sourceToken()).body(Map.of("estado", "Completado", "resultado", "Resultado de prueba"))
                .patch(MED + "/analisis/" + ana).then().statusCode(200)
                .body("estado", equalTo("Completado")).body("resultado", equalTo("Resultado de prueba"))
                .body("fechaEntregaReal", nullValue());
        request(f.sourceToken()).body(Map.of("estado", "Completado", "resultado", "Intento de sobrescritura"))
                .patch(MED + "/analisis/" + ana).then().statusCode(409);
        assertEquals("Resultado de prueba", scalar("SELECT resultado FROM public.analisis WHERE cod_ana=:id", ana));
        assertEquals("2", scalar("SELECT count(*)::text FROM public.bitacora WHERE nombre_tabla='analisis' AND accion='UPDATE' AND clave_registro=:id", ana));
        assertEquals(f.source().id(), scalar("SELECT cod_usu FROM public.bitacora WHERE nombre_tabla='analisis' AND accion='UPDATE' AND clave_registro=:id ORDER BY registrado_en DESC LIMIT 1", ana));
        assertEquals(ana, analysis(f, " hemograma "));
    }

    @Test
    void waitingAppointmentCanRequestAnalysisAndRetriesAreDeduplicatedByType() {
        Fixture f = fixture(false);
        checkin(f.cit(), f.secretaryToken());
        String first = analysis(f, "Hemograma");
        assertEquals(first, analysis(f, " HEMOGRAMA "));
        assertNotEquals(first, analysis(f, "Glucosa"));
        request(f.sourceToken()).body(Map.of("estado", "Completado", "resultado", "Valor de prueba"))
                .patch(MED + "/analisis/" + first).then().statusCode(200);
        request(f.sourceToken()).post(cita(f) + "/atender").then().statusCode(200);
    }

    @Test
    void simultaneousAnalysisRequestsAndResultUpdatesHaveOneWinner() throws Exception {
        Fixture f = fixture(true);
        List<String> codes = race(() -> analysis(f, "Hemograma"), () -> analysis(f, "Hemograma"));
        assertEquals(codes.getFirst(), codes.get(1));
        String ana = codes.getFirst();
        assertEquals("1", scalar("SELECT count(*)::text FROM public.analisis WHERE cod_cit=:id", f.cit()));
        List<Integer> statuses = race(() -> result(f, ana, "Primero").statusCode(), () -> result(f, ana, "Segundo").statusCode());
        assertEquals(List.of(200, 409), statuses.stream().sorted().toList());
        assertEquals("1", scalar("SELECT count(*)::text FROM public.bitacora WHERE nombre_tabla='analisis' AND accion='UPDATE' AND clave_registro=:id", ana));
        assertTrue(Set.of("Primero", "Segundo").contains(scalar("SELECT resultado FROM public.analisis WHERE cod_ana=:id", ana)));
    }

    @ParameterizedTest
    @ValueSource(strings = {"Confirmada", "Cancelada"})
    void analysisCannotBeIssuedBeforeCheckinOrAfterCancellation(String state) {
        Fixture f = fixture(false);
        if (state.equals("Cancelada")) funciones.cancelarCita(f.cit(), f.secretary().id(), "Prueba");
        request(f.sourceToken()).body(Map.of("tipoAnalisis", "Hemograma")).post(cita(f) + "/analisis").then().statusCode(409);
    }

    @ParameterizedTest
    @ValueSource(strings = {"Cancelado", "Entregado", "Completado"})
    void finalAnalysisStatesCannotBeModified(String state) {
        Fixture f = fixture(true);
        String ana = analysis(f, "Hemograma");
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public.analisis SET estado=:state WHERE cod_ana=:id")
                .setParameter("state", state).setParameter("id", ana).executeUpdate());
        result(f, ana, "Cambio").then().statusCode(409);
        assertNull(scalar("SELECT resultado FROM public.analisis WHERE cod_ana=:id", ana));
    }

    @Test
    void analysisValidationsRejectForgedFieldsUnknownIdsAndUnsafeStateChanges() {
        Fixture f = fixture(true);
        for (String type : List.of("", " ", "x".repeat(151))) {
            request(f.sourceToken()).body(Map.of("tipoAnalisis", type)).post(cita(f) + "/analisis").then().statusCode(400);
        }
        for (String field : List.of("solicitadoPorUsu", "resultado", "archivoPdfUrl", "estado")) {
            request(f.sourceToken()).body(Map.of("tipoAnalisis", "Hemograma", field, "arbitrario"))
                    .post(cita(f) + "/analisis").then().statusCode(400);
        }
        String ana = analysis(f, "Hemograma");
        for (Map<String, String> payload : List.of(Map.of("estado", "Entregado"), Map.of("estado", "Completado"),
                Map.of("estado", "Completado", "resultado", " "), Map.of("estado", "En Proceso", "resultado", "prematuro"),
                Map.of("estado", "Completado", "resultado", "Valor", "archivoPdfUrl", "ruta"))) {
            request(f.sourceToken()).body(payload).patch(MED + "/analisis/" + ana).then().statusCode(400);
        }
        request(f.targetToken()).body(Map.of("tipoAnalisis", "Hemograma")).post(cita(f) + "/analisis").then().statusCode(404);
        result(f, "ANA999999", "Valor").then().statusCode(404);
        request(f.sourceToken()).get(MED + "/citas/CIT99999999/analisis").then().statusCode(404);
    }

    @ParameterizedTest
    @CsvSource({"estado,Otro", "page,-1", "page,10001", "page,abc", "size,0", "size,51"})
    void invalidReferralListFiltersHaveControlledErrors(String name, String value) {
        String med = login(createUser(em, "ROL003"), "ROL003");
        String sec = login(createUser(em, "ROL002"), "ROL002");
        request(med).queryParam(name, value).get(MED + "/derivaciones").then().statusCode(400).body("error", equalTo("VALIDACION"));
        request(sec).queryParam(name, value).get(SEC + "/derivaciones").then().statusCode(400);
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL002", "ROL004"})
    void otherRolesHaveNoClinicalAccess(String role) {
        assertMedicalDenied(login(createUser(em, role), role), 403);
    }

    @Test
    void missingInvalidExpiredAndRevokedSessionsAreRejected() {
        assertMedicalDenied(null, 401);
        assertMedicalDenied(UUID.randomUUID().toString(), 401);
        var expiredStore = new AuthSessionStore(java.time.Duration.ofSeconds(-1), java.time.Clock.systemUTC());
        assertTrue(expiredStore.find(expiredStore.issue("USU999999").rawToken()).isEmpty());
        String token = login(createUser(em, "ROL003"), "ROL003");
        request(token).post("/api/v1/auth/logout").then().statusCode(200);
        assertMedicalDenied(token, 401);
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL003", "ROL004"})
    void referralSchedulingListRemainsExclusiveToSecretary(String role) {
        request(login(createUser(em, role), role)).get(SEC + "/derivaciones").then().statusCode(403);
    }

    @Test
    void catalogsReuseActiveAdministrativeConfigurationAndInactiveDoctorsCannotEmit() {
        Fixture f = fixture(true);
        request(f.sourceToken()).get(MED + "/especialidades").then().statusCode(200).body("codEsp", hasItem(f.esp()));
        request(f.sourceToken()).queryParam("codEsp", f.esp()).get(MED + "/medicos").then().statusCode(200).body("codMed", hasItem(f.targetMed()));
        mutate("UPDATE public.medicos SET estado='Inactivo' WHERE cod_med=:id", f.sourceMed());
        request(f.sourceToken()).body(payload(f)).post(cita(f) + "/derivaciones").then().statusCode(403);
    }

    private void assertMedicalDenied(String token, int status) {
        for (String route : List.of("/derivaciones", "/derivaciones/DER999999", "/citas/CIT99999999/analisis", "/especialidades", "/medicos"))
            request(token).header("X-Actor-Rol", "ROL003").get(MED + route).then().statusCode(status);
        for (String route : List.of("/citas/CIT99999999/derivaciones", "/citas/CIT99999999/analisis"))
            request(token).body(Map.of()).post(MED + route).then().statusCode(status);
        request(token).body(Map.of()).patch(MED + "/analisis/ANA999999").then().statusCode(status);
    }

    private Fixture fixture(boolean attended) {
        Account source = createUser(em, "ROL003"), target = createUser(em, "ROL003"), patient = createUser(em, "ROL001"), secretary = createUser(em, "ROL002");
        String sourceMed = doctorCode(source), targetMed = doctorCode(target);
        String originEsp = createSpecialty(em), destEsp = createSpecialty(em);
        configure(sourceMed, originEsp, "08:00", "10:00");
        configure(targetMed, destEsp, "10:00", "12:00");
        String sourceSlot = slots(sourceMed).getFirst();
        String pac = scalar("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:id", patient.id());
        String cit = funciones.reservarCita(pac, sourceSlot, patient.id(), "Web", null);
        rebase(cit, sourceSlot, today());
        Fixture f = new Fixture(cit, pac, sourceSlot, sourceMed, targetMed, destEsp, slots(targetMed), source, target, secretary,
                login(source, "ROL003"), login(target, "ROL003"), login(secretary, "ROL002"));
        if (attended) {
            checkin(cit, f.secretaryToken());
            request(f.sourceToken()).post(cita(f) + "/atender").then().statusCode(200);
        }
        return f;
    }

    private void configure(String med, String esp, String start, String end) {
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)").setParameter("med", med).setParameter("esp", esp).executeUpdate();
            em.createNativeQuery("INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin) VALUES (:med,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),CAST(:start AS time),CAST(:end AS time))")
                    .setParameter("med", med).setParameter("start", start).setParameter("end", end).executeUpdate();
        });
    }
    @SuppressWarnings("unchecked")
    private List<String> slots(String med) {
        return em.createNativeQuery("SELECT cod_slot FROM public.slots_agenda WHERE cod_med=:id AND fecha=public.fecha_clinica()+1 ORDER BY inicio_at")
                .setParameter("id", med).getResultList();
    }
    private String doctorCode(Account a) { return scalar("SELECT cod_med FROM public.medicos WHERE cod_usu=:id", a.id()); }
    private String scalar(String sql, String id) { return (String) em.createNativeQuery(sql).setParameter("id", id).getSingleResult(); }
    private void mutate(String sql, String id) { QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery(sql).setParameter("id", id).executeUpdate()); }
    private LocalDate today() { return (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult(); }
    private void rebase(String cit, String slot, LocalDate date) {
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("UPDATE public.slots_agenda SET inicio_at=CAST(:date AS date)+hora_inicio,fin_at=CAST(:date AS date)+hora_fin WHERE cod_slot=:id")
                    .setParameter("date", date).setParameter("id", slot).executeUpdate();
            em.createNativeQuery("UPDATE public.citas SET fecha=:date,token_qr=public.crear_token_qr(cod_cit,CAST(:date AS date)) WHERE cod_cit=:id")
                    .setParameter("date", date).setParameter("id", cit).executeUpdate();
        });
    }
    private void checkin(String cit, String token) {
        String qr = scalar("SELECT token_qr FROM public.citas WHERE cod_cit=:id", cit);
        request(token).body(Map.of("tokenQr", qr)).post(SEC + "/checkin").then().statusCode(200);
    }
    private String referral(Fixture f) {
        return request(f.sourceToken()).body(payload(f)).post(cita(f) + "/derivaciones").then().statusCode(201).extract().path("codDer");
    }
    private String analysis(Fixture f, String type) {
        return request(f.sourceToken()).body(Map.of("tipoAnalisis", type)).post(cita(f) + "/analisis").then().statusCode(201).extract().path("codAna");
    }
    private static Map<String, String> payload(Fixture f) { return Map.of("codEspDestino", f.esp(), "codMedDestino", f.targetMed(), "motivo", "Interconsulta de prueba"); }
    private static String cita(Fixture f) { return MED + "/citas/" + f.cit(); }
    private static io.restassured.response.Response book(Fixture f, String der, String slot) {
        return request(f.secretaryToken()).body(Map.of("codDer", der, "codSlot", slot)).post(SEC + "/citas");
    }
    private static io.restassured.response.Response result(Fixture f, String ana, String value) {
        return request(f.sourceToken()).body(Map.of("estado", "Completado", "resultado", value)).patch(MED + "/analisis/" + ana);
    }
    private static RequestSpecification request(String token) {
        var spec = given().contentType("application/json").accept("application/json").config(RestAssuredConfig.config().logConfig(LogConfig.logConfig()));
        return token == null ? spec : spec.header("Authorization", "Bearer " + token);
    }
    private static <T> List<T> race(Callable<T> first, Callable<T> second) throws Exception {
        var barrier = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var one = executor.submit(() -> { barrier.await(10, TimeUnit.SECONDS); return first.call(); });
            var two = executor.submit(() -> { barrier.await(10, TimeUnit.SECONDS); return second.call(); });
            return List.of(one.get(40, TimeUnit.SECONDS), two.get(40, TimeUnit.SECONDS));
        }
    }
    private record Fixture(String cit, String pac, String sourceSlot, String sourceMed, String targetMed, String esp,
            List<String> slots, Account source, Account target, Account secretary, String sourceToken, String targetToken, String secretaryToken) { }
}
