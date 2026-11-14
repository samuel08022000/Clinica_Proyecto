# Backend clínico Quarkus

Java 25, Quarkus 3.39.4 y PostgreSQL 15. Ejecutar desde esta carpeta `.\mvnw.cmd quarkus:dev` con la conexión JDBC autorizada configurada externamente. No sobrescribir .env. El puerto habitual de API es 8080; PostgreSQL Docker publica 5433 por defecto.

Flyway aplica B9 y R en bases vacías sin cuentas demo. Las V históricas siguen disponibles para validar bases anteriores. No modificar sus checksums ni ejecutar los SQL manualmente. Si se aplicó la V3 local, conservar su archivo original en db/dev-migration y resolver esa ubicación también al cambiar de perfil; no regenerarla. No existen generadores nuevos de contraseñas compartidas.

Documentación vigente:

- [Guía completa de migraciones](src/main/resources/db/migration/README.md).
- [Compatibilidad V3 local](db/dev-migration/README.md).
- [Arranque web y pruebas](../docs/ejecucion-integracion.md).
- [DBeaver y alta inicial propuesta](../docs/configuracion-dbeaver.md).
- [Informe Fase 13](../docs/fase13-auditoria.md).
- [SMTP real](docs/gmail-smtp.md) y [Google OIDC](docs/google-oauth.md).

Los endpoints de ejemplo se retiraron. GET /api/v1/auth/me sin Bearer devuelve 401 y permite comprobar que responde el servidor sin inventar una sesión. Autenticación, perfil activo y autorización se mantienen. Las herramientas OTP demo requieren perfil dev/test/integration, habilitación explícita, clave privada y loopback; no se registran en el paquete habitual. El proveedor por defecto está deshabilitado y SMTP requiere configuración real; solo tests usan mock por defecto.

Para verificar compilación, JUnit y paquete con PostgreSQL aislado, usar scripts/integration/Invoke-Verification.ps1 -Mode Backend desde la raíz, después de Start-Database.ps1. La prueba FlywayCompatibilityTest exige el puerto exclusivo 35441; no apuntar Maven verify a una base habitual. Las fixtures de src/test no se empaquetan para ejecución normal.
