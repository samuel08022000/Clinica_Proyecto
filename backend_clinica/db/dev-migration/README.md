# Compatibilidad con la V3 local histórica

Esta carpeta se resuelve únicamente en Quarkus dev. Si flyway_schema_history contiene V3, conservar exactamente su SQL local original: los hashes generados por equipo producen checksums diferentes. No versionarlo ni imprimirlo. Fuera de dev, una base con V3 requiere incluir explícitamente su ubicación original mediante QUARKUS_FLYWAY_LOCATIONS; no ignorar migraciones faltantes.

Fase 13 retiró prepare-demo-credentials.ps1, prepare_demo_credentials.php y su plantilla. No crear nuevas credenciales compartidas. El archivo local existente no se ha modificado ni eliminado. B9 instala bases nuevas sin V2/V3 y conserva las migraciones históricas para validar las anteriores.

El perfil dev mantiene out-of-order=true y environment=development para compatibilidad. Si V3 está pendiente en una base histórica, podría ejecutarse y cambiar los cuatro hashes demo: revisar el historial antes del arranque. No regenerar, mover ni sustituir una V3 ya aplicada. La prueba aislada cubre su aplicación original y validación posterior sin reutilizar cuentas habituales.

Inventario, dependencias y procedimiento seguro: [guía completa de migraciones](../../src/main/resources/db/migration/README.md).
