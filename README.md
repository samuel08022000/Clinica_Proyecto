# Sistema de Control de Citas Médicas — UNIFRANZ 2026

## Qué es el proyecto

Plataforma web integral para la gestión clínica de especialidades, médicos, secretarías, agendas, reservas, check-in por código QR, atenciones médicas, análisis, derivaciones, historial y sanciones temporales.

## Arquitectura definitiva

`Navegador → Laravel 13 / Blade + Vite → Controladores Laravel → Servicios PHP → PostgreSQL 16`

- **Único framework de aplicación:** Laravel (Blade, autenticación, autorización por roles, servicios de negocio, tareas programadas y acceso parametrizado a PostgreSQL).
- **Motor clínico:** PostgreSQL 16 (`America/La_Paz`) con **21 tablas clínicas**, **2 vistas**, **42 funciones**, **32 triggers** y restricciones de exclusión que garantizan integridad transaccional y control de concurrencia.
- **Roles:** `ROL001` Paciente, `ROL002` Secretaría, `ROL003` Médico, `ROL004` Administrador (sobre la identidad única `usuarios`).

---

## Requisitos verificados

- **PHP `8.5`** (verificado en `8.5.10`) con extensiones `pdo_pgsql`, `pdo_sqlite`, `mbstring`, `openssl`, `dom`, `xml`, `fileinfo`.
- **Composer 2**.
- **Node.js + npm** (verificado en Node `v26.13.1`, Vite `v8.3.5`).
- **Docker Desktop / Docker Engine** con imagen `postgres:16-alpine` disponible localmente.
- *(No se requiere Java, JDK ni Maven.)*

---

## Instalación limpia en Windows PowerShell

Ejecutar desde la raíz del repositorio (`Proyecto Clinica/`):

1. **Clonar el proyecto** y abrir una terminal PowerShell en la raíz.
2. **Configurar archivos `.env` privados** (sin sobrescribir existentes si ya existen):
   ```powershell
   Copy-Item frontend_clinica\.env.example frontend_clinica\.env
   ```
   Ajustar `APP_KEY` dentro de `frontend_clinica/.env` si está vacío:
   ```powershell
   Push-Location frontend_clinica
   php artisan key:generate --ansi
   Pop-Location
   ```
3. **Instalar dependencias de Laravel y frontend:**
   ```powershell
   Push-Location frontend_clinica
   composer install
   npm.cmd ci
   Pop-Location
   ```
4. **Inicializar credenciales privadas locales e infraestructura PostgreSQL 16 (solo la primera vez):**
   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Initialize-Environment.ps1
   ```
   *(Genera secretos aleatorios locales en `.local/clinical-phase1/` ignorado por Git.)*
5. **Iniciar PostgreSQL 16 (`compose.clinical.yml`, puerto `127.0.0.1:35443`):**
   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Start-Database.ps1
   ```
6. **Instalar el esquema clínico (`B9 → V10 → R` desde `database_clinica/migration/`) y aplicar permisos mínimos para `clinica_app`:**
   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Install-Schema.ps1
   ```
7. **Validar la integridad del esquema y migraciones:**
   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Install-Schema.ps1 -ValidateOnly
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Verify-Database.ps1
   ```
8. **Ejecutar migraciones de infraestructura local Laravel** *(sesiones/caché en SQLite local; **nunca** contra PostgreSQL clínico)*:
   ```powershell
   Push-Location frontend_clinica
   php artisan migrate
   Pop-Location
   ```
9. **Compilar los activos Vite para producción (o `npm.cmd run dev` en desarrollo):**
   ```powershell
   Push-Location frontend_clinica
   npm.cmd run build
   Pop-Location
   ```
10. **Iniciar Laravel conectado a PostgreSQL 16 clínico:**
    ```powershell
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Invoke-Laravel.ps1 serve --host=127.0.0.1 --port=8000
    ```

    **Si todas las páginas aparecen sin estilos:** revisar `frontend_clinica/public/hot`.
    Cuando existe, Laravel carga CSS y JavaScript desde el servidor Vite indicado allí,
    incluso si ya existe `public/build/manifest.json`. Mantener `npm.cmd run dev`
    activo para ese modo. Si se usarán los recursos compilados, comprobar que Vite
    está detenido y que los archivos del manifiesto existen; apartar únicamente el
    archivo temporal `public/hot` obsoleto y recargar la página. No cambiar estilos,
    layouts ni limpiar sesiones para corregir este problema.

    Para detener PostgreSQL 16 de forma segura (sin borrar volúmenes):
    ```powershell
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Stop-Database.ps1
    ```

> **Notas sobre Docker y acceso:** `compose.clinical.yml` expone PostgreSQL únicamente en `127.0.0.1:35443` y contiene las bases `clinica_laravel` (principal) y `clinica_laravel_phase1_tests` (pruebas). No crea cuentas demo por defecto; consulta [configuracion-dbeaver.md](docs/configuracion-dbeaver.md) para el alta controlada de personal/administración. `docker-compose.yml` queda únicamente como referencia heredada del contenedor PostgreSQL 15 habitual (`5433`).

---

## Desarrollo en equipo

| Qué necesitas | Ubicación oficial |
|---|---|
| Backend PHP (controladores, servicios, repositorios, FormRequests, middlewares) | `frontend_clinica/app/` (`Http/Controllers`, `Services`, `Repositories`, `Http/Requests`, `Http/Middleware`) |
| Vistas Blade, CSS institucional y JS | `frontend_clinica/resources/views/`, `frontend_clinica/resources/css/`, `frontend_clinica/resources/js/` |
| Rutas web y tareas programadas | `frontend_clinica/routes/web.php`, `frontend_clinica/routes/console.php` |
| Migraciones y documentación SQL clínica (`B9 -> V10 -> R`) | `database_clinica/migration/` y `database_clinica/README.md` |
| Scripts Docker, instalación y verificación | `compose.clinical.yml` y `scripts/clinical/` |
| Auditorías, preparación de Paciente y referencia funcional clínica | `docs/` y `docs/referencia-clinica/` |

### Cómo ejecutar las pruebas

```powershell
# Suite Feature rápida (SQLite en memoria)
Push-Location frontend_clinica
php vendor/bin/phpunit
php vendor/bin/pint --test
Pop-Location

# Suite completa contra PostgreSQL 16 clínico aislado (clinica_laravel_phase1_tests)
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres
```

### Reglas de cuidado del proyecto

- **No editar migraciones SQL ya aplicadas** en `database_clinica/migration/` ni modificar checksums manualmente en `flyway_schema_history`.
- **No ejecutar `php artisan migrate:fresh` ni `migrate` apuntando a `clinical`**; `clinical` se gestiona exclusivamente desde `database_clinica/migration/` + `scripts/clinical/`.
- **No tocar ni usar como base de pruebas la base habitual anterior** (`pgdata` / PostgreSQL 15).
- **No ejecutar `docker compose down -v`** sobre `compose.clinical.yml` salvo destrucción intencionada del volumen local.

---

## Precauciones de seguridad y Git

Nunca subir al repositorio:

- Archivos `.env`, `.env.*` (salvo `.env.example` y `.env.docker.example` sin secretos).
- Directorio privado `.local/clinical-phase1/` ni contraseñas/claves HMAC.
- Respaldos externos (`.zip`, `.tar.gz`, `.dump`, `.bak`), dumps de bases reales ni logs con datos clínicos.
- Directorios de herramientas locales de agentes o editores (`.agent/`, `.agents/`, `.claude/`, `.taste-skill/`, `.vscode/`, `vendor/`, `node_modules/`).

Equipo original: Samuel Freddy Alcón Tinta, Angela Victoria Maqui Quispe, Dilan Jhessmar Frontanilla Centellas y Yesid Freddy Hualpino Mamani.
