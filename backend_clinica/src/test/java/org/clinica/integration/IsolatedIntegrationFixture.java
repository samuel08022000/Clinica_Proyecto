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

/** Herramienta opt-in de fixtures. Nunca se carga como endpoint ni usa la base configurada de desarrollo. */
public final class IsolatedIntegrationFixture {
    private static final SecureRandom RANDOM = new SecureRandom();

    public static void main(String[] args) throws Exception {
        String jdbc = System.getenv("CLINICA_TEST_JDBC_URL");
        if (jdbc == null || !jdbc.matches("jdbc:postgresql://127\\.0\\.0\\.1:35441/clinica_fase9_live(?:_[a-z0-9]+)?")) {
            throw new IllegalArgumentException("Solo se permite la base aislada de integración de Fase 9.");
        }
        Path output = Path.of("target/integration-fase9/fixture.json").toAbsolutePath().normalize();
        if (args.length != 0) throw new IllegalArgumentException("No se admiten rutas ni credenciales en argumentos.");
        try (Connection db = DriverManager.getConnection(jdbc, System.getenv("CLINICA_TEST_DB_USER"), System.getenv("CLINICA_TEST_DB_PASSWORD"))) {
            if (db.getMetaData().getDatabaseMajorVersion() != 15) throw new IllegalStateException("Se requiere PostgreSQL 15.");
            if (!"0".equals(scalar(db, "SELECT count(*)::text FROM information_schema.tables WHERE table_schema='public'"))) {
                throw new IllegalStateException("El bootstrap requiere la base aislada vacía recién creada.");
            }
        }
        Flyway.configure().dataSource(jdbc, System.getenv("CLINICA_TEST_DB_USER"), System.getenv("CLINICA_TEST_DB_PASSWORD"))
                .locations("filesystem:src/main/resources/db/migration").baselineOnMigrate(false).outOfOrder(false)
                .load().migrate();
        Map<String, Object> fixture = new LinkedHashMap<>();
        try (Connection db = DriverManager.getConnection(jdbc, System.getenv("CLINICA_TEST_DB_USER"), System.getenv("CLINICA_TEST_DB_PASSWORD"))) {
            if (db.getMetaData().getDatabaseMajorVersion() != 15) throw new IllegalStateException("Se requiere PostgreSQL 15.");
            db.setAutoCommit(false);
            Map<String, Map<String, String>> users = new LinkedHashMap<>();
            for (String role : new String[]{"ROL001", "ROL002", "ROL003", "ROL004"}) users.put(role, account(db, role));
            Map<String, String> sanctionPatient = account(db, "ROL001");
            Map<String, String> qrPatient = account(db, "ROL001");
            String admin = users.get("ROL004").get("id");
            scalar(db, "SELECT set_config('app.cod_usu',?,true)", admin);
            String med = users.get("ROL003").get("codMed");
            String esp = scalar(db, "INSERT INTO public.especialidades(nombre_especialidad,duracion_consulta_min,descripcion) VALUES (?,30,'Catálogo ficticio de integración') RETURNING cod_esp", "Integración " + UUID.randomUUID());
            scalar(db, "INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (?,?) RETURNING cod_med", med, esp);
            scalar(db, "INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin,pausa_inicio,pausa_fin) VALUES (?,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),TIME '08:00',TIME '12:00',TIME '10:00',TIME '10:30') RETURNING cod_hor", med);
            String date = scalar(db, "SELECT (public.fecha_clinica()+1)::text");
            String slot = scalar(db, "SELECT cod_slot FROM public.slots_agenda WHERE cod_med=? AND fecha=public.fecha_clinica()+1 ORDER BY inicio_at LIMIT 1", med);
            String cita = scalar(db, "SELECT public.reservar_cita(?,?,?,'Web',NULL)", qrPatient.get("codPac"), slot, qrPatient.get("id"));
            // Representa una cita preexistente de hoy, sin desactivar triggers o cambiar la ventana de reserva.
            scalar(db, "UPDATE public.slots_agenda SET inicio_at=public.fecha_clinica()+hora_inicio,fin_at=public.fecha_clinica()+hora_fin WHERE cod_slot=? RETURNING cod_slot", slot);
            scalar(db, "UPDATE public.citas SET fecha=public.fecha_clinica(),token_qr=public.crear_token_qr(cod_cit,public.fecha_clinica()) WHERE cod_cit=? RETURNING cod_cit", cita);
            String qr = scalar(db, "SELECT token_qr FROM public.citas WHERE cod_cit=?", cita);
            fixture.put("apiUrl", "http://127.0.0.1:18090/api/v1");
            fixture.put("laravelUrl", "http://127.0.0.1:18091");
            fixture.put("database", jdbc.substring(jdbc.lastIndexOf('/') + 1));
            fixture.put("postgresVersion", db.getMetaData().getDatabaseProductVersion());
            fixture.put("users", users);
            fixture.put("sanctionPatient", sanctionPatient);
            fixture.put("qrPatient", qrPatient);
            fixture.put("codEsp", esp);
            fixture.put("codMed", med);
            fixture.put("fecha", date);
            fixture.put("codCitQr", cita);
            fixture.put("tokenQr", qr);
            fixture.put("demoKey", secret());
            fixture.put("laravelKey", "base64:" + secret());
            db.commit();
        }
        Files.createDirectories(output.getParent());
        new ObjectMapper().writeValue(output.toFile(), fixture);
        System.out.println("Fixtures ficticios creados en la base aislada PostgreSQL 15; no se imprimen credenciales ni QR.");
    }

    private static Map<String, String> account(Connection db, String role) throws Exception {
        String nonce = UUID.randomUUID().toString();
        String email = nonce + "@example.test", password = secret();
        String id = scalar(db, "INSERT INTO public.usuarios(nombre_completo,email,password_hash,cod_rol) VALUES ('Usuario de Integración',?,crypt(CAST(? AS text),gen_salt('bf',10)),?) RETURNING cod_usu", email, password, role);
        Map<String, String> result = new LinkedHashMap<>();
        result.put("id", id); result.put("email", email); result.put("password", password);
        switch (role) {
            case "ROL001" -> result.put("codPac", scalar(db, "INSERT INTO public.pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,email_verificado) VALUES (?,?,'1990-01-01','70000000','La Paz',true) RETURNING cod_pac", id, nonce.substring(0, 18)));
            case "ROL002" -> result.put("codSec", scalar(db, "INSERT INTO public.secretarias(cod_usu) VALUES (?) RETURNING cod_sec", id));
            case "ROL003" -> result.put("codMed", scalar(db, "INSERT INTO public.medicos(cod_usu,matricula_profesional) VALUES (?,?) RETURNING cod_med", id, nonce));
            case "ROL004" -> { }
            default -> throw new IllegalArgumentException("Rol no permitido");
        }
        return result;
    }

    private static String secret() { byte[] bytes = new byte[32]; RANDOM.nextBytes(bytes); return Base64.getEncoder().encodeToString(bytes); }

    private static String scalar(Connection db, String sql, String... values) throws Exception {
        try (var query = db.prepareStatement(sql)) {
            for (int i = 0; i < values.length; i++) query.setString(i + 1, values[i]);
            try (var rows = query.executeQuery()) {
                if (!rows.next()) throw new IllegalStateException("Falta un dato del fixture aislado.");
                return rows.getString(1);
            }
        }
    }
}
