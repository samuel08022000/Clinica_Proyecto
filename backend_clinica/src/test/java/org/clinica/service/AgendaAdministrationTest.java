package org.clinica.service;

import io.quarkus.narayana.jta.QuarkusTransaction;
import io.quarkus.test.junit.QuarkusTest;
import io.restassured.specification.RequestSpecification;
import jakarta.inject.Inject;
import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceException;
import org.clinica.dto.agenda.AgendaDtos.BloqueoSolicitud;
import org.clinica.entity.Bitacora;
import org.clinica.repository.CitaFunctionRepository;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;

import java.time.Duration;
import java.time.LocalDate;
import java.time.LocalTime;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

import static org.clinica.service.PersonalTestFixtures.AUTH;
import static org.clinica.service.PersonalTestFixtures.PERSONAL;
import static org.clinica.service.PersonalTestFixtures.authenticated;
import static org.clinica.service.PersonalTestFixtures.createSpecialty;
import static org.clinica.service.PersonalTestFixtures.createUser;
import static org.clinica.service.PersonalTestFixtures.json;
import static org.clinica.service.PersonalTestFixtures.login;
import static org.hamcrest.Matchers.equalTo;
import static org.hamcrest.Matchers.hasItem;
import static org.junit.jupiter.api.Assertions.*;

@QuarkusTest
class AgendaAdministrationTest {
    private static final String ADMIN = "/api/v1/administracion";

    @Inject EntityManager em;
    @Inject CitaFunctionRepository citas;
    @Inject AgendaHorarioService service;

    @Test
    void configuresEditsAndDeactivatesIsoWeeklySchedulesWithAudit() {
        var admin = createUser(em,"ROL004");
        String token = login(admin,"ROL004");
        Doctor doctor = doctor();
        String fake = createUser(em,"ROL004").id();
        String codHor = request(token).header("X-Actor-Cod-Usu",fake).body(schedule(1,"08:00","12:00"))
                .post(doctorPath(doctor)+"/horarios").then().statusCode(201).extract().path("codHor");
        request(token).get(doctorPath(doctor)+"/horarios").then().statusCode(200)
                .body("codHor",hasItem(codHor)).body("[0].diaSemana",equalTo(1));
        request(token).body(schedule(7,"09:00","13:00")).put(ADMIN+"/horarios/"+codHor)
                .then().statusCode(200).body("diaSemana",equalTo(7));
        for (String estado : List.of("Inactivo","Activo")) {
            request(token).body(Map.of("estado",estado)).put(ADMIN+"/horarios/"+codHor+"/estado")
                    .then().statusCode(200).body("estado",equalTo(estado));
        }
        var audit = em.createQuery("FROM Bitacora WHERE nombreTabla='horarios' AND claveRegistro=:id ORDER BY secuencial",
                Bitacora.class).setParameter("id",codHor).getResultList();
        assertEquals(List.of("INSERT","UPDATE","UPDATE","UPDATE"),audit.stream().map(row->row.accion).toList());
        audit.forEach(row->assertEquals(admin.id(),row.codUsu));
    }

    @ParameterizedTest
    @CsvSource({"08:00,08:00", "12:00,08:00", "25:00,10:00"})
    void rejectsInvalidWorkingRanges(String inicio,String fin) {
        String token=adminToken();
        request(token).body(schedule(1,inicio,fin)).post(doctorPath(doctor())+"/horarios")
                .then().statusCode(400).body("error",equalTo("VALIDACION"));
    }

    @Test
    void validatesDaysPausesUnknownFieldsAndInactiveDoctors() {
        String token=adminToken();
        Doctor doctor=doctor();
        for (int dia : List.of(0,8)) {
            request(token).body(schedule(dia,"08:00","12:00")).post(doctorPath(doctor)+"/horarios").then().statusCode(400);
        }
        for (Map<String,Object> pause : List.of(Map.<String,Object>of("pausaInicio","07:00","pausaFin","09:00"),
                Map.<String,Object>of("pausaInicio","10:00"),Map.<String,Object>of("pausaInicio","11:00","pausaFin","10:00"))) {
            var input=schedule(1,"08:00","12:00"); input.putAll(pause);
            request(token).body(input).post(doctorPath(doctor)+"/horarios").then().statusCode(400);
        }
        var forbidden=schedule(1,"08:00","12:00"); forbidden.put("codUsuAdmin","USU999999");
        request(token).body(forbidden).post(doctorPath(doctor)+"/horarios").then().statusCode(400);
        request(token).body(schedule(1,"08:00","12:00")).post(ADMIN+"/medicos/MED999999/horarios").then().statusCode(404);
        request(token).body(schedule(1,"08:00","12:00")).put(ADMIN+"/horarios/HOR999999").then().statusCode(404);
        request(token).body(Map.of("estado","Inactivo")).put(PERSONAL+"/medicos/"+doctor.med()+"/estado").then().statusCode(200);
        request(token).get(doctorPath(doctor)+"/horarios").then().statusCode(200);
        request(token).body(schedule(1,"08:00","12:00")).post(doctorPath(doctor)+"/horarios").then().statusCode(409);
    }

    @ParameterizedTest
    @ValueSource(ints={20,30,45,60})
    void generatesEffectiveDurationsAndExcludesBreaks(int minutes) {
        String token=adminToken(); Doctor doctor=doctor(); LocalDate date=clinicDate().plusDays(1);
        request(token).body(Map.of("duracionConsultaMin",minutes)).put(doctorPath(doctor)+"/duracion").then().statusCode(200);
        var input=schedule(date.getDayOfWeek().getValue(),"08:00","12:00");
        input.put("pausaInicio","09:00"); input.put("pausaFin","10:00");
        request(token).body(input).post(doctorPath(doctor)+"/horarios").then().statusCode(201);
        var slots=slots(token,doctor,date);
        int expected=switch(minutes) { case 20 -> 9; case 30 -> 6; default -> 3; };
        assertEquals(expected,slots.size());
        for (var slot:slots) {
            LocalTime start=LocalTime.parse((String)slot.get("horaInicio"));
            LocalTime end=LocalTime.parse((String)slot.get("horaFin"));
            assertEquals(minutes,Duration.between(start,end).toMinutes());
            assertFalse(start.isBefore(LocalTime.of(10,0)) && end.isAfter(LocalTime.of(9,0)));
        }
        request(token).body(Map.of("estado","Inactivo")).put(PERSONAL+"/especialidades/"+doctor.esp()+"/estado")
                .then().statusCode(200);
        assertTrue(slots(token,doctor,date).isEmpty());
    }

    @Test
    void usesLinkThenDoctorThenSpecialtyDurationAndRetainsHistoricalSlotVersions() {
        String token=adminToken(); Doctor doctor=doctor(); LocalDate date=clinicDate().plusDays(1);
        String hor=addSchedule(token,doctor,date,"08:00","11:00");
        var initial=slots(token,doctor,date); assertEquals(6,initial.size());
        var patient=createUser(em,"ROL001"); String pac=patientCode(patient.id());
        String oldSlot=(String)initial.getFirst().get("codSlot");
        String historical=citas.reservarCita(pac,oldSlot,patient.id(),"Web",null);
        citas.cancelarCita(historical,patient.id(),"Historial de prueba");
        request(token).body(Map.of("duracionConsultaMin",20)).put(doctorPath(doctor)+"/duracion").then().statusCode(200);
        var updated=slots(token,doctor,date); assertEquals(9,updated.size());
        assertFalse(updated.stream().anyMatch(slot->oldSlot.equals(slot.get("codSlot"))));
        assertEquals(LocalTime.of(8,30),em.createNativeQuery("SELECT hora_fin FROM public.citas WHERE cod_cit=:id",LocalTime.class)
                .setParameter("id",historical).getSingleResult());
        assertEquals(LocalTime.of(8,30),em.createNativeQuery("SELECT hora_fin FROM public.slots_agenda WHERE cod_slot=:id",LocalTime.class)
                .setParameter("id",oldSlot).getSingleResult());
        QuarkusTransaction.requiringNew().run(()->em.createNativeQuery(
                "UPDATE public.medico_especialidades SET duracion_consulta_min=NULL WHERE cod_med=:med AND cod_esp=:esp")
                .setParameter("med",doctor.med()).setParameter("esp",doctor.esp()).executeUpdate());
        assertEquals(9,slots(token,doctor,date).size());
        QuarkusTransaction.requiringNew().run(()->em.createNativeQuery(
                "UPDATE public.medico_especialidades SET duracion_consulta_min=45 WHERE cod_med=:med AND cod_esp=:esp")
                .setParameter("med",doctor.med()).setParameter("esp",doctor.esp()).executeUpdate());
        assertEquals(4,slots(token,doctor,date).size());
        assertEquals(45,Duration.between(LocalTime.parse((String)slots(token,doctor,date).getFirst().get("horaInicio")),
                LocalTime.parse((String)slots(token,doctor,date).getFirst().get("horaFin"))).toMinutes());
        request(token).body(schedule(date.getDayOfWeek().getValue(),"08:15","11:15")).put(ADMIN+"/horarios/"+hor)
                .then().statusCode(200);
        assertEquals("08:15:00",slots(token,doctor,date).getFirst().get("horaInicio"));
        assertEquals(4,slots(token,doctor,date).size());
    }

    @Test
    void rejectsScheduleAndDurationChangesThatWouldInvalidateFutureAppointments() {
        String token=adminToken(); Doctor doctor=doctor(); LocalDate date=clinicDate().plusDays(1);
        String hor=addSchedule(token,doctor,date,"08:00","10:00");
        var patient=createUser(em,"ROL001");
        String appointment=citas.reservarCita(patientCode(patient.id()),(String)slots(token,doctor,date).getFirst().get("codSlot"),
                patient.id(),"Web",null);
        assertEquals(3,slots(token,doctor,date).size());
        var pause=schedule(date.getDayOfWeek().getValue(),"08:00","10:00");
        pause.put("pausaInicio","08:00"); pause.put("pausaFin","09:00");
        for (var input:List.of(pause,schedule(date.getDayOfWeek().getValue(),"08:15","10:00"))) {
            request(token).body(input).put(ADMIN+"/horarios/"+hor).then().statusCode(409)
                    .body("error",equalTo("CONFLICTO_AGENDA"));
        }
        request(token).body(Map.of("estado","Inactivo")).put(ADMIN+"/horarios/"+hor+"/estado").then().statusCode(409);
        request(token).body(Map.of("duracionConsultaMin",60)).put(doctorPath(doctor)+"/duracion").then().statusCode(409);
        var current=request(token).get(PERSONAL+"/medicos/"+doctor.med()).then().statusCode(200).extract().jsonPath().getMap("$");
        var legacy=new HashMap<>(current); legacy.put("duracionConsultaMin",60);
        request(token).body(legacy).put(PERSONAL+"/medicos/"+doctor.med()).then().statusCode(409);
        request(token).body(schedule(date.getDayOfWeek().getValue(),"08:00","11:00")).put(ADMIN+"/horarios/"+hor)
                .then().statusCode(200);
        assertEquals("Confirmada",status(appointment));
        assertEquals(LocalTime.of(8,30),em.createNativeQuery("SELECT hora_fin FROM public.citas WHERE cod_cit=:id",LocalTime.class)
                .setParameter("id",appointment).getSingleResult());
    }

    @Test
    void partialAndFullDayBlocksPreserveSchedulesAndDurableAffectedCitationHistory() {
        var admin=createUser(em,"ROL004"); String token=login(admin,"ROL004"); Doctor doctor=doctor();
        LocalDate date=clinicDate().plusDays(1); String hor=addSchedule(token,doctor,date,"08:00","10:00");
        var patient=createUser(em,"ROL001"); String pac=patientCode(patient.id()); var available=slots(token,doctor,date);
        String first=citas.reservarCita(pac,(String)available.get(0).get("codSlot"),patient.id(),"Web",null);
        String second=citas.reservarCita(pac,(String)available.get(2).get("codSlot"),patient.id(),"Web",null);
        var partial=block(date,"Permiso"); partial.put("horaInicio","08:00"); partial.put("horaFin","09:00");
        String codBlo=request(token).header("X-Actor-Cod-Usu",createUser(em,"ROL004").id()).body(partial)
                .post(doctorPath(doctor)+"/bloqueos").then().statusCode(201)
                .body("codUsuAdmin",equalTo(admin.id())).body("cantidadCitasAfectadas",equalTo(1))
                .body("trazabilidadCompleta",equalTo(true)).extract().path("codBlo");
        assertEquals("Afectada/Pendiente Reprogramación",status(first)); assertEquals("Confirmada",status(second));
        assertEquals(1,slots(token,doctor,date).size());
        request(token).get(ADMIN+"/bloqueos/"+codBlo).then().statusCode(200).body("motivo",equalTo("Permiso"));
        request(token).get(ADMIN+"/bloqueos/"+codBlo+"/citas-afectadas").then().statusCode(200)
                .body("citas.codCit",hasItem(first));
        String replacement=citas.reprogramarCita(first,(String)available.get(3).get("codSlot"),admin.id(),"Nueva hora autorizada");
        request(token).get(ADMIN+"/bloqueos/"+codBlo+"/citas-afectadas").then().statusCode(200)
                .body("citas[0].codCit",equalTo(first)).body("citas[0].estado",equalTo("Reprogramada"));
        request(token).body(block(date,"Feriado")).post(doctorPath(doctor)+"/bloqueos").then().statusCode(201)
                .body("cantidadCitasAfectadas",equalTo(2));
        assertTrue(slots(token,doctor,date).isEmpty());
        assertEquals("Afectada/Pendiente Reprogramación",status(second));
        assertEquals("Afectada/Pendiente Reprogramación",status(replacement));
        request(token).get(doctorPath(doctor)+"/horarios").then().statusCode(200)
                .body("[0].codHor",equalTo(hor)).body("[0].estado",equalTo("Activo"));
        request(token).queryParam("desde",date.toString()).queryParam("hasta",date.toString())
                .get(doctorPath(doctor)+"/bloqueos").then().statusCode(200).body("size()",equalTo(2));
        var audit=em.createQuery("FROM Bitacora WHERE nombreTabla='bloqueos_agenda' AND claveRegistro=:id",Bitacora.class)
                .setParameter("id",codBlo).getResultList(); assertEquals(1,audit.size()); assertEquals(admin.id(),audit.getFirst().codUsu);
        var citationAudit=em.createQuery("""
                FROM Bitacora WHERE nombreTabla='citas' AND claveRegistro=:cit
                  AND accion='UPDATE' ORDER BY secuencial
                """,Bitacora.class).setParameter("cit",first).getResultList();
        assertEquals(admin.id(),citationAudit.getFirst().codUsu);
        assertEquals("Afectada/Pendiente Reprogramación",citationAudit.getFirst().datosNuevos.get("estado"));
        assertTrue(((Number)em.createNativeQuery("SELECT count(*) FROM public.notificaciones WHERE cod_cit=:id AND tipo_alerta='Cita_Afectada'")
                .setParameter("id",second).getSingleResult()).longValue()>0);
    }

    @Test
    void invalidBlocksAndForcedRollbackLeaveNoPartialChanges() {
        var admin=createUser(em,"ROL004"); String token=login(admin,"ROL004"); Doctor doctor=doctor(); LocalDate date=clinicDate().plusDays(1);
        addSchedule(token,doctor,date,"08:00","10:00"); var patient=createUser(em,"ROL001");
        String appointment=citas.reservarCita(patientCode(patient.id()),(String)slots(token,doctor,date).getFirst().get("codSlot"),patient.id(),"Web",null);
        for (var extra:List.of(Map.<String,Object>of("motivo"," "),Map.<String,Object>of("horaInicio","09:00"),
                Map.<String,Object>of("horaInicio","10:00","horaFin","09:00"),Map.<String,Object>of("codUsuAdmin",admin.id()))) {
            var input=block(date,"Licencia"); input.putAll(extra);
            request(token).body(input).post(doctorPath(doctor)+"/bloqueos").then().statusCode(400);
        }
        BloqueoSolicitud dto=new BloqueoSolicitud(); dto.fechaBloqueo=date.toString(); dto.motivo="Rollback";
        assertThrows(IllegalStateException.class,()->QuarkusTransaction.requiringNew().run(()->{
            service.crearBloqueo(doctor.med(),dto,admin.id()); throw new IllegalStateException("Revertir fixture");
        }));
        assertEquals("Confirmada",status(appointment));
        request(token).get(doctorPath(doctor)+"/bloqueos").then().statusCode(200).body("size()",equalTo(0));
        request(token).body(block(date.plusDays(30),"Vacaciones")).post(doctorPath(doctor)+"/bloqueos").then().statusCode(201);
        request(token).get(doctorPath(doctor)+"/bloqueos").then().statusCode(200).body("size()",equalTo(1));
        request(token).get(ADMIN+"/bloqueos/BLO999999").then().statusCode(404);
        request(token).get(ADMIN+"/bloqueos/BLO999999/citas-afectadas").then().statusCode(404);
    }

    @Test
    void filtersAvailabilityRespectsClinicZoneAndD14AndRejectsD15() {
        String token=adminToken(); Doctor doctor=doctor(); LocalDate today=clinicDate(); LocalDate limit=today.plusDays(14);
        addSchedule(token,doctor,limit,"08:00","10:00"); var available=slots(token,doctor,limit); assertEquals(4,available.size());
        for (var filter:List.of(Map.of("codEsp",doctor.esp()),Map.of("codMed",doctor.med()),Map.of("fecha",limit.toString(),"codMed",doctor.med()))) {
            request(token).queryParams(filter).get(ADMIN+"/disponibilidad").then().statusCode(200).body("codMed",hasItem(doctor.med()));
        }
        assertTrue(slots(token,doctor,today.plusDays(15)).isEmpty());
        var patient=createUser(em,"ROL001");
        citas.reservarCita(patientCode(patient.id()),(String)available.getFirst().get("codSlot"),patient.id(),"Web",null);
        assertThrows(PersistenceException.class,()->citas.generarSlotsAgenda(today.plusDays(15),today.plusDays(15)));
        String outsideSlot=QuarkusTransaction.requiringNew().call(()->(String)em.createNativeQuery("""
                INSERT INTO public.slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
                SELECT cod_hor,cod_med,cod_esp,inicio_at+interval '1 day',fin_at+interval '1 day'
                FROM public.slots_agenda WHERE cod_slot=:slot RETURNING cod_slot
                """).setParameter("slot",available.getFirst().get("codSlot")).getSingleResult());
        assertThrows(PersistenceException.class,()->citas.reservarCita(patientCode(patient.id()),outsideSlot,patient.id(),"Web",null));
        assertThrows(PersistenceException.class,()->QuarkusTransaction.requiringNew().run(()->
                em.createNativeQuery("INSERT INTO public.citas(cod_pac,cod_slot) VALUES (:pac,:slot)")
                        .setParameter("pac",patientCode(patient.id())).setParameter("slot",outsideSlot).executeUpdate()));
        QuarkusTransaction.requiringNew().run(()->{
            em.createNativeQuery("SET LOCAL TIME ZONE 'Asia/Tokyo'").executeUpdate();
            assertEquals(today,clinicDate());
        });
        assertEquals(3,slots(token,doctor,limit).size());
        QuarkusTransaction.requiringNew().run(()->em.createNativeQuery("UPDATE public.usuarios SET estado='Inactivo' WHERE cod_usu=:usu")
                .setParameter("usu",doctor.usu()).executeUpdate());
        assertTrue(slots(token,doctor,limit).isEmpty());
    }

    @Test
    void concurrentSchedulesAndRepeatedGenerationCannotDuplicateAvailableSlots() throws Exception {
        String token=adminToken(); Doctor doctor=doctor(); LocalDate date=clinicDate().plusDays(1); var barrier=new CyclicBarrier(2);
        try(var executor=Executors.newVirtualThreadPerTaskExecutor()) {
            var first=executor.submit(()->{barrier.await(10,TimeUnit.SECONDS);return request(token).body(schedule(date.getDayOfWeek().getValue(),"08:00","10:00"))
                    .post(doctorPath(doctor)+"/horarios").statusCode();});
            var second=executor.submit(()->{barrier.await(10,TimeUnit.SECONDS);return request(token).body(schedule(date.getDayOfWeek().getValue(),"09:00","11:00"))
                    .post(doctorPath(doctor)+"/horarios").statusCode();});
            assertEquals(List.of(201,409),List.of(first.get(20,TimeUnit.SECONDS),second.get(20,TimeUnit.SECONDS)).stream().sorted().toList());
            String missingSlot=(String)slots(token,doctor,date).getFirst().get("codSlot");
            QuarkusTransaction.requiringNew().run(()->em.createNativeQuery("DELETE FROM public.slots_agenda WHERE cod_slot=:slot")
                    .setParameter("slot",missingSlot).executeUpdate());
            var one=executor.submit(()->citas.generarSlotsAgenda()); var two=executor.submit(()->citas.generarSlotsAgenda());
            assertEquals(1,one.get(20,TimeUnit.SECONDS)+two.get(20,TimeUnit.SECONDS));
        }
        assertEquals(4,slots(token,doctor,date).size());
    }

    @Test
    void racingBlockAndReservationAreConsistent() throws Exception {
        String token=adminToken(); Doctor doctor=doctor(); LocalDate date=clinicDate().plusDays(1); addSchedule(token,doctor,date,"08:00","10:00");
        String slot=(String)slots(token,doctor,date).getFirst().get("codSlot"); var patient=createUser(em,"ROL001"); String pac=patientCode(patient.id());
        var barrier=new CyclicBarrier(2);
        try(var executor=Executors.newVirtualThreadPerTaskExecutor()) {
            var booking=executor.submit(()->{barrier.await(10,TimeUnit.SECONDS);try{return citas.reservarCita(pac,slot,patient.id(),"Web",null);}
                catch(PersistenceException expected){return null;}});
            var block=executor.submit(()->{barrier.await(10,TimeUnit.SECONDS);return request(token).body(block(date,"Ausencia extraordinaria"))
                    .post(doctorPath(doctor)+"/bloqueos").then().statusCode(201).extract().<String>path("codBlo");});
            String appointment=booking.get(20,TimeUnit.SECONDS); String bloqueo=block.get(20,TimeUnit.SECONDS);
            if(appointment!=null) {
                assertEquals("Afectada/Pendiente Reprogramación",status(appointment));
                request(token).get(ADMIN+"/bloqueos/"+bloqueo+"/citas-afectadas").then().statusCode(200).body("citas.codCit",hasItem(appointment));
            }
        }
        assertTrue(slots(token,doctor,date).isEmpty());
    }

    @ParameterizedTest
    @CsvSource({"GET,/medicos/MED999999/horarios", "POST,/medicos/MED999999/horarios", "PUT,/horarios/HOR999999",
            "PUT,/horarios/HOR999999/estado", "PUT,/medicos/MED999999/duracion", "GET,/medicos/MED999999/bloqueos",
            "POST,/medicos/MED999999/bloqueos", "GET,/bloqueos/BLO999999", "GET,/bloqueos/BLO999999/citas-afectadas", "GET,/disponibilidad"})
    void administrativeRoutesRequireValidBearer(String method,String route) {
        for(var input:List.of(json().header("X-Actor-Rol","ROL004"),request(UUID.randomUUID().toString()))) {
            if(!method.equals("GET"))input.body(Map.of());
            input.request(method,ADMIN+route).then().statusCode(401);
        }
    }

    @ParameterizedTest
    @CsvSource({"ROL001,PUT,/horarios/HOR999999", "ROL002,POST,/medicos/MED999999/bloqueos", "ROL003,POST,/medicos/MED999999/horarios"})
    void otherRolesCannotMutateAdministrativeAgendas(String role,String method,String route) {
        request(login(createUser(em,role),role)).body(Map.of()).request(method,ADMIN+route).then().statusCode(403)
                .body("error",equalTo("SIN_PERMISO"));
    }

    @Test
    void measuresLocalGenerationPerformanceWithDatasetSize() {
        String token=adminToken(); Doctor doctor=doctor(); addSchedule(token,doctor,clinicDate().plusDays(1),"08:00","12:00");
        long doctors=count("medicos"); long horarios=count("horarios"); long specialties=count("especialidades"); long before=count("slots_agenda");
        citas.generarSlotsAgenda(); var millis=new ArrayList<Double>();
        for(int i=0;i<5;i++){long start=System.nanoTime();citas.generarSlotsAgenda();millis.add((System.nanoTime()-start)/1_000_000.0);}
        assertEquals(before,count("slots_agenda"));
        System.out.printf(Locale.ROOT,"AGENDA_PERFORMANCE medicos=%d horarios=%d especialidades=%d slots=%d generacionMs=%s%n",
                doctors,horarios,specialties,before,millis);
    }

    private long count(String table) {
        return ((Number)em.createNativeQuery("SELECT count(*) FROM public."+table).getSingleResult()).longValue();
    }
    private Doctor doctor() {
        var account=createUser(em,"ROL003"); String esp=createSpecialty(em);
        String med=(String)em.createNativeQuery("SELECT cod_med FROM public.medicos WHERE cod_usu=:usu").setParameter("usu",account.id()).getSingleResult();
        QuarkusTransaction.requiringNew().run(()->em.createNativeQuery("INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (:med,:esp)")
                .setParameter("med",med).setParameter("esp",esp).executeUpdate());
        return new Doctor(med,esp,account.id());
    }
    private String addSchedule(String token,Doctor doctor,LocalDate date,String start,String end) {
        return request(token).body(schedule(date.getDayOfWeek().getValue(),start,end)).post(doctorPath(doctor)+"/horarios")
                .then().statusCode(201).extract().path("codHor");
    }
    private List<Map<String,Object>> slots(String token,Doctor doctor,LocalDate date) {
        return request(token).queryParam("codEsp",doctor.esp()).queryParam("codMed",doctor.med()).queryParam("fecha",date.toString())
                .get(ADMIN+"/disponibilidad").then().statusCode(200).extract().jsonPath().getList("$");
    }
    private LocalDate clinicDate() { return (LocalDate)em.createNativeQuery("SELECT public.fecha_clinica()",LocalDate.class).getSingleResult(); }
    private String patientCode(String user) { return (String)em.createNativeQuery("SELECT cod_pac FROM public.pacientes WHERE cod_usu=:usu").setParameter("usu",user).getSingleResult(); }
    private String status(String appointment) { return (String)em.createNativeQuery("SELECT estado FROM public.citas WHERE cod_cit=:cit").setParameter("cit",appointment).getSingleResult(); }
    private String adminToken() { return login(createUser(em,"ROL004"),"ROL004"); }
    private static RequestSpecification request(String token) { return authenticated(token).header("X-Actor-Rol","ROL004"); }
    private static String doctorPath(Doctor doctor) { return ADMIN+"/medicos/"+doctor.med(); }
    private static Map<String,Object> schedule(int day,String start,String end) { return new HashMap<>(Map.of("diaSemana",day,"horaInicio",start,"horaFin",end)); }
    private static Map<String,Object> block(LocalDate date,String reason) { return new HashMap<>(Map.of("fechaBloqueo",date.toString(),"motivo",reason)); }
    private record Doctor(String med,String esp,String usu) { }
}
