# FASE 6 — AUDITORÍA DE INDEPENDENCIA DE QUARKUS, SEGURIDAD INTEGRAL Y ARQUITECTURA LARAVEL

## A. Dictamen general y alcance de la auditoría

Se realizó una auditoría técnica independiente del **Sistema de Control de Citas Médicas** sobre el proyecto local (`Laravel v13.33.0`, `PHP 8.5.10`, `PostgreSQL 16.15`, `Node v26.13.1`, `Vite v8.3.5`) comprobando:

1. **Independencia operativa respecto a Quarkus y Java:** la aplicación web Laravel, sus pruebas (`Feature` y `Postgres`), el esquema clínico y ahora también el **instalador de base de datos PostgreSQL** (`scripts/clinical/ClinicalSchemaPure.php`) operan íntegramente sin arrancar ni invocar Quarkus ni Java.
2. **Postura de seguridad integral:** se inspeccionaron autenticación, autorización por roles, aislamiento IDOR, validación de entradas, manejo de errores, sesiones/cookies, privilegios PostgreSQL (`clinica_app`, `clinica_owner`, funciones `SECURITY DEFINER`) y reglas de negocio; los 4 hallazgos accionables detectados fueron corregidos con pruebas de regresión en verde.
3. **Preparación backend del panel Paciente (`ROL001`):** documentada en [preparacion-backend-panel-paciente.md](file:///c:/Users/LENOVO/Desktop/PROYECTOS/Proyecto%20Clinica/docs/preparacion-backend-panel-paciente.md), sin diseñar ni modificar las pantallas visuales definitivas que implementará Codex posteriormente.

---

## B. Matriz de independencia de Quarkus y Java

| Ámbito auditado | Estado previo / observado | Evidencia verificada | Acción o estado final |
|---|---|---|---|
| Código fuente Laravel (`app/`, `bootstrap/`, `config/`, `routes/`, `resources/views/`) | **0** referencias activas | Búsqueda en archivos `.php`, `.blade.php`, `.js`, `.css`: **0** coincidencias de `Quarkus\|quarkus\|8080\|Bearer`; `Http::fake()` con `assertNothingSent()` en todas las suites | **Independiente** (100 % PHP/Laravel → PostgreSQL) |
| Red y puertos locales en ejecución | Puerto `8080` cerrado | `Get-NetTCPConnection -State Listen`: solo escuchan `35443` y `5432` (PostgreSQL); sonda HTTP Laravel (`/`, `/login`, `/registro`, paneles protegidos) responde `200`/`302` con Quarkus apagado | **Independiente** |
| Contenedor clínico (`compose.clinical.yml`) | Solo `clinica-laravel-fase1-pg16` (`postgres:16`) | Sin servicio Quarkus en `compose.clinical.yml`; `Initialize-Environment.ps1` e `Invoke-Laravel.ps1` ejecutan PHP + PostgreSQL | **Independiente** |
| Instalador del esquema clínico (`scripts/clinical/Install-Schema.ps1`) | **Dependía** de `java` y `backend_clinica/target/quarkus-app/lib/main/*.jar` (`ClinicalSchema.java` + Flyway 12) | Sustituido por `scripts/clinical/ClinicalSchemaPure.php` (PHP PDO + `psql` en contenedor + `SET ROLE clinica_owner`, hashes CRC32 compatibles con Flyway 12 y conservación de `B9 -> V10 -> R`). Validado con `Install-Schema.ps1 -ValidateOnly` y probado de punta a punta en base temporal descartable `clinica_laravel_install_verify` (`21` tablas, `2` vistas, `32` triggers, `3` filas `flyway_schema_history`: `772538900`, `212713187`, `1209888159`), luego eliminada | **Independiente** *(se conserva `ClinicalSchema.java` solo bajo flag `-UseLegacyJavaFlyway` hasta su retiro aprobado)* |
| Archivos residuales no participantes en ejecución | Presentes fuera del runtime activo | - `frontend_clinica/.env`: conserva claves legadas sin efecto en config (`QUARKUS_API_URL`, `QUARKUS_*`, `CLINIC_DEMO_ENABLED`) y flags locales de desarrollo (`APP_DEBUG`, `SESSION_SECURE_COOKIE`)<br>- `docker-compose.yml` raíz, `scripts/integration/*`, `README.md`, `frontend_clinica/docs/fase10-12*.md` y carpeta vacía `frontend_clinica/target/`<br>- `backend_clinica/`: carpeta Java histórica + fuente oficial de `B9`, `V10`, `R` y documentación clínica | **No participan en runtime**; inventariados para archivo/limpieza posterior |

---

## C. Resultados de la auditoría de seguridad y correcciones aplicadas

### 1. Hallazgos confirmados y corregidos en Fase 6

| Severidad | Hallazgo y evidencia | Corrección implementada | Prueba de regresión |
|---|---|---|---|
| **Media** | **Ventana de cancelación/reprogramación tras fin de turno antes del cron de 5 min (`citas` `Confirmada`)**: `B9:cancelar_cita` y `V10:reprogramar_cita` no bloquean `(fecha + hora_fin) <= ahora_clinica()` (comportamiento conservado intencionalmente para Secretaría según `backend_clinica/docs/cancelaciones-inasistencias-historial.md:139`). Un paciente con turno vencido antes de correr `marcar_inasistencias_vencidas()` podía cancelar/reprogramar | En `PatientAppointmentService::assertMutableConfirmed()` se bloquea cualquier intento de `ROL001` sobre una cita `Confirmada` cuyo `(fecha + hora_fin) <= ahora_clinica()`, devolviendo **`409 CITA_VENCIDA`** sin afectar el flujo de Secretaría | `PatientBackendMigrationTest::patient_can_reschedule_and_cancel_future_appointment_and_cannot_mutate_expired_or_non_confirmed` |
| **Baja** | **Error HTTP 500 por arreglo en `?q[]=abc`**: `Administracion/PacienteController.php:12` evaluaba un closure `preg_match_all()` sobre `$value` sin regla `'bail'`, generando `TypeError` (`500`) cuando `q` llega como array | Se antepuso **`'bail'`** a la regla `'q'` para detener validación tras `'string'` y responder `422`/redirect controlado | `SecurityAuditRegressionTest::admin_patient_search_rejects_array_query_parameter_with_validation_error_instead_of_500` |
| **Baja** | **Error HTTP 500 por arreglo anidado en `code_digits` de OTP**: `AuthController.php:134-136` aplicaba `implode('', ...)` directamente sobre `code_digits`, causando `Array to string conversion` ante `code_digits[0][]=1` | Validación previa verificando que todos los elementos de `code_digits` sean escalares antes de concatenar, rechazando entradas anidadas con validación `422`/redirect | `SecurityAuditRegressionTest::otp_verification_rejects_nested_array_code_digits_with_validation_error_instead_of_500` |
| **Baja** | **Límite de intentos de login únicamente por par `email\|ip`**: `LoginAttemptService.php:16-39` permitía eludir el bloqueo de `5` intentos rotando direcciones IP contra un mismo correo | Añadido acumulador secundario por correo (`MAX_EMAIL_ATTEMPTS = 15` en `900 s`), limpiado junto al par `email\|ip` en `success()` | `SecurityAuditRegressionTest::login_attempt_service_limits_distributed_ip_attacks_against_the_same_email` |

### 2. Verificaciones de seguridad que pasaron sin vulnerabilidades activas

- **Autenticación y sesiones:** hash `bcrypt`, regeneración de sesión tras login, invalidación completa en logout, rechazo de tokens legados sin guardia Laravel (`auth_token`), expiración `auth_expires_at`, cookies `http_only` + `same_site: lax`, `SESSION_ENCRYPT=true`, fail-closed de Google OAuth mediante `UnavailableGoogleIdentityProvider` y cabeceras `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy` en paneles administrativos, Secretaría, Médico y ahora Paciente (`PatientResponses`).
- **Autorización multirol e IDOR:** `CheckRolSession` (fail-closed `503` ante error PostgreSQL) + `ClinicalRoleGuard` dentro de los servicios (Admin `ROL004`, Secretaría `ROL002`, Médico `ROL003`, Paciente `ROL001`); aislamiento estricto de citas, análisis y derivaciones (`404` cuando pertenecen a otro médico o paciente).
- **SQL y privilegios PostgreSQL (`clinica_app` sobre `clinica_laravel`):**
  - Consultas 100 % parametrizadas (bindings PDO `?` / `query()`).
  - Todas las **31** rutinas `SECURITY DEFINER` fijan `search_path=public, pg_temp` y no otorgan ejecución a `PUBLIC`.
  - `clinica_app` carece de acceso a tablas sensibles (`verificaciones_otp`, `tokens_recuperacion`, `configuracion_seguridad`, `notificaciones`), solo actualiza columnas seguras de `usuarios` (`nombre_completo`, `email`, `password_hash`, `estado`, `actualizado_en` — nunca `cod_rol`) y no posee `INSERT`/`UPDATE`/`DELETE` directo sobre `citas`.
  - Tras cada transacción (`ClinicalTransaction::run`), `app.cod_usu` (`is_local = true`) queda limpio (`actor_actual() IS NULL`).

### 3. Observaciones informativas / pendientes para producción

1. **Variables locales en `frontend_clinica/.env`:** antes de despliegue productivo deberán establecerse `APP_DEBUG=false`, `APP_ENV=production`, `APP_LOCALE=es`, `SESSION_SECURE_COOKIE=true` bajo HTTPS, y depurarse las claves heredadas `QUARKUS_*` / `CLINIC_DEMO_ENABLED` que hoy no tienen lectores en `config/`.
2. **Enumeración y temporización en flujos públicos (`B.1`):** el registro por correo/CI informa duplicidad (`409` en BD/validación) y la recuperación de contraseña envía SMTP síncrono si se habilita correo real; aceptable en el alcance actual, recomendable encolar correos (`QUEUE_CONNECTION` asíncrono) en despliegue futuro.
3. **Política de longitud de contraseña para médicos (`PersonalRequest.php:27`):** exige mínimo `8` caracteres al crear médicos frente a `10` caracteres con complejidad en secretarías (`SecretariaPasswordRequest`); conviene alinearlas en una iteración administrativa futura.
4. **Inmutabilidad de análisis `Completado` en BD:** actualmente está garantizada en `MedicoAnalisisService`; a futuro puede reforzarse adicionalmente con trigger PostgreSQL si se habilitan escrituras por otras vías.
5. **Notificaciones al paciente (`RF-26`):** diferidas en espera de permisos selectivos sobre `notificaciones` y worker de envío (`tomar_notificaciones` / `finalizar_notificacion`).

---

## D. Verificación técnica final ejecutada tras los cambios de Fase 6

Todas las comprobaciones se ejecutaron **después del último cambio de código** con Quarkus detenido (`8080` cerrado):

| Verificación | Comando ejecutado | Resultado exacto observado |
|---|---|---|
| Base clínica aislada principal | `scripts/clinical/Verify-Database.ps1` | **OK**: `clinica_laravel`, PostgreSQL `16.15`, `America/La_Paz`, `21` tablas, `2` vistas, `42` funciones clínicas, `32` triggers, `40` FKs, `80` índices, `2` exclusiones, `0` restricciones inválidas, `0` triggers deshabilitados, **`0` usuarios**, `qrInitialized: true`, `B9 -> V10 -> R` (`success: true`) |
| Instalador puro sin Java/Quarkus | `scripts/clinical/Install-Schema.ps1 -ValidateOnly` | **OK**: `clinica_laravel` y `clinica_laravel_phase1_tests` validados (`B9 + V10 + R`, hashes `772538900`, `212713187`, `1209888159`) + instalación limpia probada en `clinica_laravel_install_verify` descartable |
| Suite Feature completa (`tests/Feature`) | `php frontend_clinica/vendor/bin/phpunit -c frontend_clinica/phpunit.xml` | **PASSED**: **`238` tests, `238` passed, `0` failed, `0` skipped, `1,521` assertions** (`48,884 ms`) *(incluye `SecurityAuditRegressionTest` `4/4`)* |
| Suite PostgreSQL completa (`tests/Postgres`) | `scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres` | **PASSED**: **`45` tests, `45` passed, `0` failed, `0` skipped, `708` assertions** (`752,363 ms`) *(incluye `PatientBackendMigrationTest` `6/6`)* |
| Estilo de código (`Pint`) | `php vendor/bin/pint --test` | **PASSED** (`{"tool":"pint","result":"passed"}`) |
| Sintaxis PHP | `php -l` en `app/`, `routes/`, `scripts/clinical/` | **`110` archivos verificados, `0` errores** |
| Composer, rutas, Blade y Vite | `composer validate --strict`, `artisan route:list`, `view:clear` + `view:cache`, `npm.cmd run build` | `./composer.json is valid`, **`Showing [97] routes`**, `Blade templates cached successfully.`, `vite v8.3.5` compilado en `3.60 s` |
| Sonda HTTP real sin Quarkus | Servidor PHP embebido contra `frontend_clinica/public` + `curl.exe` | `/ => 200`, `/login => 200`, `/registro => 200`, `/administracion/inicio => 302`, `/secretaria/inicio => 302`, `/medico/inicio => 302`, `/paciente/inicio => 302` |

**Total de pruebas automatizadas vigentes:** **`283` pruebas (`238` Feature + `45` PostgreSQL), `283` en verde, `0` fallos, `0` omitidas, `2,229` aserciones**.

---

## E. Propuesta ordenada para archivar o retirar `backend_clinica/` (sin ejecutar aún)

En cumplimiento estricto de las instrucciones, **`backend_clinica/` no se ha eliminado ni modificado**. Cuando el equipo decida retirar el código Quarkus en una fase dedicada posterior, se recomienda el siguiente plan seguro:

1. **Respaldar los activos aún vigentes antes de mover o borrar `backend_clinica/`:**
   - Copiar los scripts SQL oficiales (`backend_clinica/src/main/resources/db/migration/B9*.sql`, `V10*.sql`, `R*.sql`) a una carpeta propia de base de datos en el proyecto (por ejemplo `database/clinical/migrations/` o `scripts/clinical/migrations/`), actualizando la ruta `$migrationPath` en `scripts/clinical/Install-Schema.ps1`.
   - Conservar o trasladar la documentación funcional de referencia (`backend_clinica/docs/*.md`) bajo `docs/referencia-clinica/`.
2. **Limpiar referencias históricas residuales:**
   - Eliminar el fallback `-UseLegacyJavaFlyway` y `scripts/clinical/ClinicalSchema.java`.
   - Retirar el servicio `backend_quarkus` del `docker-compose.yml` raíz (o archivarlo junto con `scripts/integration/`), limpiar las claves `QUARKUS_*` de `.env` local y remover la carpeta vacía `frontend_clinica/target/`.
3. **Validar tras el archivo:**
   - Ejecutar `scripts/clinical/Install-Schema.ps1 -ValidateOnly`, prueba de instalación pura sobre base descartable y las suites `tests/Feature` + `tests/Postgres` confirmando **0** dependencias rotas.
