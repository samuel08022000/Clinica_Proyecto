package org.clinica.service;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Comparator;

/** Replay original versions in upgrade tests; B9 intentionally represents the current schema. */
final class HistoricalMigrations implements AutoCloseable {
    private final Path directory;

    HistoricalMigrations() throws Exception {
        directory = Files.createTempDirectory(Path.of("target"), "historical-migrations-");
        try (var files = Files.list(Path.of("src/main/resources/db/migration"))) {
            for (Path file : files.filter(p -> p.getFileName().toString().matches("(?:V[0-9]+|R)__.*\\.sql")).toList()) {
                Files.copy(file, directory.resolve(file.getFileName()));
            }
        }
    }

    String location() { return "filesystem:" + directory; }

    @Override
    public void close() throws Exception {
        try (var files = Files.walk(directory)) {
            for (Path file : files.sorted(Comparator.reverseOrder()).toList()) Files.delete(file);
        }
    }
}
