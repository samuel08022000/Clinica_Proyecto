package org.clinica.service;

import io.agroal.api.AgroalDataSource;
import io.quarkus.elytron.security.common.BcryptUtil;
import io.quarkus.test.junit.QuarkusTest;
import jakarta.inject.Inject;
import org.eclipse.microprofile.config.inject.ConfigProperty;
import org.flywaydb.core.Flyway;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;

import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.SQLException;
import java.util.UUID;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.junit.jupiter.api.Assertions.assertThrows;

@QuarkusTest
class AgendaMigrationUpgradeTest {
    @Inject AgroalDataSource datasource;
    @ConfigProperty(name = "quarkus.datasource.username") String username;
    @ConfigProperty(name = "quarkus.datasource.password") Optional<String> configuredPassword;

    @ParameterizedTest
    @ValueSource(strings = {"4", "5"})
    void upgradesExistingAppointmentsAndBlocksFromPreviousVersions(String previousVersion) throws Exception {
        String password = configuredPassword.orElse("");
        String database = "clinica_upgrade_" + UUID.randomUUID().toString().replace("-", "");
        String jdbcUrl;
        try (Connection current = datasource.getConnection(); var statement = current.createStatement()) {
            String currentUrl = current.getMetaData().getURL();
            int query = currentUrl.indexOf('?');
            String base = query < 0 ? currentUrl : currentUrl.substring(0, query);
            jdbcUrl = base.substring(0, base.lastIndexOf('/') + 1) + database;
            statement.execute("CREATE DATABASE " + database);
        }
        Flyway.configure().dataSource(jdbcUrl, username, password).locations("classpath:db/migration")
                .target(previousVersion).load().migrate();
        String suffix = UUID.randomUUID().toString();
        String hash = BcryptUtil.bcryptHash(suffix);
        String legacyBlock;
        String appointment;
        String slot;
        try (Connection connection = DriverManager.getConnection(jdbcUrl, username, password)) {
            connection.setAutoCommit(false);
            String admin = user(connection, suffix + "a@example.test", hash, "ROL004");
            String doctorUser = user(connection, suffix + "m@example.test", hash, "ROL003");
            String patientUser = user(connection, suffix + "p@example.test", hash, "ROL001");
            String doctor = scalar(connection, "INSERT INTO public.medicos(cod_usu,matricula_profesional) VALUES (?,?) RETURNING cod_med", doctorUser, suffix);
            String patient = scalar(connection, """
                    INSERT INTO public.pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,email_verificado)
                    VALUES (?, ?, DATE '1990-01-01', '+59170000001', 'La Paz', true) RETURNING cod_pac
                    """, patientUser, suffix.substring(0, 16));
            String specialty = scalar(connection, "INSERT INTO public.especialidades(nombre_especialidad,duracion_consulta_min) VALUES (?,30) RETURNING cod_esp", suffix);
            scalar(connection, "INSERT INTO public.medico_especialidades(cod_med,cod_esp) VALUES (?,?) RETURNING cod_med", doctor, specialty);
            scalar(connection, """
                    INSERT INTO public.horarios(cod_med,dia_semana,hora_inicio,hora_fin)
                    VALUES (?,CAST(EXTRACT(ISODOW FROM public.fecha_clinica()+1) AS smallint),TIME '08:00',TIME '10:00') RETURNING cod_hor
                    """, doctor);
            slot = scalar(connection, "SELECT cod_slot FROM public.slots_agenda WHERE cod_med=? ORDER BY inicio_at LIMIT 1", doctor);
            appointment = scalar(connection, "SELECT public.reservar_cita(?,?,?,'Admin',NULL)", patient, slot, admin);
            legacyBlock = scalar(connection, """
                    INSERT INTO public.bloqueos_agenda(cod_med,cod_usu_admin,fecha_bloqueo,motivo)
                    SELECT ?,?,fecha,'Bloqueo anterior a V5' FROM public.slots_agenda WHERE cod_slot=? RETURNING cod_blo
                    """, doctor, admin, slot);
            connection.commit();
        }
        Flyway.configure().dataSource(jdbcUrl, username, password).locations("classpath:db/migration").load().migrate();
        try (Connection connection = DriverManager.getConnection(jdbcUrl, username, password)) {
            assertEquals("Afectada/Pendiente Reprogramación", scalar(connection, "SELECT estado FROM public.citas WHERE cod_cit=?", appointment));
            assertEquals("08:30:00", scalar(connection, "SELECT hora_fin::text FROM public.slots_agenda WHERE cod_slot=?", slot));
            assertEquals("5".equals(previousVersion), Boolean.parseBoolean(scalar(connection,
                    "SELECT trazabilidad_completa::text FROM public.bloqueos_agenda WHERE cod_blo=?", legacyBlock)));
            assertEquals("5".equals(previousVersion) ? "1" : "0", scalar(connection,
                    "SELECT count(*)::text FROM public.bloqueos_citas_afectadas WHERE cod_blo=?", legacyBlock));
            assertTrue(Integer.parseInt(scalar(connection, "SELECT count(*)::text FROM public.bitacora WHERE nombre_tabla='citas' AND clave_registro=?", appointment)) > 0);
            assertEquals("P4030", assertThrows(SQLException.class, () -> scalar(connection,
                    "SELECT public.reservar_cita('PAC999999','SLT99999999','USU999999','Ventanilla',NULL)")).getSQLState());
        }
    }

    private static String user(Connection connection, String email, String hash, String role) throws Exception {
        return scalar(connection, "INSERT INTO public.usuarios(nombre_completo,email,password_hash,cod_rol) VALUES ('Usuario migración',?,?,?) RETURNING cod_usu", email, hash, role);
    }

    private static String scalar(Connection connection, String sql, String... values) throws Exception {
        try (var statement = connection.prepareStatement(sql)) {
            for (int index = 0; index < values.length; index++) {
                statement.setString(index + 1, values[index]);
            }
            try (var result = statement.executeQuery()) {
                assertTrue(result.next());
                return result.getString(1);
            }
        }
    }
}
