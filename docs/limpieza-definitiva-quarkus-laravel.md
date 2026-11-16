# LIMPIEZA DEFINITIVA DE QUARKUS Y ORGANIZACIÓN FINAL DE LARAVEL + POSTGRESQL

## A. Respaldo y punto de recuperación verificado

Antes de retirar cualquier archivo histórico o de Quarkus del proyecto principal (`Proyecto Clinica`), se comprobó la existencia e integridad de la copia externa de respaldo:

- **Ubicación externa verificada:**
  - Carpeta completa fuera del directorio de trabajo: `c:\Users\LENOVO\Desktop\Proyecto Clinica`
  - Archivo comprimido adicional en ubicación externa: `c:\Users\LENOVO\Desktop\Proyecto Clinica.zip` (`431,364,360` bytes, `27,404` entradas legibles)
- **Comprobación de integridad y alcance:**
  - Presencia de repositorio Git con el mismo commit `HEAD` (`2f3b15341b03287d8ac17612f334badb3abe0342`).
  - Paridad exacta de `backend_clinica/`: **`6,116` archivos, `658,199,678` bytes** en origen y en copia externa.
  - Verificación SHA-256 archivo por archivo sobre `backend_clinica/src/`, `backend_clinica/docs/`, `backend_clinica/db/` y `backend_clinica/scripts/`: **`152` archivos comprobados, `0` discrepancias** (incluyendo `pom.xml`, servicios Java, pruebas Java y todas las migraciones `V1`–`V10`, `B9`, `R` y `V3`).
  - No se incluyeron secretos en este informe ni se subieron respaldos a Git.

---

## B. Archivos eliminados

Ejecutado únicamente después de verificar el respaldo externo y completar la instalación limpia desde `database_clinica/migration/`:

| Ruta eliminada | Alcance / Contenido retirado | Motivo |
|---|---|---|
| `backend_clinica/` *(previa preservación de `migration/`, `db/dev-migration/` y `docs/`)* | Código Java Quarkus (`src/`), `pom.xml`, Maven Wrapper (`mvnw`, `mvnw.cmd`, `.mvn/`), artefactos compilados `target/`, `Dockerfile.*`, configuración Quarkus (`.env`, `.env.example`, `.dockerignore`, `README.md`) y scripts auxiliares Java (`scripts/dev/`) | Retiro definitivo del backend Quarkus y Java |
| `scripts/clinical/ClinicalSchema.java` | Instalador heredado en Java + Flyway CLI y parámetro `-UseLegacyJavaFlyway` en `Install-Schema.ps1` | Reemplazado por `scripts/clinical/ClinicalSchemaPure.php` |
| `scripts/integration/` (`15` archivos) y `compose.integration.yml` | Harness antiguo de integración Fase 12 dependiente de `mvnw quarkus:dev`, puerto `18090`, PostgreSQL efímero `35441` y `backend_clinica/target/` | Reemplazado por `compose.clinical.yml` y la suite `tests/Postgres` |
| `frontend_clinica/tests/Integration/` (`router.php`) | Router HTTP exclusivo del harness `scripts/integration/` | Sin consumidores activos tras retirar `scripts/integration/` |
| `frontend_clinica/target/` (`0` archivos) | Directorio vacío residual | Limpieza de artefacto vacío |

> **Conservados expresamente:** aplicación `frontend_clinica/` (`vendor/`, `node_modules/`), documentación histórica y académica (`EVALUACION PROCESUAL.pdf`, `docs/fase13-*`, `docs/migracion-laravel-fase1..5*`, `docs/auditoria-laravel-seguridad-arquitectura.md`, `docs/preparacion-backend-panel-paciente.md`) y herramientas locales del entorno (`.agent/`, `.agents/`, `.taste-skill/`, `.vscode/`, `.local/clinical-phase1/`).

---

## C. Archivos trasladados y actualizados

| Origen | Destino / Cambio | Detalle |
|---|---|---|
| `backend_clinica/src/main/resources/db/migration/*` (`12` archivos: `V1`, `V2`, `V4`–`V10`, `B9`, `R`, `README.md`) | `database_clinica/migration/` | Copia binaria exacta verificada por SHA-256 (`0` discrepancias); contenido y checksums sin modificar |
| `backend_clinica/db/dev-migration/*` (`V3__corregir_credenciales_usuarios_demo.sql`, `README.md`) | `database_clinica/dev-migration/` | Preservados aparte para no interferir con `B9 -> V10 -> R` |
| `backend_clinica/docs/*.md` (`9` especificaciones funcionales de negocio) | `docs/referencia-clinica/` | Reglas funcionales de citas, check-in QR, sanciones, derivaciones, análisis e incidencias preservadas |
| `scripts/clinical/Install-Schema.ps1` | Actualizado en sitio | Usa `database_clinica/migration` y ejecuta únicamente `ClinicalSchemaPure.php` |
| `scripts/clinical/ClinicalSchemaPure.php` | Actualizado comentario de cabecera | Apunta conceptualmente a `database_clinica/migration/` sin Java ni Quarkus |
| `frontend_clinica/tests/Postgres/ClinicalInfrastructureTest.php` | Actualizado línea 51 | `base_path('../database_clinica/migration/'.$file)` |
| `docker-compose.yml` y `.env.docker.example` | Limpiados en sitio | Retirados servicios `backend_quarkus`, `frontend_laravel`, volumen `maven_repo` y variable `QUARKUS_HOST_PORT`; conservados `postgres_db` y `pgadmin` heredados con nota hacia `compose.clinical.yml` |
| `.gitignore`, `README.md`, `frontend_clinica/README.md`, `database_clinica/README.md`, `database_clinica/.gitignore` | Creados / actualizados | Protección contra `.agent/`, `.zip`, `.tar.gz`, `.dump`; guías completas de instalación y equipo sin Quarkus |

---

## D. Nueva estructura del proyecto

```text
Proyecto Clinica/
├── frontend_clinica/               # Laravel 13.33.0 + PHP 8.5.10 + Blade + Vite 8.3.5
│   ├── app/                        # Controladores, Servicios, Repositorios, FormRequests, Middlewares
│   ├── bootstrap/
│   ├── config/
│   ├── database/                   # Migraciones SQLite locales de infraestructura Laravel
│   ├── public/                     # Activos públicos y compilación Vite (build/)
│   ├── resources/                  # Vistas Blade, CSS institucional y JS
│   ├── routes/                     # web.php y console.php (maintenance slots/reception)
│   ├── tests/                      # Feature (238 tests) + Postgres (45 tests)
│   ├── composer.json
│   ├── package.json
│   └── README.md
│
├── database_clinica/               # Única fuente de verdad SQL clínica (PostgreSQL 16)
│   ├── migration/                  # B9, V10, R + histórico V1, V2, V4..V9 + README.md
│   ├── dev-migration/              # V3 histórico aislado
│   ├── .gitignore
│   └── README.md
│
├── scripts/
│   └── clinical/                   # Inicialización privada, Docker PG16, instalador PHP, permisos y Verify-Database.ps1
│
├── docs/
│   ├── referencia-clinica/         # 9 documentos funcionales preservados desde backend_clinica/docs/
│   ├── configuracion-dbeaver.md
│   ├── migracion-laravel-fase1.md .. fase5-verificacion-final.md
│   ├── auditoria-laravel-seguridad-arquitectura.md
│   ├── preparacion-backend-panel-paciente.md
│   └── limpieza-definitiva-quarkus-laravel.md
│
├── compose.clinical.yml            # Compose oficial Laravel + PostgreSQL 16 (127.0.0.1:35443)
├── docker-compose.yml              # Referencia heredada PG15 habitual (sin Quarkus)
├── .env.docker.example
├── .gitignore
└── README.md
```

---

## E. Independencia de Java y Quarkus

- **Escaneo sobre archivos activos** (`frontend_clinica/app/`, `bootstrap/`, `config/`, `routes/`, `resources/`, `scripts/`, `compose.*`, `README.md`, `database_clinica/README.md`): **0** dependencias ejecutables de `backend_clinica`, `Quarkus`, `mvnw`, `.jar`, `ClinicalSchema.java` ni rutas absolutas `C:\Users\...`. *(Las únicas ocurrencias textuales son `ClinicalSchemaPure.php:205` —`"sin Java ni Quarkus"`— y documentación histórica).*
- **Red:** puerto `8080` cerrado; únicamente escuchan puertos PostgreSQL (`35443` y `5432`).
- **Instalación limpia desde `database_clinica/migration/` antes del retiro:** ejecutada sobre la base temporal descartable `clinica_laravel_install_verify` mediante `ClinicalSchemaPure.php` + los 4 scripts `scripts/clinical/*permissions.sql`, sin Java ni Maven:
  - Resultado verificado: **`21` tablas de dominio, `2` vistas, `42` funciones clínicas, `32` triggers, `40` claves foráneas, `0` restricciones inválidas, `0` triggers deshabilitados, `3` filas en `flyway_schema_history` (`B9=772538900`, `V10=212713187`, `R=1209888159`), `0` usuarios, `QR_HMAC` (`32` bytes), privilegios mínimos `clinica_app` correctos**. La base temporal fue eliminada tras validar.
- **Sonda HTTP real posterior al retiro:** servidor PHP integrado sobre `frontend_clinica/public` conectado a `clinica_laravel` (`127.0.0.1:35443`): `/` → `HTTP 200`, `/login` → `HTTP 200`, `/registro` → `HTTP 200`, paneles protegidos (`/administracion/inicio`, `/secretaria/inicio`, `/medico/inicio`, `/paciente/inicio`) → `HTTP 302` hacia login.

---

## F. Base de datos PostgreSQL 16

Verificación final ejecutada mediante `scripts/clinical/Verify-Database.ps1` tras todas las pruebas y limpieza:

- **Contenedor:** `clinica-laravel-fase1-pg16` (`127.0.0.1:35443`, `PostgreSQL 16.15`, `America/La_Paz`).
- **Base principal:** `clinica_laravel` con **`21`** tablas de dominio, **`2`** vistas (`v_agenda_disponibilidad`, `v_historial_citas`), **`42`** funciones clínicas, **`32`** triggers, **`40`** FKs, **`80`** índices, **`2`** restricciones de exclusión, **`0`** restricciones inválidas, **`0`** triggers deshabilitados, **`0`** usuarios, `qrInitialized: true`, historial **`B9 -> V10 -> R`** (`success: true`).
- **Base de pruebas:** `clinica_laravel_phase1_tests` con paridad de esquema verificada.
- **Base habitual anterior:** conservada intacta sin alteraciones ni borrado de volúmenes (`docker compose down -v` no utilizado).

---

## G. Docker

- **`compose.clinical.yml` (activo y oficial):** levanta únicamente `postgres_clinical` (`postgres:16-alpine`, `clinica-laravel-fase1-pg16`) publicado solo en `127.0.0.1:35443`, con secreto Bootstrap en `.local/clinical-phase1/bootstrap-password.txt` (ignorado por Git) y volumen `clinica_laravel_fase1_pg16_data`. No incluye ningún servicio Quarkus.
- **`docker-compose.yml` (heredado):** conserva únicamente `postgres_db` (PG 15 habitual en `5433`) y `pgadmin` opcional (`--profile pgadmin`), habiéndose eliminado `backend_quarkus`, `frontend_laravel` y `maven_repo`.

---

## H. Pruebas y regresiones ejecutadas después de retirar Quarkus

| Verificación | Comando | Resultado exacto observado |
|---|---|---|
| Base clínica principal | `scripts/clinical/Verify-Database.ps1` | **OK** (`clinica_laravel`, PG `16.15`, `21` tablas, `2` vistas, `42` funciones, `32` triggers, `0` usuarios, `B9 -> V10 -> R`) |
| Validador puro sin Java | `scripts/clinical/Install-Schema.ps1 -ValidateOnly` | **OK** (`clinica_laravel` y `clinica_laravel_phase1_tests` validados desde `database_clinica/migration/`) |
| Suite Feature completa | `php frontend_clinica/vendor/bin/phpunit -c frontend_clinica/phpunit.xml` | **PASSED**: **`238` tests, `238` passed, `0` failed, `0` skipped, `1,521` assertions** (`71,008 ms`) |
| Suite PostgreSQL completa | `scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres` | **PASSED**: **`45` tests, `45` passed, `0` failed, `0` skipped, `708` assertions** (`778,568 ms`) |
| Estilo de código PHP (`Pint`) | `php vendor/bin/pint --test` | **PASSED** (`{"tool":"pint","result":"passed"}`) |
| Sintaxis PHP (`app/`, `routes/`, `scripts/clinical/`) | `php -l` por archivo | **`110` archivos verificados, `0` errores** |
| Composer, rutas, vistas Blade y Vite | `composer validate --strict`, `artisan route:list`, `view:cache`, `npm.cmd run build` | `./composer.json is valid`, **`Showing [97] routes`**, `Blade templates cached successfully.`, `vite v8.3.5` compilado en `3.05 s` |
| Sonda HTTP real | Servidor PHP local contra `frontend_clinica/public` + `curl.exe` | `/ => 200`, `/login => 200`, `/registro => 200`, rutas protegidas `=> 302` |

**Total tras la limpieza definitiva:** **`283` pruebas automatizadas (`238` Feature + `45` PostgreSQL), `283` aprobadas, `0` fallidas, `0` omitidas, `2,229` aserciones**.

---

## I. Seguridad y revisión de secretos

- Ningún archivo versionado (`compose.clinical.yml`, `README.md`, `database_clinica/`, `scripts/clinical/*.ps1`) contiene contraseñas ni claves reales.
- `.gitignore` protege `.env`, `/.local/clinical-phase1/`, `.agent/`, `.agents/`, `.claude/`, `.taste-skill/`, `*.zip`, `*.tar.gz`, `*.dump` y `*.bak`.
- Se conservó sin alterar el `.env` local de `frontend_clinica/` para no modificar `APP_KEY` ni configuración privada preexistente; sus antiguas claves `QUARKUS_*` ya no tienen ningún lector activo en `config/`.

---

## J. Instrucciones rápidas para compañeros de equipo

Desde la raíz (`Proyecto Clinica/`) en Windows PowerShell (guía detallada en [README.md](../README.md)):

```powershell
# 1. Instalar dependencias de Laravel y Vite
Push-Location frontend_clinica; composer install; npm.cmd ci; npm.cmd run build; Pop-Location

# 2. Preparar .env privado e inicializar infraestructura local (solo primera vez)
Copy-Item frontend_clinica\.env.example frontend_clinica\.env
Push-Location frontend_clinica; php artisan key:generate --ansi; php artisan migrate; Pop-Location
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Initialize-Environment.ps1

# 3. Levantar PostgreSQL 16, instalar esquema (B9 -> V10 -> R) y validar
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Start-Database.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Install-Schema.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Verify-Database.ps1

# 4. Iniciar aplicación web Laravel y ejecutar pruebas
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Invoke-Laravel.ps1 serve --host=127.0.0.1 --port=8000
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres
```

---

## K. Pendientes documentados

1. **Interfaz visual definitiva y controladores HTTP del panel Paciente (`ROL001`):** los servicios, `FormRequest`, middleware (`PatientResponses`) y pruebas PostgreSQL ya están implementados (`docs/preparacion-backend-panel-paciente.md`); queda para la fase visual con Codex construir las vistas Blade, rutas y controladores web del paciente sobre `/paciente/*`.
2. **Notificaciones clínicas al paciente (`RF-26`):** pendiente definir el worker de envío (`tomar_notificaciones` / `finalizar_notificacion`) y otorgar `GRANT SELECT` explícito sobre `public.notificaciones` a `clinica_app`.
3. **Google OAuth real:** se mantiene intencionalmente deshabilitado (`UnavailableGoogleIdentityProvider` fail-closed) mientras no se incorpore validación criptográfica OIDC completa (`iss`, `aud`, `exp`, firma JWKS y `nonce`).
4. **Higiene local del `.env` en producción:** ajustar `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true` bajo HTTPS y depurar las variables heredadas `QUARKUS_*` sin uso del `.env` local al desplegar.
5. **Revisión de archivos históricos en Git:** se conservaron sin cambios las eliminaciones previas del índice de documentos de requisitos antiguos y el archivo `EVALUACION PROCESUAL.pdf`; no se realizó `git commit`, `push`, `reset` ni `clean`.
