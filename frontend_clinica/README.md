# Frontend clínico Laravel 13 + PostgreSQL 16

Aplicación principal del **Sistema de Control de Citas Médicas** sobre **PHP 8.5**, **Laravel 13**, **Laravel Blade**, **Vite** y **PostgreSQL 16** (conexión `clinical`).

## Instalación local de dependencias

Desde `frontend_clinica/`:

```powershell
composer install
npm.cmd ci
npm.cmd run build
```

Configurar un archivo `.env` local a partir de `.env.example` únicamente si no existe (`php artisan key:generate`). Las migraciones SQLite locales (`php artisan migrate`) gestionan exclusivamente sesiones, caché y desafíos temporales de Laravel — **nunca migran el esquema clínico PostgreSQL**, que reside en [../database_clinica/migration/](../database_clinica/migration/).

## Ejecución con PostgreSQL 16 clínico aislado

Desde la raíz del proyecto en Windows PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Start-Database.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Invoke-Laravel.ps1 serve --host=127.0.0.1 --port=8000
```

`scripts/clinical/Invoke-Laravel.ps1` inyecta en memoria las credenciales privadas de `.local/clinical-phase1/` para la conexión `clinical` sin exponer secretos en archivos versionados ni argumentos.

## Pruebas y calidad

- Suite Feature: `php vendor/bin/phpunit`
- Suite PostgreSQL real aislada (desde raíz): `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres`
- Estilo PHP: `php vendor/bin/pint --test`
- Rutas y vistas: `php artisan route:list`, `php artisan view:cache`, `npm.cmd run build`

Ver [README principal](../README.md), [documentación de base de datos](../database_clinica/README.md) y [guía DBeaver](../docs/configuracion-dbeaver.md).
