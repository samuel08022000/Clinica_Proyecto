package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import org.clinica.dto.recepcion.RecepcionDtos.NuevaCita;
import org.clinica.entity.Bitacora;
import org.clinica.repository.CitaFunctionRepository;
import org.clinica.service.PersonalTestFixtures.Account;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;

import java.time.LocalDate;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import static org.clinica.service.PersonalTestFixtures.authenticated;
import static org.clinica.service.PersonalTestFixtures.createSpecialty;
import static org.clinica.service.PersonalTestFixtures.createUser;
import static org.clinica.service.PersonalTestFixtures.json;
import static org.clinica.service.PersonalTestFixtures.login;
import static org.hamcrest.Matchers.equalTo;
import static org.hamcrest.Matchers.hasItem;
import static org.hamcrest.Matchers.not;
import static org.junit.jupiter.api.Assertions.*;

@QuarkusTest
class RecepcionTest {
    private static final String RECEPCION = "/api/v1/secretaria";

    @Inject EntityManager em;
    @Inject CitaFunctionRepository funciones;
    @Inject ReservaCitaService reservas;

    @Test
    void searchesOnlyPatientsByNormalizedDocumentNameAndEmailWithMinimalResponses() {
        String token = secretaryToken();
        var patient = patient();
        String suffix = UUID.randomUUID().toString().replace("-", "").substring(0, 12);
        String document = "CI-" + suffix;
        String name = "Paciente " + suffix;
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("UPDATE public.pacientes SET ci=:ci WHERE cod_pac=:pac")
                    .setParameter("ci", document).setParameter("pac", patient.code()).executeUpdate();
            em.createNativeQuery("UPDATE public.usuarios SET nombre_completo=:nombre WHERE cod_usu=:usu")
                    .setParameter("nombre", name).setParameter("usu", patient.account().id()).executeUpdate();
        });
        for (String term : List.of(document.replace("-", ""), name, patient.account().email())) {
            var matches = request(token).queryParam("q", term).get(RECEPCION + "/pacientes").then().statusCode(200)
                    .body("pacientes[0].codPac", equalTo(patient.code())).extract().jsonPath().getMap("pacientes[0]");
            assertEquals(Set.of("codPac", "nombreCompleto", "ci", "email", "estadoCuenta"), matches.keySet());
        }
        var detail = request(token).get(RECEPCION + "/pacientes/" + patient.code()).then().statusCode(200)
                .body("habilitadoParaReservar", equalTo(true)).body("emailVerificado", equalTo(true))
                .extract().jsonPath().getMap("$");
        assertFalse(detail.containsKey("passwordHash"));
        assertFalse(detail.containsKey("codUsu"));
        var staff = createUser(em, "ROL002");
        request(token).queryParam("q", staff.email()).get(RECEPCION + "/pacientes").then().statusCode(200)
                .body("pacientes.size()", equalTo(0));
        request(token).get(RECEPCION + "/pacientes/PAC999999").then().statusCode(404);
    }

    @Test
    void rejectsEmptyBroadAndWildcardSearchesWithoutMassDisclosure() {
        String token = secretaryToken();
        for (String term : List.of("", "ab", "%%%", "A".repeat(101), "_'%")) {
            request(token).queryParam("q", term).get(RECEPCION + "/pacientes").then().statusCode(400);
        }
        String term = "Amplia" + UUID.randomUUID().toString().substring(0, 8);
        for (int index = 0; index < 21; index++) {
            var account = createUser(em, "ROL001");
            QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public.usuarios SET nombre_completo=:nombre WHERE cod_usu=:usu")
                    .setParameter("nombre", term).setParameter("usu", account.id()).executeUpdate());
        }
        request(token).queryParam("q", term).get(RECEPCION + "/pacientes").then().statusCode(400)
                .body("error", equalTo("VALIDACION"));
        request(token).queryParam("q", "abc%_' OR 1=1 --").get(RECEPCION + "/pacientes").then().statusCode(200)
                .body("pacientes.size()", equalTo(0));
    }

    @Test
    void createsReadsAndAuditsCounterReservationUsingAuthenticatedSecretary() {
        var secretary = createUser(em, "ROL002");
        String token = login(secretary, "ROL002");
        var other = createUser(em, "ROL002");
        var patient = patient();
        var agenda = agenda(tomorrow());
        String slot = slots(token, agenda, tomorrow(), null).getFirst();
        var response = request(token).header("X-Actor-Cod-Usu", other.id())
                .body(Map.of("codPac", patient.code(), "codSlot", slot)).post(RECEPCION + "/citas").then().statusCode(201)
                .body("codPac", equalTo(patient.code())).body("codSlot", equalTo(slot))
                .body("codMed", equalTo(agenda.med())).body("codEsp", equalTo(agenda.esp()))
                .body("estado", equalTo("Confirmada")).body("canalReserva", equalTo("Ventanilla"))
                .body("qrDisponible", equalTo(true)).extract().jsonPath();
        String citation = response.getString("codCit");
        String secretaryCode = (String) em.createNativeQuery("SELECT cod_sec FROM public.secretarias WHERE cod_usu=:usu")
                .setParameter("usu", secretary.id()).getSingleResult();
        assertEquals(secretaryCode, response.getString("codSec"));
        assertFalse(response.getMap("$").containsKey("tokenQr"));
        var audit = em.createQuery("FROM Bitacora WHERE nombreTabla='citas' AND claveRegistro=:cit", Bitacora.class)
                .setParameter("cit", citation).getResultList();
        assertEquals(1, audit.size());
        assertEquals(secretary.id(), audit.getFirst().codUsu);
        assertEquals("Ventanilla", audit.getFirst().datosNuevos.get("canal_reserva"));
        assertFalse(audit.getFirst().datosNuevos.containsKey("token_qr"));
        String otherToken = login(other, "ROL002");
        request(otherToken).get(RECEPCION + "/citas/" + citation).then().statusCode(200)
                .body("estado", equalTo("Confirmada")).body("codSec", equalTo(secretaryCode));
        assertEquals(1L, citationAuditCount(citation));
        assertFalse(slots(token, agenda, tomorrow(), null).contains(slot));
        request(token).get(RECEPCION + "/citas/CIT99999999").then().statusCode(404);
    }

    @Test
    void catalogAndAvailabilityExcludeDisabledSourcesBreaksAndBlocks() {
        String token = secretaryToken();
        var agenda = agenda(tomorrow());
        request(token).get(RECEPCION + "/especialidades").then().statusCode(200).body("codEsp", hasItem(agenda.esp()));
        request(token).queryParam("codEsp", agenda.esp()).get(RECEPCION + "/medicos").then().statusCode(200)
                .body("codMed", hasItem(agenda.med())).body("[0].duracionConsultaMin", equalTo(30));
        assertEquals(6, slots(token, agenda, tomorrow(), null).size());
        var admin = createUser(em, "ROL004");
        String adminToken = login(admin, "ROL004");
        request(adminToken).body(Map.of("fechaBloqueo", tomorrow().toString(), "horaInicio", "08:00", "horaFin", "09:00", "motivo", "Permiso"))
                .post("/api/v1/administracion/medicos/" + agenda.med() + "/bloqueos").then().statusCode(201);
        assertEquals(4, slots(token, agenda, tomorrow(), null).size());
        var patient = patient();
        String blocked = slotAt(agenda, tomorrow(), "08:00");
        reserve(token, patient.code(), blocked).then().statusCode(409);
        disable("especialidades", "cod_esp", agenda.esp());
        request(token).get(RECEPCION + "/especialidades").then().statusCode(200).body("codEsp", not(hasItem(agenda.esp())));
        assertTrue(slots(token, agenda, tomorrow(), null).isEmpty());
        reserve(token, patient.code(), slotAt(agenda, tomorrow(), "09:00")).then().statusCode(409);
        var other = agenda(tomorrow());
        disable("medicos", "cod_med", other.med());
        request(token).queryParam("codEsp", other.esp()).get(RECEPCION + "/medicos").then().statusCode(200)
                .body("size()", equalTo(0));
        reserve(token, patient.code(), slotAt(other, tomorrow(), "08:00")).then().statusCode(409);
        var unlinked = agenda(tomorrow());
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery(
                "UPDATE public.medico_especialidades SET estado='Inactivo' WHERE cod_med=:med AND cod_esp=:esp")
                .setParameter("med", unlinked.med()).setParameter("esp", unlinked.esp()).executeUpdate());
        reserve(token, patient.code(), slotAt(unlinked, tomorrow(), "08:00")).then().statusCode(409);
    }

    @ParameterizedTest
    @ValueSource(strings = {"Bloqueado_Temporal", "Inactivo", "NO_VERIFICADO", "USUARIO_INACTIVO"})
    void disabledPatientsRemainVisibleButCannotReserve(String condition) {
        String token = secretaryToken();
        var patient = patient();
        var agenda = agenda(tomorrow());
        QuarkusTransaction.requiringNew().run(() -> {
            switch (condition) {
                case "NO_VERIFICADO" -> em.createNativeQuery("UPDATE public.pacientes SET email_verificado=false WHERE cod_pac=:pac")
                        .setParameter("pac", patient.code()).executeUpdate();
                case "USUARIO_INACTIVO" -> em.createNativeQuery("UPDATE public.usuarios SET estado='Inactivo' WHERE cod_usu=:usu")
                        .setParameter("usu", patient.account().id()).executeUpdate();
                default -> em.createNativeQuery("""
                        UPDATE public.pacientes SET estado_cuenta=:estado,
                            bloqueado_hasta=CASE WHEN :estado='Bloqueado_Temporal' THEN now()+interval '2 days' ELSE NULL END
                        WHERE cod_pac=:pac
                        """).setParameter("estado", condition).setParameter("pac", patient.code()).executeUpdate();
            }
        });
        request(token).get(RECEPCION + "/pacientes/" + patient.code()).then().statusCode(200)
                .body("habilitadoParaReservar", equalTo(false));
        String slot = slots(token, agenda, tomorrow(), null).getFirst();
        reserve(token, patient.code(), slot).then().statusCode(403);
        assertThrows(PersistenceException.class, () -> funciones.reservarCita(patient.code(), slot, patient.account().id(), "Web", null));
        assertEquals(0L, patientCitations(patient.code()));
    }

    @Test
    void rejectsUnknownResourcesForbiddenFieldsAndPastOrOutOfWindowSlots() {
        String token = secretaryToken();
        var patient = patient();
        var agenda = agenda(clinicDate().plusDays(14));
        String slot = slots(token, agenda, clinicDate().plusDays(14), null).getFirst();
        reserve(token, "PAC999999", slot).then().statusCode(404);
        reserve(token, patient.code(), "SLT99999999").then().statusCode(404);
        for (String field : List.of("codSec", "canalReserva", "codMed", "codEsp", "rol", "creadoPorUsu")) {
            var input = new HashMap<>(Map.of("codPac", patient.code(), "codSlot", slot));
            input.put(field, "ROL004");
            request(token).body(input).post(RECEPCION + "/citas").then().statusCode(400);
        }
        request(token).body(Map.of()).post(RECEPCION + "/citas").then().statusCode(400);
        for (int offset : List.of(-1, 15)) {
            LocalDate date = clinicDate().plusDays(offset);
            request(token).queryParam("codEsp", agenda.esp()).queryParam("fecha", date.toString())
                    .get(RECEPCION + "/disponibilidad").then().statusCode(400);
            String invalidSlot = cloneSlotOnDate(slot, date);
            reserve(token, patient.code(), invalidSlot).then().statusCode(400);
        }
        String past = QuarkusTransaction.requiringNew().call(() -> (String) em.createNativeQuery("""
                INSERT INTO public.slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
                SELECT cod_hor,cod_med,cod_esp,public.fecha_clinica()+TIME '00:00',public.fecha_clinica()+TIME '00:30'
                FROM public.slots_agenda WHERE cod_slot=:slot RETURNING cod_slot
                """).setParameter("slot", slot).getSingleResult());
        reserve(token, patient.code(), past).then().statusCode(400);
        reserve(token, patient.code(), slot).then().statusCode(201);
        reserve(token, patient.code(), slot).then().statusCode(409);
        assertEquals(1L, patientCitations(patient.code()));
        assertEquals(1L, em.createNativeQuery("SELECT count(*) FROM public.bitacora WHERE nombre_tabla='citas' AND datos_nuevos->>'cod_pac'=:pac")
                .setParameter("pac", patient.code()).getSingleResult());
    }

    @Test
    void patientAvailabilityAndDatabaseRejectCrossSpecialtyOverlapWithRollback() {
        String token = secretaryToken();
        var patient = patient();
        var first = agenda(tomorrow());
        var second = agenda(tomorrow());
        String firstSlot = slotAt(first, tomorrow(), "08:00");
        String overlapping = slotAt(second, tomorrow(), "08:00");
        reserve(token, patient.code(), firstSlot).then().statusCode(201);
        assertTrue(slots(token, second, tomorrow(), null).contains(overlapping));
        assertFalse(slots(token, second, tomorrow(), patient.code()).contains(overlapping));
        reserve(token, patient.code(), overlapping).then().statusCode(409);
        var dto = new NuevaCita();
        dto.codPac = patient.code();
        dto.codSlot = slotAt(second, tomorrow(), "09:00");
        String actor = createUser(em, "ROL002").id();
        assertThrows(IllegalStateException.class, () -> QuarkusTransaction.requiringNew().run(() -> {
            reservas.reservarPresencial(dto, actor);
            throw new IllegalStateException("Revertir reserva de prueba");
        }));
        assertEquals(1L, patientCitations(patient.code()));
        assertTrue(slots(token, second, tomorrow(), null).contains(dto.codSlot));
    }

    @ParameterizedTest
    @ValueSource(strings = {"DOS_SECRETARIOS", "WEB_SECRETARIA", "MISMO_PACIENTE"})
    void simultaneousReservationsUseOneCentralAtomicEngine(String scenario) throws Exception {
        var staff = createUser(em, "ROL002");
        String firstToken = login(staff, "ROL002");
        String secondToken = secretaryToken();
        var firstPatient = patient();
        var secondPatient = scenario.equals("MISMO_PACIENTE") ? firstPatient : patient();
        var firstAgenda = agenda(tomorrow());
        var secondAgenda = scenario.equals("MISMO_PACIENTE") ? agenda(tomorrow()) : firstAgenda;
        String firstSlot = slotAt(firstAgenda, tomorrow(), "08:00");
        String secondSlot = slotAt(secondAgenda, tomorrow(), "08:00");
        var barrier = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var one = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return reserve(firstToken, firstPatient.code(), firstSlot).statusCode();
            });
            var two = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                if (scenario.equals("WEB_SECRETARIA")) {
                    try {
                        funciones.reservarCita(secondPatient.code(), secondSlot, secondPatient.account().id(), "Web", null);
                        return 201;
                    } catch (PersistenceException conflict) {
                        return 409;
                    }
                }
                return reserve(secondToken, secondPatient.code(), secondSlot).statusCode();
            });
            assertEquals(List.of(201, 409), List.of(one.get(20, TimeUnit.SECONDS), two.get(20, TimeUnit.SECONDS)).stream().sorted().toList());
        }
        long total = patientCitations(firstPatient.code());
        if (!scenario.equals("MISMO_PACIENTE")) total += patientCitations(secondPatient.code());
        assertEquals(1L, total);
    }

    @Test
    void concurrentCounterReservationAndAdministrativeBlockRemainConsistent() throws Exception {
        String token = secretaryToken();
        String admin = login(createUser(em, "ROL004"), "ROL004");
        var patient = patient();
        LocalDate date = tomorrow();
        var agenda = agenda(date);
        String slot = slotAt(agenda, date, "08:00");
        var barrier = new CyclicBarrier(2);
        try (var executor = Executors.newVirtualThreadPerTaskExecutor()) {
            var booking = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return reserve(token, patient.code(), slot);
            });
            var blocking = executor.submit(() -> {
                barrier.await(10, TimeUnit.SECONDS);
                return request(admin).body(Map.of("fechaBloqueo", date.toString(), "motivo", "Licencia"))
                        .post("/api/v1/administracion/medicos/" + agenda.med() + "/bloqueos").then().statusCode(201).extract().<String>path("codBlo");
            });
            var result = booking.get(20, TimeUnit.SECONDS);
            String codBlo = blocking.get(20, TimeUnit.SECONDS);
            assertTrue(result.statusCode() == 201 || result.statusCode() == 409);
            if (result.statusCode() == 201) {
                String citation = result.path("codCit");
                request(token).get(RECEPCION + "/citas/" + citation).then().statusCode(200)
                        .body("estado", equalTo("Afectada/Pendiente Reprogramación"));
                request(admin).get("/api/v1/administracion/bloqueos/" + codBlo + "/citas-afectadas").then().statusCode(200)
                        .body("citas.codCit", hasItem(citation));
            }
        }
        assertTrue(slots(token, agenda, date, null).isEmpty());
    }

    @ParameterizedTest
    @CsvSource({"GET,/pacientes?q=Paciente", "GET,/pacientes/PAC999999", "GET,/especialidades", "GET,/medicos?codEsp=ESP999999",
            "GET,/disponibilidad?codEsp=ESP999999&fecha=2026-01-01", "POST,/citas", "GET,/citas/CIT99999999"})
    void allReceptionRoutesRequireAValidBearerEvenWithForgedActorHeaders(String method, String route) {
        for (var input : List.of(json().header("X-Actor-Rol", "ROL002").header("X-Actor-Cod-Usu", "USU999999"),
                request(UUID.randomUUID().toString()))) {
            if (method.equals("POST")) input.body(Map.of());
            input.request(method, RECEPCION + route).then().statusCode(401);
        }
    }

    @ParameterizedTest
    @ValueSource(strings = {"ROL001", "ROL003", "ROL004"})
    void otherRolesCannotUseReceptionAndSecretaryCannotUseAdministrativeWrites(String role) {
        String token = login(createUser(em, role), role);
        request(token).get(RECEPCION + "/especialidades").then().statusCode(403).body("error.code", equalTo("FORBIDDEN"));
        request(token).body(Map.of()).post(RECEPCION + "/citas").then().statusCode(403);
        String secretary = secretaryToken();
        for (String path : List.of("/api/v1/personal/medicos", "/api/v1/personal/secretarias", "/api/v1/personal/especialidades",
                "/api/v1/administracion/medicos/MED999999/horarios", "/api/v1/administracion/medicos/MED999999/bloqueos")) {
            request(secretary).header("X-Actor-Rol", "ROL004").body(Map.of()).post(path).then().statusCode(403);
        }
    }

    private Patient patient() {
        var account = createUser(em, "ROL001");
        String code = (String) em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:usu")
                .setParameter("usu", account.id()).getSingleResult();
        return new Patient(code, account);
    }

    private Agenda agenda(LocalDate date) {
        var doctor = createUser(em, "ROL003");
        String specialty = createSpecialty(em);
        String med = (String) em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu=:usu")
                .setParameter("usu", doctor.id()).getSingleResult();
        QuarkusTransaction.requiringNew().run(() -> {
            em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)")
                    .setParameter("med", med).setParameter("esp", specialty).executeUpdate();
            em.createNativeQuery("""
                    INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin,pausa_inicio,pausa_fin)
                    VALUES (:med,:dia,TIME '08:00',TIME '12:00',TIME '10:00',TIME '11:00')
                    """).setParameter("med", med).setParameter("dia", (short) date.getDayOfWeek().getValue()).executeUpdate();
        });
        return new Agenda(med, specialty);
    }

    private List<String> slots(String token, Agenda agenda, LocalDate date, String patient) {
        var query = request(token).queryParam("codEsp", agenda.esp()).queryParam("codMed", agenda.med()).queryParam("fecha", date.toString());
        if (patient != null) query.queryParam("codPac", patient);
        return query.get(RECEPCION + "/disponibilidad").then().statusCode(200).extract().jsonPath().getList("codSlot");
    }

    private String slotAt(Agenda agenda, LocalDate date, String time) {
        return (String) em.createNativeQuery("SELECT cod_slot FROM public.slots_agenda WHERE cod_med=:med AND fecha=:fecha AND hora_inicio=CAST(:hora AS time)")
                .setParameter("med", agenda.med()).setParameter("fecha", date).setParameter("hora", time).getSingleResult();
    }

    private String cloneSlotOnDate(String original, LocalDate date) {
        return QuarkusTransaction.requiringNew().call(() -> (String) em.createNativeQuery("""
                INSERT INTO public.slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
                SELECT cod_hor,cod_med,cod_esp,CAST(:fecha AS date)+hora_inicio,CAST(:fecha AS date)+hora_fin
                FROM public.slots_agenda WHERE cod_slot=:slot RETURNING cod_slot
                """).setParameter("fecha", date).setParameter("slot", original).getSingleResult());
    }

    private void disable(String table, String key, String code) {
        QuarkusTransaction.requiringNew().run(() -> em.createNativeQuery("UPDATE public." + table + " SET estado='Inactivo' WHERE " + key + "=:id")
                .setParameter("id", code).executeUpdate());
    }

    private long citationAuditCount(String code) {
        return ((Number) em.createNativeQuery("SELECT count(*) FROM public.bitacora WHERE nombre_tabla='citas' AND clave_registro=:id")
                .setParameter("id", code).getSingleResult()).longValue();
    }

    private long patientCitations(String code) {
        return ((Number) em.createNativeQuery("SELECT count(*) FROM public.citas WHERE cod_pac=:pac")
                .setParameter("pac", code).getSingleResult()).longValue();
    }

    private String secretaryToken() { return login(createUser(em, "ROL002"), "ROL002"); }
    private LocalDate clinicDate() { return (LocalDate) em.createNativeQuery("SELECT public.fecha_clinica()", LocalDate.class).getSingleResult(); }
    private LocalDate tomorrow() { return clinicDate().plusDays(1); }
    private static RequestSpecification request(String token) { return authenticated(token).header("X-Actor-Rol", "ROL002"); }
    private static io.restassured.response.Response reserve(String token, String patient, String slot) {
        return request(token).body(Map.of("codPac", patient, "codSlot", slot)).post(RECEPCION + "/citas");
    }
    private record Patient(String code, Account account) { }
    private record Agenda(String med, String esp) { }
}
