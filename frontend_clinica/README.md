# Frontend clínico Laravel Blade

PHP 8.5 y Laravel 13. La identidad y las operaciones clínicas se validan en Quarkus; Laravel conserva su Bearer en una sesión de servidor y comprueba autorización remota para cada panel.

Desde esta carpeta, instalar dependencias con `composer install` y `npm.cmd ci`. Configurar un entorno local privado solo si aún no existe. Ejecutar Laravel con `php artisan serve --host=127.0.0.1 --port=8000` y Vite en otra terminal con `npm.cmd run dev`. `QUARKUS_API_URL` debe apuntar a la API autorizada, habitualmente `http://127.0.0.1:8080/api/v1`.

El login no precarga cuentas demo. No hay credenciales administrativas predeterminadas. Los paneles de Administración y Secretaría consumen datos reales; una caída de Quarkus impide operar y muestra error. Paciente y Médico conservan sus inicios autenticados, sin anunciar un panel operativo inexistente.

No ejecutar migraciones Laravel contra PostgreSQL clínico. Se conservaron sus migraciones de infraestructura users/cache/jobs y el modelo configurado del framework; DatabaseSeeder ya no crea Test User. Los scripts Composer no ejecutan migraciones automáticamente. Las nuevas configuraciones de ejemplo usan sesiones y caché de archivo sin exigir tablas Laravel.

Pruebas: `php artisan test`, `php artisan view:cache`, `php artisan route:list`, `php vendor/bin/pint --test` y `npm.cmd run build`. Los `Http::fake` y fixtures se mantienen exclusivamente en tests. Las suites LiveBackendIntegrationTest y LiveHttpStackTest requieren activación y servidores aislados: no apuntarlas a datos habituales.

Ver [arranque e integración](../docs/ejecucion-integracion.md), [DBeaver](../docs/configuracion-dbeaver.md), [migraciones](../backend_clinica/src/main/resources/db/migration/README.md) e [informe de Fase 13](../docs/fase13-auditoria.md).
