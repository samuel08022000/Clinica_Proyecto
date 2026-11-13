package org.clinica.integration;

import com.fasterxml.jackson.databind.ObjectMapper;
import org.flywaydb.core.Flyway;

import java.nio.file.Files;
import java.nio.file.Path;
import java.security.SecureRandom;
import java.sql.Connection;
import java.sql.DriverManager;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.UUID;

/** Opt-in CLI only. Requires an empty, explicitly owned, exclusive PostgreSQL 15 test database. */
public final class Phase12IntegrationFixture {
    private static final SecureRandom RANDOM = new SecureRandom();

    public static void main(String[] args) throws Exception {
        String jdbc = System.getenv("CLINICA_TEST_JDBC_URL");
        String owner = System.getenv("CLINICA_INTEGRATION_OWNER");
        if (args.length != 0 || jdbc == null || !jdbc.matches("jdbc:postgresql://127\\.0\\.0\\.1:35441/clinica_fase12_e2e_[a-f0-9]{12}")
                || owner == null || !owner.matches("[a-f0-9-]{36}")) {
            throw new IllegalArgumentException("An explicitly owned isolated Fase 12 database is required.");
        }
        String user = System.getenv("CLINICA_TEST_DB_USER");
        String password = System.getenv("CLINICA_TEST_DB_PASSWORD");
        try (Connection db = DriverManager.getConnection(jdbc, user, password)) {
            if (db.getMetaData().getDatabaseMajorVersion() != 15
                    || !("isolated-fase12:" + owner).equals(scalar(db, "SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()"))
                    || !"0".equals(scalar(db, "SELECT count(*)::text FROM information_schema.tables WHERE table_schema='public'"))) {
                throw new IllegalStateException("The fixture refuses any database without an empty schema and its exclusive owner marker.");
            }
        }
        Flyway.configure().dataSource(jdbc, user, password).locations("filesystem:src/main/resources/db/migration")
                .baselineOnMigrate(false).outOfOrder(false).load().migrate();
        Map<String, Object> fixture = new LinkedHashMap<>();
        try (Connection db = DriverManager.getConnection(jdbc, user, password)) {
            db.setAutoCommit(false);
            Map<String, Map<String, String>> users = new LinkedHashMap<>();
            users.put("ROL001", account(db, "ROL001", "Elena Paciente Fase Doce"));
            users.put("ROL002", account(db, "ROL002", "Angela Recepcion Fase Doce"));
            users.put("ROL003", account(db, "ROL003", "Lucia Medico Fase Doce"));
            users.put("ROL004", account(db, "ROL004", "Maria Administradora Fase Doce"));
            var otherSecretary = account(db, "ROL002", "Segunda Recepcion Fase Doce");
            var observerAdmin = account(db, "ROL004", "Observador Administracion Fase Doce");
            var observerSecretary = account(db, "ROL002", "Observador Recepcion Fase Doce");
            var otherPatient = account(db, "ROL001", "Pedro Paciente Fase Doce");
            var sanctionPatient = account(db, "ROL001", "Paciente Sancion Fase Doce");
            var historyPatient = account(db, "ROL001", "Paciente Historial Fase Doce");
            String admin = users.get("ROL004").get("id");
            String secretary = users.get("ROL002").get("id");
            scalar(db, "SELECT set_config('app.cod_usu',?,true)", admin);
            String med = users.get("ROL003").get("codMed");
            String esp = scalar(db, "INSERT INTO public.especialidades(nombre_especialidad,duracion_consulta_min,descripcion) VALUES ('Medicina integral Fase Doce',30,'Catalogo exclusivo de pruebas') RETURNING cod_esp");
            scalar(db, "INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (?,?) RETURNING cod_med", med, esp);
            // All seven real weekdays. No custom clock or disabled trigger is used.
            for (int day = 1; day <= 7; day++) {
                scalar(db, "INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin,pausa_inicio,pausa_fin) VALUES (?,CAST(? AS smallint),TIME '00:00',TIME '23:30',TIME '12:00',TIME '12:30') RETURNING cod_hor", med, Integer.toString(day));
            }
            String date = scalar(db, "SELECT (public.fecha_clinica()+1)::text");
            String today = scalar(db, "SELECT public.fecha_clinica()::text");
            String qrSlot = slot(db, med, "08:00");
            String qrAppointment = reserve(db, users.get("ROL001").get("codPac"), qrSlot, secretary);
            historical(db, qrSlot, qrAppointment, "public.fecha_clinica()");
            String absenceSlot = slot(db, med, "09:00");
            String absenceAppointment = reserve(db, otherPatient.get("codPac"), absenceSlot, secretary);
            historical(db, absenceSlot, absenceAppointment, "public.fecha_clinica()-1");
            // Real cancelled history, sufficient for remote pagination without changing business states directly.
            for (int index = 0; index < 23; index++) {
                String cit = reserve(db, historyPatient.get("codPac"), slot(db, med, "10:00"), secretary);
                scalar(db, "SELECT public.cancelar_cita(?,?,'Antecedente sintetico de paginacion')::text", cit, secretary);
            }
            fixture.put("apiUrl", "http://127.0.0.1:18090/api/v1");
            fixture.put("laravelUrl", "http://127.0.0.1:18091");
            fixture.put("database", jdbc.substring(jdbc.lastIndexOf('/') + 1));
            fixture.put("postgresVersion", db.getMetaData().getDatabaseProductVersion());
            fixture.put("users", users);
            fixture.put("otherSecretary", otherSecretary);
            fixture.put("observerAdmin", observerAdmin);
            fixture.put("observerSecretary", observerSecretary);
            fixture.put("otherPatient", otherPatient);
            fixture.put("sanctionPatient", sanctionPatient);
            fixture.put("historyPatient", historyPatient);
            fixture.put("codEsp", esp);
            fixture.put("codMed", med);
            fixture.put("fecha", date);
            fixture.put("today", today);
            fixture.put("todayPlus14", scalar(db, "SELECT (public.fecha_clinica()+14)::text"));
            fixture.put("todayPlus15", scalar(db, "SELECT (public.fecha_clinica()+15)::text"));
            fixture.put("weekday", scalar(db, "SELECT EXTRACT(ISODOW FROM public.fecha_clinica()+1)::text"));
            fixture.put("codCitQr", qrAppointment);
            fixture.put("codCitAbsence", absenceAppointment);
            fixture.put("tokenQr", scalar(db, "SELECT token_qr FROM public.citas WHERE cod_cit=?", qrAppointment));
            fixture.put("demoKey", secret());
            fixture.put("laravelKey", "base64:" + secret());
            fixture.put("staffPassword", "F12aA1!" + secret());
            db.commit();
        }
        Path output = Path.of("target/integration-fase12/browser-fixture.json");
        Files.createDirectories(output.getParent());
        new ObjectMapper().writeValue(output.toFile(), fixture);
        System.out.println("Fase 12 private fixtures created. No credentials or QR are printed.");
    }

    private static void historical(Connection db, String slot, String appointment, String dateExpression) throws Exception {
        // Fixture-only aging of a legitimately created reservation, following Fase 9's historical-fixture strategy.
        // Never used by an HTTP route. All constraints, triggers, audit and real QR generation remain enabled.
        scalar(db, "UPDATE public.slots_agenda SET inicio_at=(" + dateExpression + ")+hora_inicio,fin_at=(" + dateExpression + ")+hora_fin WHERE cod_slot=? RETURNING cod_slot", slot);
        scalar(db, "UPDATE public.citas SET fecha=(" + dateExpression + "),token_qr=public.crear_token_qr(cod_cit,(" + dateExpression + ")) WHERE cod_cit=? RETURNING cod_cit", appointment);
    }

    private static String slot(Connection db, String doctor, String time) throws Exception {
        return scalar(db, "SELECT cod_slot FROM public.v_agenda_disponibilidad WHERE cod_med=? AND fecha=public.fecha_clinica()+1 AND hora_inicio=CAST(? AS time) AND estado_disponibilidad='Disponible' ORDER BY cod_slot LIMIT 1", doctor, time);
    }

    private static String reserve(Connection db, String patient, String slot, String actor) throws Exception {
        return scalar(db, "SELECT public.reservar_cita(?,?,?,'Ventanilla',NULL)", patient, slot, actor);
    }

    private static Map<String, String> account(Connection db, String role, String name) throws Exception {
        String nonce = UUID.randomUUID().toString().replace("-", "");
        String email = nonce + "@example.test", password = "F12aA1!" + secret();
        String id = scalar(db, "INSERT INTO public.usuarios(nombre_completo,email,password_hash,cod_rol) VALUES (?,?,crypt(CAST(? AS text),gen_salt('bf',10)),?) RETURNING cod_usu", name, email, password, role);
        Map<String, String> result = new LinkedHashMap<>();
        result.put("id", id); result.put("email", email); result.put("password", password); result.put("name", name);
        switch (role) {
            case "ROL001" -> {
                String ci = nonce.substring(0, 14);
                result.put("ci", ci);
                result.put("codPac", scalar(db, "INSERT INTO public.pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,email_verificado) VALUES (?,?,'1990-01-01','70000000','La Paz',true) RETURNING cod_pac", id, ci));
            }
            case "ROL002" -> result.put("codSec", scalar(db, "INSERT INTO public.secretarias(cod_usu) VALUES (?) RETURNING cod_sec", id));
            case "ROL003" -> result.put("codMed", scalar(db, "INSERT INTO public.medicos(cod_usu,matricula_profesional) VALUES (?,?) RETURNING cod_med", id, nonce));
            case "ROL004" -> { }
            default -> throw new IllegalArgumentException("Unsupported role");
        }
        return result;
    }

    private static String secret() { byte[] bytes = new byte[32]; RANDOM.nextBytes(bytes); return Base64.getEncoder().encodeToString(bytes); }

    private static String scalar(Connection db, String sql, String... values) throws Exception {
        try (var query = db.prepareStatement(sql)) {
            for (int index = 0; index < values.length; index++) query.setString(index + 1, values[index]);
            try (var rows = query.executeQuery()) {
                if (!rows.next()) throw new IllegalStateException("Missing isolated fixture data.");
                return rows.getString(1);
            }
        }
    }
}
