package org.clinica.service;

import io.agroal.api.AgroalDataSource;
import io.quarkus.test.junit.QuarkusTest;
import jakarta.inject.Inject;
import org.eclipse.microprofile.config.inject.ConfigProperty;
import org.flywaydb.core.Flyway;
import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.DriverManager;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

import static org.junit.jupiter.api.Assertions.*;

/** A/B/C: bases exclusivas, historia original sin repair y esquema comparable. */
@QuarkusTest
class FlywayCompatibilityTest {
    @Inject AgroalDataSource datasource;
    @ConfigProperty(name = "quarkus.datasource.username") String username;
    @ConfigProperty(name = "quarkus.datasource.password") Optional<String> password;

    @Test
    void emptyDatabaseHasNoDemoAndMatchesHistoricalSchemaWithoutChangingExistingData() throws Exception {
        String fresh = database("clinica_f13_new_");
        String legacy = database("clinica_f13_old_");
        Path history = historicalLocation();
        try {
            Flyway cleanStart = Flyway.configure().dataSource(fresh, username, password.orElse(""))
                    .locations("classpath:db/migration", "filesystem:db/dev-migration")
                    .placeholders(Map.of("environment", "development")).outOfOrder(true)
                    .baselineOnMigrate(false).cleanDisabled(true).load();
            assertEquals(2, cleanStart.migrate().migrationsExecuted); // B9 + R
            cleanStart.validate();
            try (Connection db = connect(fresh)) {
                assertEquals("0", scalar(db, "SELECT count(*) FROM public.usuarios"));
                for (String table : List.of("pacientes", "secretarias", "medicos", "especialidades", "horarios", "slots_agenda", "citas")) {
                    assertEquals("0", scalar(db, "SELECT count(*) FROM public." + table), table);
                }
                assertEquals("4", scalar(db, "SELECT count(*) FROM public.roles WHERE cod_rol IN ('ROL001','ROL002','ROL003','ROL004')"));
                assertEquals("1", scalar(db, "SELECT count(*) FROM public.flyway_schema_history WHERE type='SQL_BASELINE' AND version='9' AND success"));
                assertEquals("0", scalar(db, "SELECT count(*) FROM public.flyway_schema_history WHERE version='2'"));
            }

            // Reproduce an installation that actually ran original V1/V2/V4 and R.
            Flyway.configure().dataSource(legacy, username, password.orElse(""))
                    .locations("filesystem:" + history).target("4").load().migrate();
            List<String> accounts;
            List<String> oldHistory;
            try (Connection db = connect(legacy)) {
                accounts = rows(db, "SELECT row_to_json(u)::text FROM public.usuarios u ORDER BY cod_usu");
                assertEquals(4, accounts.size());
                oldHistory = rows(db, "SELECT version || ':' || checksum FROM public.flyway_schema_history WHERE version IS NOT NULL ORDER BY installed_rank");
            }
            Flyway upgraded = flyway(legacy, "classpath:db/migration");
            upgraded.migrate();
            upgraded.validate();
            try (Connection old = connect(legacy); Connection current = connect(fresh)) {
                assertTrue(accounts.equals(rows(old, "SELECT row_to_json(u)::text FROM public.usuarios u ORDER BY cod_usu")), "Existing accounts and hashes must remain byte-for-byte unchanged");
                assertEquals(oldHistory, rows(old, "SELECT version || ':' || checksum FROM public.flyway_schema_history WHERE version IN ('1','2','4') ORDER BY installed_rank"));
                assertEquals("0", scalar(old, "SELECT count(*) FROM public.flyway_schema_history WHERE type='SQL_BASELINE'"));
                // Definitions, columns, restrictions, indexes and triggers must be identical.
                for (String query : schemaQueries()) assertEquals(rows(old, query), rows(current, query));
            }
            assertEquals(0, upgraded.migrate().migrationsExecuted);
        } finally {
            drop(fresh);
            drop(legacy);
            deleteTemporaryHistory(history);
        }
    }

    @Test
    void installedLocalV3RemainsResolvableAndIsNeverReplayed() throws Exception {
        Path local = Path.of("db/dev-migration/V3__corregir_credenciales_usuarios_demo.sql");
        // A clone without V3 still checks version 3 resolution using a credential-free test migration.
        Path history = historicalLocation();
        boolean originalLocal = Files.isRegularFile(local);
        if (!originalLocal) Files.writeString(history.resolve(local.getFileName()), "SELECT 1;\n");
        String jdbc = database(originalLocal ? "db_clinica" : "clinica_f13_v3_", originalLocal);
        String[] historicalLocations = originalLocal
                ? new String[]{"filesystem:" + history, "filesystem:db/dev-migration"}
                : new String[]{"filesystem:" + history};
        try {
            Flyway.configure().dataSource(jdbc, username, password.orElse(""))
                    .locations(historicalLocations).placeholders(Map.of("environment", "development"))
                    .target("3").load().migrate();
            List<String> accounts;
            String checksum;
            try (Connection db = connect(jdbc)) {
                accounts = rows(db, "SELECT row_to_json(u)::text FROM public.usuarios u ORDER BY cod_usu");
                checksum = scalar(db, "SELECT checksum::text FROM public.flyway_schema_history WHERE version='3'");
            }
            String[] locations = originalLocal
                    ? new String[]{"classpath:db/migration", "filesystem:db/dev-migration"}
                    : new String[]{"classpath:db/migration", "filesystem:" + onlyV3(history)};
            Flyway finalHistory = Flyway.configure().dataSource(jdbc, username, password.orElse(""))
                    .locations(locations).placeholders(Map.of("environment", "development")).load();
            finalHistory.migrate();
            finalHistory.validate();
            try (Connection db = connect(jdbc)) {
                assertEquals(checksum, scalar(db, "SELECT checksum::text FROM public.flyway_schema_history WHERE version='3'"));
                assertTrue(accounts.equals(rows(db, "SELECT row_to_json(u)::text FROM public.usuarios u ORDER BY cod_usu")), "V3 must not replay or change accounts");
            }
        } finally {
            drop(jdbc);
            deleteTemporaryHistory(history);
        }
    }

    private Flyway flyway(String jdbc, String... locations) {
        return Flyway.configure().dataSource(jdbc, username, password.orElse(""))
                .locations(locations).baselineOnMigrate(false).cleanDisabled(true).load();
    }

    private String database(String prefix) throws Exception { return database(prefix, false); }

    private String database(String prefix, boolean exact) throws Exception {
        try (Connection db = datasource.getConnection(); var sql = db.createStatement()) {
            String current = db.getMetaData().getURL();
            // This test may create/drop databases only on the explicitly isolated integration server.
            if (!current.matches("jdbc:postgresql://127\\.0\\.0\\.1:35441/clinica_fase12_tests(?:\\?.*)?")) {
                throw new IllegalStateException("Flyway compatibility tests require the exclusive server on 35441.");
            }
            String name = exact ? prefix : prefix + UUID.randomUUID().toString().replace("-", "");
            sql.execute("CREATE DATABASE " + name); // No IF EXISTS: refuse to reuse any database.
            return "jdbc:postgresql://127.0.0.1:35441/" + name;
        }
    }

    private Connection connect(String jdbc) throws Exception { return DriverManager.getConnection(jdbc, username, password.orElse("")); }

    private void drop(String jdbc) throws Exception {
        if (!jdbc.matches("jdbc:postgresql://127\\.0\\.0\\.1:35441/(clinica_f13_[a-z0-9_]+|db_clinica)")) throw new IllegalStateException("Not owned");
        try (Connection db = datasource.getConnection(); var sql = db.createStatement()) {
            sql.execute("DROP DATABASE " + jdbc.substring(jdbc.lastIndexOf('/') + 1));
        }
    }

    private Path historicalLocation() throws Exception {
        Path directory = Files.createTempDirectory(Path.of("target"), "f13-history-");
        try (var files = Files.list(Path.of("src/main/resources/db/migration"))) {
            for (Path file : files.filter(p -> p.getFileName().toString().matches("[VR]__.*|V[0-9]+__.*\\.sql")).toList()) {
                Files.copy(file, directory.resolve(file.getFileName()));
            }
        }
        return directory;
    }

    private Path onlyV3(Path history) throws Exception {
        Path local = Files.createDirectory(history.resolve("local"));
        Files.move(history.resolve("V3__corregir_credenciales_usuarios_demo.sql"), local.resolve("V3__corregir_credenciales_usuarios_demo.sql"));
        return local;
    }

    private void deleteTemporaryHistory(Path history) throws Exception {
        try (var files = Files.walk(history)) {
            for (Path file : files.sorted(java.util.Comparator.reverseOrder()).toList()) Files.delete(file);
        }
    }

    private static String scalar(Connection db, String query) throws Exception { return rows(db, query).getFirst(); }
    private static List<String> rows(Connection db, String query) throws Exception {
        List<String> result = new ArrayList<>();
        try (var sql = db.createStatement(); var rows = sql.executeQuery(query)) {
            while (rows.next()) result.add(rows.getString(1).replace("\r\n", "\n"));
        }
        return result;
    }

    private static List<String> schemaQueries() {
        return List.of(
                "SELECT table_name||':'||column_name||':'||data_type||':'||is_nullable||':'||coalesce(column_default,'') FROM information_schema.columns WHERE table_schema='public' AND table_name<>'flyway_schema_history' ORDER BY table_name,ordinal_position",
                "SELECT c.relname||':'||pg_get_constraintdef(p.oid) FROM pg_constraint p JOIN pg_class c ON c.oid=p.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname<>'flyway_schema_history' ORDER BY 1",
                "SELECT indexdef FROM pg_indexes WHERE schemaname='public' AND tablename<>'flyway_schema_history' ORDER BY indexdef",
                "SELECT pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY 1",
                "SELECT pg_get_functiondef(p.oid) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.prolang=(SELECT oid FROM pg_language WHERE lanname='plpgsql') ORDER BY p.proname,pg_get_function_identity_arguments(p.oid)",
                "SELECT viewname||':'||definition FROM pg_views WHERE schemaname='public' ORDER BY viewname");
    }
}
