# Sistema de Control de Citas Médicas — UNIFRANZ 2026

Arquitectura: navegador → Laravel 13 / Blade → Quarkus 3.39.4 / Java 25 → PostgreSQL 15. PHP de ejecución: 8.5. PostgreSQL corre en Docker; DBeaver permite revisar sus datos.

El backend valida roles y perfiles activos, emite sesiones opacas, integra Google OAuth, OTP y recuperación por correo, y administra personal, especialidades, agendas, reservas, QR, recepción, cancelaciones, historial y sanciones. Los paneles de Administración y Secretaría consultan la API real. Los inicios de Paciente y Médico muestran la cuenta autenticada; no equivalen a paneles operativos completos.

## Inicio y documentación vigente

- [Arranque web e integración aislada](docs/ejecucion-integracion.md).
- [Conexión y consultas seguras con DBeaver](docs/configuracion-dbeaver.md).
- [Todas las migraciones Flyway: V1–V9, V3 local, R y B9](backend_clinica/src/main/resources/db/migration/README.md).
- [Auditoría y resultados de Fase 13](docs/fase13-auditoria.md).
- [SMTP](backend_clinica/docs/gmail-smtp.md) y [Google OAuth](backend_clinica/docs/google-oauth.md).

Configurar únicamente archivos locales privados cuando no existan; nunca sobrescribir .env existentes. No hay cuentas ni contraseñas predeterminadas para instalaciones nuevas. El primer administrador requiere alta individual autorizada: ver la propuesta de la guía DBeaver. Administración crea los perfiles de personal posteriores.

## Base de datos y seguridad

Desde la raíz, comprobar Docker y ejecutar `docker compose up -d postgres_db`. Verificar el puerto mediante `docker compose port postgres_db 5432`: el valor habitual de host es 5433. Iniciar Quarkus en backend_clinica con `.\mvnw.cmd quarkus:dev`, Laravel en frontend_clinica con `php artisan serve --host=127.0.0.1 --port=8000` y Vite en otra terminal con `npm.cmd run dev`. Revisar antes la URL JDBC: Flyway migra al iniciar. Laravel nunca migra las tablas clínicas.

B9 instala el esquema funcional sin V2/V3 en bases vacías. V1, V2, V4–V9 y R se conservan intactas para instalaciones históricas; la V3 local se conserva si fue aplicada. Las cuentas existentes no se eliminan ni se rotan automáticamente. No ejecutar SQL histórico manualmente, clean, repair para ocultar errores ni eliminar volúmenes de datos.

Roles: ROL001 Paciente, ROL002 Secretario, ROL003 Médico (nombre Doctor en el catálogo), ROL004 Administrador. No cambiar roles directamente sin componer sus perfiles. SMTP y Google requieren credenciales externas individuales. El proveedor OTP en memoria es una herramienta explícita de desarrollo/test/integration y no confirma envíos reales; el paquete habitual no registra sus endpoints.

## Verificación

Conservar JUnit, PHPUnit, Http::fake, fixtures y pruebas de concurrencia. La integración usa un Compose exclusivo con tmpfs en 35441, credenciales aleatorias y guardas que rechazan bases habituales. Ver comandos y cierre seguro en la guía de ejecución. Los reportes privados en target no deben publicarse porque pueden contener material de prueba sensible.

Equipo: Samuel Freddy Alcón Tinta, Angela Victoria Maqui Quispe, Dilan Jhessmar Frontanilla Centellas y Yesid Freddy Hualpino Mamani.
