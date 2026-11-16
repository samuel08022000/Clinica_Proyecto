# Migración a Laravel — Fase 4 de 5: Módulo Médico completo, integración con Secretaría y Portal público

Fecha de verificación: 2026-11-16. Zona clínica: `America/La_Paz`.

## A. Estado general

**Estado de la Fase 4: COMPLETADA y verificada** dentro del entorno PostgreSQL 16 aislado y Laravel 12 local, sin participación de Quarkus en el runtime de los flujos migrados.

- Backend Médico implementado íntegramente en Laravel/PHP sobre PostgreSQL (`clinical`).
- Panel visual Blade del rol Médico (`ROL003`) operativo y coherente con los paneles de Administración y Secretaría.
- Integración completa de derivaciones entre Médico (`ROL003`) y Secretaría (`ROL002`), incluyendo citas hijas y reprogramación enlazada.
- Portal público (`/`) desconectado de `QuarkusApiClient` y leyendo especialidades activas directamente desde PostgreSQL.
- No se inició la Fase 5, no se eliminó `backend_clinica` (Quarkus permanece como referencia histórica) y no se ejecutaron `git add`, `git commit`, `git push` ni creación de ramas.

---

## B. Funcionalidades implementadas

### 1. Agenda médica (`MedicoAtencionService`, `AgendaController`)

- Muestra exclusivamente las citas asignadas al médico autenticado (`medicos.cod_usu = Auth::user()->cod_usu` con usuario, rol `ROL003` y perfil `medicos.estado = 'Activo'`).
- Fecha clínica predeterminada mediante `SELECT fecha_clinica()` (`America/La_Paz`) cuando no se envía `fecha`.
- Rechaza fechas imposibles (`2026-02-30`), año cero (`0000-01-01`), formato inválido, `fecha=` explícitamente vacío o filtros no escalares con HTTP 400 (`VALIDACION`).
- Orden cronológico ascendente (`ORDER BY c.hora_inicio, c.cod_cit`).

### 2. Atención y detalle de cita (`MedicoAtencionService`, `CitaController`)

- Proyección administrativa segura del detalle de cita propia (`codCit`, `codPac`, `nombrePaciente`, `codEsp`, `nombreEspecialidad`, `fecha`, `horaInicio`, `horaFin`, `estado`, `canalReserva`, `horaLlegadaCheckin`, `checkinPorUsu`, `fechaAtencion`, `atendidaPorUsu`, `checkinRegistrado`), sin exponer tokens QR, contraseñas ni datos de otros médicos.
- Cierre transaccional exclusivo `En Espera -> Atendida`, exigiendo check-in completo (`hora_llegada_checkin` y `checkin_por_usu` no nulos) y ausencia de atención previa.
- Delega la transición final a la función PostgreSQL `marcar_cita_atendida(cod_cit, cod_usu)` bajo `ClinicalTransaction` y `bloquear_agenda_medico(cod_med)`, sellando `fecha_atencion`, `atendida_por_usu` y auditoría en `bitacora`.
- Citas ajenas o inexistentes devuelven HTTP 404 (`NO_ENCONTRADO`); estados inválidos o dobles cierres devuelven HTTP 409 (`ATENCION_NO_PERMITIDA`).

### 3. Historial de atenciones (`MedicoAtencionService`, `AgendaController`)

- Lista exclusivamente citas propias en estado `Atendida`, ordenadas de forma descendente (`c.fecha DESC, c.hora_inicio DESC, c.cod_cit DESC`).
- Filtros opcionales `desde` y `hasta` en formato `YYYY-MM-DD`, validando que `desde <= hasta` y permitiendo enviar solo uno de los dos límites desde el formulario HTML.
- Paginación estable (`page=0..10000`, `size=1..50`, por defecto `0` y `20`) con indicador `hayMas`.

### 4. Derivaciones e interconsultas (`MedicoDerivacionService`, `DerivacionController`)

- Emisión autorizada únicamente desde una cita propia en estado `Atendida`, hacia una especialidad activa y un médico destinatario opcional activo con vínculo activo en `medico_especialidades`.
- Ejecuta `crear_derivacion(cod_cit_origen, cod_usu, cod_esp_destino, motivo, cod_med_destino)` dentro de `ClinicalTransaction` con bloqueo asesor `bloquear_agenda_medico(cod_med)`.
- Deduplicación idempotente por `(cod_cit_origen, cod_esp_destino, motivo_normalizado, cod_med_destino)`: reintentos idénticos devuelven el `codDer` existente sin duplicar filas ni registros de bitácora; motivos o destinos distintos sobre la misma cita sí crean nuevas interconsultas.
- Listado paginado (`Pendiente`, `Agendada`, `Completada`, `Cancelada`) y detalle exclusivamente de derivaciones emitidas por el médico autenticado.

### 5. Análisis clínicos (`MedicoAnalisisService`, `AnalisisController`)

- Solicitud de análisis (`tipoAnalisis`, 1–150 caracteres) habilitada únicamente cuando la cita propia está en estado `En Espera` o `Atendida` (en coherencia con `trg_validar_analisis`).
- El usuario solicitante (`solicitado_por_usu`) proviene siempre de la sesión autenticada; el estado inicial en SQL es `Pendiente`.
- Deduplicación case-insensitive por cita (`lower(trim(tipo_analisis)) = lower(?)`).
- Transiciones permitidas por el médico solicitante: `Pendiente -> En Proceso` y `Pendiente|En Proceso -> Completado` (con `resultado` obligatorio de 1 a 20 000 caracteres).
- Protección de resultados finales: estados `Completado`, `Entregado` y `Cancelado`, o análisis con `resultado`, `archivo_pdf_url` o `fecha_entrega_real` ya poblados devuelven HTTP 409 (`ANALISIS_NO_MODIFICABLE`). No se aceptan ni exponen rutas ficticias de PDF.

### 6. Panel visual Blade Médico

- Layout `layouts/medico.blade.php` reutilizando `x-workspace` y los componentes `x-admin.*` y `x-appointment-status`.
- Vistas implementadas: `dashboard.blade.php`, `agenda/index.blade.php`, `citas/show.blade.php`, `historial/index.blade.php`, `derivaciones/index.blade.php`, `derivaciones/show.blade.php`, `derivaciones/create.blade.php`, `analisis/index.blade.php` y `error.blade.php`.
- Todos los formularios de escritura incluyen `@csrf`, confirmación explícita (`x-admin.confirmation`), método HTTP apropiado (`POST` / `PATCH`), botones deshabilitados o formularios ocultos cuando el estado no permite la acción, y cabeceras `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer` en `MedicoResponses`.
- `resources/js/medico.js` filtra dinámicamente los médicos destinatarios según la especialidad seleccionada, conservando validación estricta en servidor.

### 7. Integración con Secretaría (`ReceptionService`, `Secretaria\DerivacionController`, `ReservaController`)

- Secretaría (`ROL002`) cuenta con bandeja `GET /secretaria/derivaciones` y detalle `GET /secretaria/derivaciones/{derivacion}` sin exponer el motivo clínico de la derivación ni resultados de análisis.
- Desde una derivación `Pendiente` sin `codCitHija`, Secretaría abre `GET /secretaria/reservas/nueva?codDer=...`: el servidor reconstruye `codPac`, `codEspDestino` y `codMedDestino` (si aplica) desde PostgreSQL, ignorando intentos de manipular `codPac` o `codEsp` en la URL.
- `ReceptionService::reserve` resuelve el paciente desde la derivación cuando `codPac` no viene en el payload, rechaza discrepancias entre `codPac` y la derivación (409 `DESTINO_INCOMPATIBLE` / `P4094`), rechaza tickets inexistentes (404) o ya consumidos (409) y ejecuta `reservar_cita` conservando `canal_reserva = 'Derivacion'`, `cod_cit_padre` y la actualización `derivaciones.cod_cit_hija` / `estado = 'Agendada'`.
- Si ocurre un conflicto de slot al confirmar (`POST /secretaria/reservas`), la redirección de retorno conserva `codDer` para no degradar el flujo a una reserva tradicional.
- La reprogramación (`POST /secretaria/citas/{cita}/reprogramar`) conserva el vínculo con `derivaciones.cod_cit_hija` y la cadena `cod_cit_padre`.

### 8. Portal público (`PortalController`)

- `GET /` consulta directamente `especialidades` (`WHERE estado = 'Activo'`) sobre la conexión `clinical` sin invocar `QuarkusApiClient`, proyectando `code`, `name`, `description` y `duration` esperados por `welcome.blade.php` y mostrando estados vacíos o de indisponibilidad sin fabricar datos demo.

---

## C. Archivos creados y modificados en la Fase 4

*(Se excluyen los archivos introducidos previamente en las Fases 1–3 y los cambios preexistentes en `backend_clinica/` o raíz).*

### Creados

- **Servicios y middleware Médico:**
  - `frontend_clinica/app/Services/Medico/MedicoAtencionService.php`
  - `frontend_clinica/app/Services/Medico/MedicoDerivacionService.php`
  - `frontend_clinica/app/Services/Medico/MedicoAnalisisService.php`
  - `frontend_clinica/app/Http/Middleware/MedicoResponses.php`
- **Controladores Médico:**
  - `frontend_clinica/app/Http/Controllers/Medico/DashboardController.php`
  - `frontend_clinica/app/Http/Controllers/Medico/AgendaController.php`
  - `frontend_clinica/app/Http/Controllers/Medico/CitaController.php`
  - `frontend_clinica/app/Http/Controllers/Medico/DerivacionController.php`
  - `frontend_clinica/app/Http/Controllers/Medico/AnalisisController.php`
- **Form Requests Médico:**
  - `frontend_clinica/app/Http/Requests/Medico/MedicoRequest.php`
  - `frontend_clinica/app/Http/Requests/Medico/FinishAppointmentRequest.php`
  - `frontend_clinica/app/Http/Requests/Medico/ReferralRequest.php`
  - `frontend_clinica/app/Http/Requests/Medico/AnalysisRequest.php`
  - `frontend_clinica/app/Http/Requests/Medico/AnalysisResultRequest.php`
- **Integración Secretaría:**
  - `frontend_clinica/app/Http/Controllers/Secretaria/DerivacionController.php`
  - `frontend_clinica/app/Http/Requests/Secretaria/ReferralBrowseRequest.php`
  - `frontend_clinica/resources/views/secretaria/derivaciones/index.blade.php`
  - `frontend_clinica/resources/views/secretaria/derivaciones/show.blade.php`
- **Vistas y assets Médico:**
  - `frontend_clinica/resources/views/layouts/medico.blade.php`
  - `frontend_clinica/resources/views/medico/dashboard.blade.php`
  - `frontend_clinica/resources/views/medico/error.blade.php`
  - `frontend_clinica/resources/views/medico/agenda/index.blade.php`
  - `frontend_clinica/resources/views/medico/citas/show.blade.php`
  - `frontend_clinica/resources/views/medico/historial/index.blade.php`
  - `frontend_clinica/resources/views/medico/derivaciones/index.blade.php`
  - `frontend_clinica/resources/views/medico/derivaciones/show.blade.php`
  - `frontend_clinica/resources/views/medico/derivaciones/create.blade.php`
  - `frontend_clinica/resources/views/medico/analisis/index.blade.php`
  - `frontend_clinica/resources/views/medico/partials/appointments-table.blade.php`
  - `frontend_clinica/resources/views/medico/partials/navigation.blade.php`
  - `frontend_clinica/resources/css/medico.css`
  - `frontend_clinica/resources/js/medico.js`
- **Permisos SQL y pruebas de Fase 4:**
  - `scripts/clinical/phase4-permissions.sql`
  - `scripts/clinical/Apply-Phase4-Permissions.ps1`
  - `frontend_clinica/tests/Postgres/MedicoMigrationTest.php`
  - `frontend_clinica/tests/Support/ConcurrentMedicalWorker.php`
- **Documentación:**
  - `docs/migracion-laravel-fase4.md` (este informe)

### Modificados en Fase 4

- `frontend_clinica/app/Exceptions/ClinicalOperationException.php`: enrutamiento de renderizado hacia `MedicoResponses` para `/medico/*`.
- `frontend_clinica/app/Http/Controllers/PortalController.php`: reemplazo de `QuarkusApiClient` por consulta local a `especialidades` en `clinical` y alias `descripcion AS description`.
- `frontend_clinica/app/Http/Controllers/Secretaria/ReservaController.php`: reconstrucción autoritativa desde `codDer` en `create()`, preservación de `codDer` ante conflicto de reserva en `store()`.
- `frontend_clinica/app/Services/Reception/ReceptionService.php`: rutas internas `derivaciones` / `derivaciones/{codDer}` y resolución/validación estricta de `codPac` desde `codDer` en `reserve()`.
- `frontend_clinica/app/Http/Requests/Secretaria/ReceptionRequest.php`, `ReservationBrowseRequest.php`, `ReservationRequest.php`: soporte y validación de `codDer` opcional y `required_without:codDer` en `codPac`.
- `frontend_clinica/resources/views/secretaria/partials/navigation.blade.php`: enlace a Derivaciones en navegación de Secretaría.
- `frontend_clinica/resources/views/secretaria/reservas/create.blade.php` y `confirm.blade.php`: transporte seguro del parámetro `codDer` y aviso de reserva derivada.
- `frontend_clinica/resources/views/components/admin/badge.blade.php`: estilos semánticos para estados `Agendada`, `Completada`, `Completado` y `En Proceso`.
- `frontend_clinica/resources/css/app.css` y `frontend_clinica/resources/js/app.js`: importación de `medico.css` y `medico.js`.
- `frontend_clinica/routes/web.php`: grupo `medico.*` con middleware `[MedicoResponses::class, 'rol:ROL003']` y rutas `secretaria.derivaciones.*`.
- `frontend_clinica/tests/Feature/PortalFrontendTest.php`: verificación del portal sin `QuarkusApiClient` tanto con catálogo PostgreSQL activo (`data-specialties-state="ready"`) como en estado vacío/error.

---

## D. Base de datos, triggers y permisos

- **Tablas clínicas utilizadas:** `usuarios`, `roles`, `medicos`, `secretarias`, `pacientes`, `especialidades`, `medico_especialidades`, `slots_agenda`, `citas`, `derivaciones`, `analisis`, `bitacora`.
- **Vistas utilizadas:** `v_agenda_disponibilidad`.
- **Funciones reutilizadas:** `fecha_clinica()`, `bloquear_agenda_medico(text)`, `marcar_cita_atendida(text,text)`, `crear_derivacion(text,text,text,text,text)`, `reservar_cita(text,text,text,text,text)`, `reprogramar_cita(text,text,text,text)`, `actor_actual()`.
- **Triggers conservados sin alteración:** `trg_validar_cita`, `trg_procesar_evento_cita`, `trg_auditar_citas`, `trg_auditar_derivaciones`, `trg_validar_analisis`, `trg_auditar_analisis`, `trg_bitacora_inmutable`, además del resto de los 32 triggers del esquema.
- **Permisos incrementales de Fase 4 (`phase4-permissions.sql`):**
  - `EXECUTE` sobre `marcar_cita_atendida(text,text)` y `crear_derivacion(text,text,text,text,text)` para `clinica_app`.
  - `INSERT (cod_cit, solicitado_por_usu, tipo_analisis)` sobre `public.analisis` para `clinica_app`.
  - `UPDATE (estado, resultado)` sobre `public.analisis` para `clinica_app`.
  - `USAGE, SELECT` sobre `public.analisis_secuencial_seq` para `clinica_app`.
  - No se concedió `DELETE` sobre `analisis`, `UPDATE` global sobre `citas`, acceso a `configuracion_seguridad` ni rol de superusuario.
- **Integridad de migraciones y esquema (`Verify-Database.ps1` en `clinica_laravel`):**
  - PostgreSQL **16.15**, zona `America/La_Paz`.
  - **21** tablas de dominio/soporte (+ `flyway_schema_history`), **2** vistas, **42** funciones clínicas (excluyendo extensiones), **32** triggers, **40** claves foráneas, **80** índices, **2** restricciones de exclusión, **0** restricciones inválidas, **0** triggers deshabilitados, **0** usuarios en la base principal, `qrInitialized = true`.
  - Historial Flyway intacto: `B9__instalacion_sin_datos_demo.sql` → `V10__derivaciones_recepcion_enlazadas.sql` → `R__verificacion_email_otp.sql`. No se modificaron B9, V10 ni R.
- **Seguridad de auditoría:** todas las escrituras se ejecutan mediante `ClinicalTransaction`, que fija `set_config('app.cod_usu', ?, true)` localmente en la transacción de la conexión `clinical` a partir del usuario autenticado (`Auth::guard('web')`). Al finalizar cada operación, `actor_actual()` vuelve a `NULL`.

---

## E. Rutas implementadas en Fase 4

### Módulo Médico (`prefix: /medico`, `name: medico.*`, middleware: `MedicoResponses`, `rol:ROL003`)

| Método HTTP | URI | Nombre de ruta | Parámetros | Acción / Controlador |
|---|---|---|---|---|
| `GET\|HEAD` | `/medico/inicio` | `medico.home` | — | `Medico\DashboardController` |
| `GET\|HEAD` | `/medico/agenda` | `medico.agenda.index` | `?fecha=YYYY-MM-DD` (opcional) | `Medico\AgendaController@index` |
| `GET\|HEAD` | `/medico/historial` | `medico.historial.index` | `?desde&hasta&page&size` | `Medico\AgendaController@history` |
| `GET\|HEAD` | `/medico/citas/{cita}` | `medico.citas.show` | `cita` alfanumérico | `Medico\CitaController@show` |
| `POST` | `/medico/citas/{cita}/atender` | `medico.citas.atender` | `cita`, `confirmacion` | `Medico\CitaController@finish` |
| `GET\|HEAD` | `/medico/derivaciones` | `medico.derivaciones.index` | `?estado&page&size` | `Medico\DerivacionController@index` |
| `GET\|HEAD` | `/medico/derivaciones/{derivacion}` | `medico.derivaciones.show` | `derivacion` alfanumérico | `Medico\DerivacionController@show` |
| `GET\|HEAD` | `/medico/citas/{cita}/derivaciones/crear` | `medico.derivaciones.create` | `cita` alfanumérico | `Medico\DerivacionController@create` |
| `POST` | `/medico/citas/{cita}/derivaciones` | `medico.derivaciones.store` | `cita`, `codEspDestino`, `codMedDestino?`, `motivo`, `confirmacion` | `Medico\DerivacionController@store` |
| `GET\|HEAD` | `/medico/citas/{cita}/analisis` | `medico.analisis.index` | `cita` alfanumérico | `Medico\AnalisisController@index` |
| `POST` | `/medico/citas/{cita}/analisis` | `medico.analisis.store` | `cita`, `tipoAnalisis`, `confirmacion` | `Medico\AnalisisController@store` |
| `PATCH` | `/medico/analisis/{analisis}` | `medico.analisis.update` | `analisis`, `estado`, `resultado?`, `confirmacion` | `Medico\AnalisisController@update` |

### Integración en Secretaría (`prefix: /secretaria`, `name: secretaria.*`, middleware: `ReceptionResponses`, `rol:ROL002`)

| Método HTTP | URI | Nombre de ruta | Parámetros | Acción / Controlador |
|---|---|---|---|---|
| `GET\|HEAD` | `/secretaria/derivaciones` | `secretaria.derivaciones.index` | `?estado&page&size` | `Secretaria\DerivacionController@index` |
| `GET\|HEAD` | `/secretaria/derivaciones/{derivacion}` | `secretaria.derivaciones.show` | `derivacion` alfanumérico | `Secretaria\DerivacionController@show` |
| `GET\|HEAD` | `/secretaria/reservas/nueva` | `secretaria.reservas.create` | `?codDer&codPac&codEsp&codMed&fecha&codSlot` | `Secretaria\ReservaController@create` |
| `POST` | `/secretaria/reservas` | `secretaria.reservas.store` | `codSlot`, `codDer?`, `codPac?`, `confirmacion` | `Secretaria\ReservaController@store` |

---

## F. Pruebas reales ejecutadas

Todas las ejecuciones siguientes se realizaron en la verificación actual contra PostgreSQL 16.15 aislado (`clinica_laravel_phase1_tests`), con contenedor Quarkus detenido y sin procesos escuchando en el puerto `8080`.

| Verificación / Comando | Ejecutadas | Aprobadas | Fallidas | Errores | Omitidas | Aserciones | Resultado |
|---|---:|---:|---:|---:|---:|---:|---|
| `Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres/MedicoMigrationTest.php --testdox` | 11 | 11 | 0 | 0 | 0 | 192 | Aprobado (04:20) |
| `Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres --testdox` (regresión Fases 1–4) | 38 | 38 | 0 | 0 | 0 | 526 | Aprobado (05:58) |
| `Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Feature --testdox` | 246 | 28 | 0 | 0 | 218 | 176 | Aprobado con omitidos heredados |
| `php vendor/bin/phpunit tests/Feature/PortalFrontendTest.php --testdox` (sin credenciales clínicas) | 6 | 6 | 0 | 0 | 0 | 49 | Aprobado (respaldo sin BD) |
| Concurrencia real (`ConcurrentMedicalWorker.php`, procesos independientes con barrera) | 5 escenarios en 2 tests | 5 | 0 | 0 | 0 | incluidas arriba | 1 ganador en cierre, 1 en actualización resultado, 1 en reserva derivada; deduplicación segura en derivación y análisis |
| `Verify-Database.ps1` (base principal `clinica_laravel`) | — | — | — | — | — | — | Aprobado (21 tablas, 2 vistas, 42 funciones, 32 triggers, 0 usuarios) |
| `npm.cmd run build` (Vite 8.3.5 + Tailwind CSS) | — | — | — | — | — | — | Compilado en 3.44 s (`app.css` 82.47 kB, `app.js` 9.24 kB; aviso opcional de `fontaine` no bloqueante) |
| `php vendor/bin/pint --test` (archivos Fase 4 y modificados) | — | — | — | — | — | — | Aprobado (`passed`) |
| `php -l` sobre `app/`, `routes/`, `tests/Postgres/`, `tests/Support/` | — | — | — | — | — | — | 0 errores de sintaxis |
| `node ..\.agents\skills\design-taste-frontend\scripts\lint.mjs resources/css/medico.css resources/views/medico resources/views/secretaria` | — | — | — | — | — | — | `0 FAIL, 0 WARN` |
| `php artisan route:list` y `composer validate --no-check-publish` | — | — | — | — | — | — | 97 rutas registradas; `composer.json` válido |
| `git diff --check` | — | — | — | — | — | — | Sin errores de whitespace |

**Nota sobre navegador:** no se ejecutó una inspección automatizada en navegador real (Playwright/DevTools); los renderizados Blade, formularios CSRF, filtros y flujos HTTP completos entre roles se verificaron mediante pruebas HTTP de Laravel contra PostgreSQL real y análisis estático con el linter visual.

---

## G. Problemas encontrados y soluciones verificadas

1. **`SELECT ... FOR UPDATE` sobre `citas` rechazado por privilegios mínimos de `clinica_app`:**
   - *Causa:* PostgreSQL requiere privilegio `UPDATE` en la tabla para ejecutar `SELECT ... FOR UPDATE`, el cual no debía concederse de forma global sobre `citas` a `clinica_app`.
   - *Solución:* los servicios médicos adquieren el bloqueo asesor transaccional mediante `SELECT bloquear_agenda_medico(?)` al inicio de la transacción y delegan las actualizaciones de citas/derivaciones a las funciones `SECURITY DEFINER` (`marcar_cita_atendida`, `crear_derivacion`), conservando privilegios mínimos.
   - *Verificación:* `MedicoMigrationTest` (pruebas de atención, derivación, concurrencia y `phase_four_permissions_schema_and_actor_context_remain_minimal_and_intact`).

2. **Reserva derivada sin `codPac` enviado y riesgo de manipulación de paciente o pérdida de `codDer`:**
   - *Causa:* el formulario de Secretaría puede enviar `codDer` sin `codPac` (o con `codPac` vacío), y además `ReservaController::store` no incluía `codDer` al redirigir de vuelta ante un error de reserva (409/422), lo que podía degradar el reintento a una reserva tradicional.
   - *Solución:* `ReceptionService::reserve` resuelve el paciente desde `derivaciones -> citas.cod_pac` cuando no hay `codPac`, rechaza con 404 si la derivación no existe, rechaza con 409 (`DESTINO_INCOMPATIBLE`) si el cliente envía un `codPac` distinto al de la cita origen, y delega en `reservar_cita` (`V10`). `ReservaController::store` conserva `codDer` en la redirección tras conflicto de horario.
   - *Verificación:* `end_to_end_http_journey_integrates_reception_referrals_analyses_and_guards_tampering` y `one_referral_cannot_create_two_children_under_real_concurrency` en `MedicoMigrationTest`.

3. **Filtros opcionales vacíos en Historial médico (`?desde=...&hasta=`) producían HTTP 400:**
   - *Causa:* al enviar el formulario HTML de `medico/historial` con solo uno de los campos de fecha, el navegador envía el otro vacío (`hasta=`), que el middleware `ConvertEmptyStringsToNull` transforma en `null`. `AgendaController::history` usaba `$request->has($key)` y rechazaba `null` como filtro no string con HTTP 400.
   - *Solución:* cambio a `$request->filled($key)` en `AgendaController::history`, manteniendo el rechazo 400 para valores no string (como arrays `?desde[]=...`) y manteniendo el rechazo 400 de `?fecha=` vacío en `AgendaController::index`.
   - *Verificación:* `end_to_end_http_journey_integrates_reception_referrals_analyses_and_guards_tampering`.

4. **Parámetro `estado` tipo array en listado de derivaciones médicas:**
   - *Causa:* `(string) $filters['estado']` en `MedicoDerivacionService::listing` podía lanzar `Array to string conversion` si se enviaba `?estado[]=...`.
   - *Solución:* validación explícita `is_string($rawState)` devolviendo `ClinicalOperationException(400, ..., 'VALIDACION')`.

5. **Fallo HTTP 500 en el Portal público (`/`) con especialidades activas en PostgreSQL:**
   - *Causa:* `PortalController::index` seleccionaba `descripcion` sin alias mientras `welcome.blade.php` leía `$specialty['description']`. En ejecuciones previas sin contraseña clínica en el entorno del test feature, la excepción SQL activaba el bloque `catch` y ocultaba el defecto; al ejecutar `tests/Feature` con `-TestDatabase` contra PostgreSQL poblado, `PortalFrontendTest` falló con `500 Undefined array key "description"`, y la aserción de `PortalFrontendTest` tampoco contemplaba `data-specialties-state="ready"`.
   - *Solución:* agregado alias `descripcion AS description` en `PortalController` y actualización de `PortalFrontendTest` para aceptar `data-specialties-state="ready"` cuando hay especialidades reales en PostgreSQL, manteniendo la prohibición de datos ficticios.
   - *Verificación:* `PortalFrontendTest` aprobado tanto con `-TestDatabase` (6/6, 49 aserciones en la corrida Feature) como sin credenciales clínicas (6/6, 49 aserciones).

6. **Aserción de inventario SQL y verificación de reprogramación derivada:**
   - *Causa:* en desarrollo previo de `MedicoMigrationTest`, el conteo simple de `pg_proc` incluía funciones de extensiones (`pgcrypto`, `btree_gist`), y algunas aserciones asumían campos de DTO no expuestos en lugar de comprobar la persistencia real de `cod_cit_padre`.
   - *Solución:* conservada la consulta exacta de Fase 1 filtrando dependencias de extensión (`NOT EXISTS ... pg_depend ... deptype='e'`, resultado exacto 42) y verificación directa en PostgreSQL de `cod_cit_padre` y `derivaciones.cod_cit_hija`.

7. **Concurrencia real y trazabilidad de auditoría:**
   - *Verificación:* con procesos PHP independientes sincronizados por barrera (`ConcurrentMedicalWorker.php`), se comprobó que dos cierres simultáneos producen un único éxito y una sola transición `Atendida` en `bitacora`; dos emisiones idénticas de derivación o solicitudes idénticas de análisis se deduplican a un solo registro; dos completados simultáneos de análisis producen una sola escritura válida; y dos reservas simultáneas sobre el mismo ticket `codDer` generan una sola cita hija.

---

## H. Guía de pruebas manuales (PowerShell y navegador)

> **Advertencia:** utilizar únicamente el entorno PostgreSQL aislado (`clinica-laravel-fase1-pg16`, puerto `35443`) y cuentas sintéticas de prueba. No ingresar contraseñas ni tokens en comandos en claro ni usar cuentas reales. No ejecutar contra la base habitual (`clinica_db`).

### 1. Iniciar PostgreSQL aislado y verificar permisos e invariantes

Desde la raíz del proyecto:

```powershell
# 1. Iniciar contenedor PostgreSQL 16 aislado (sin Quarkus)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Start-Database.ps1

# 2. Aplicar/verificar permisos mínimos Fase 4 (idempotente)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Apply-Phase4-Permissions.ps1

# 3. Verificar esquema e inventario (21 tablas, 2 vistas, 42 funciones, 32 triggers)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Verify-Database.ps1
```

### 2. Iniciar Laravel sin Quarkus

```powershell
# 4. Comprobar que no hay ningún servicio Quarkus escuchando en 8080
Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue

# 5. Iniciar Laravel contra la base aislada (mantener abierta esta consola)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 artisan serve --host=127.0.0.1 --port=8000
```

*(Si la base `clinica_laravel` aún tiene 0 usuarios y necesitas crear el primer Administrador de pruebas de forma interactiva y con contraseña oculta: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 artisan clinical:bootstrap-admin`.)*

### 3. Recorrido funcional en el navegador (`http://127.0.0.1:8000`)

1. **Portal público:** abre `http://127.0.0.1:8000/` y verifica que carga las especialidades activas reales (o el aviso de catálogo vacío si aún no se crearon) sin errores 500.
2. **Configuración como Administrador (`ROL004`):** inicia sesión en `/login` con la cuenta administrativa de pruebas; en **Administración → Especialidades** y **Médicos**, crea o verifica dos especialidades activas, Médico A, Médico B, una cuenta de Secretaría (`ROL002`) y horarios para hoy (`Agendas`). Asegúrate de contar con un paciente de prueba activo y con correo verificado.
3. **Reserva y check-in como Secretaría (`ROL002`):** inicia sesión como Secretaría, ve a **Nueva reserva**, selecciona al paciente de prueba, especialidad de Médico A y un turno de hoy. Confirma la reserva; anota el `codCit` resultante y realiza el **Check-in QR** por el flujo autorizado de acreditación de Secretaría hasta que la cita quede `En Espera`.
4. **Agenda y atención como Médico A (`ROL003`):** cierra sesión e inicia sesión como Médico A. Verifica `/medico/inicio` y `/medico/agenda`: la cita aparece `En Espera`. Entra a **Ver detalle**, marca la casilla de confirmación y pulsa **Finalizar consulta**. Verifica que pasa a `Atendida` con sello de fecha y responsable.
5. **Análisis clínicos:** en la misma cita (o desde **Gestionar análisis**), solicita un estudio (ej. `Hemograma`), vuelve a solicitar el mismo estudio con distinta capitalización para comprobar que se reutiliza el mismo `codAna` sin duplicarse, márcalo `En Proceso` y luego complétalo con un resultado textual autorizado. Verifica que después de `Completado` desaparecen los controles de edición.
6. **Emitir derivación:** desde la cita `Atendida`, abre **Emitir derivación**, selecciona la especialidad destino y opcionalmente Médico B, ingresa un motivo de prueba, confirma y envía. Verifica que queda `Pendiente` en **Derivaciones**.
7. **Programar cita hija como Secretaría:** cambia a la sesión de Secretaría, entra a **Derivaciones**, abre el detalle de la derivación y pulsa **Programar cita hija**. Comprueba que el paciente y la especialidad vienen preestablecidos por el servidor y que aparece el aviso de reserva derivada. Selecciona fecha y turno de Médico B y confirma.
8. **Agenda del médico destinatario (`ROL003`):** inicia sesión como Médico B, abre **Agenda** en la fecha programada y confirma que aparece la cita hija con canal `Derivacion`.
9. **Historial y persistencia:** vuelve a Médico A y abre **Historial** filtrando solo por `Desde` (dejando `Hasta` vacío) y luego con rango completo; verifica que aparece la cita atendida. Reinicia `artisan serve` o ejecuta `Test-Persistence.ps1` para comprobar persistencia real en PostgreSQL.
10. **Accesos no autorizados:** intentando abrir `/medico/inicio` sin sesión debe redirigir a `/login`; con cuenta de Secretaría, Paciente o Administrador debe devolver HTTP `403`; intentando abrir `/medico/citas/{codCit_de_Medico_B}` desde Médico A debe devolver HTTP `404`.

Para detener el servidor web usa `Ctrl+C`, y para detener PostgreSQL conservando los datos:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Stop-Database.ps1
```

---

## I. Preparación para Fase 5

- **Módulo Médico sin Quarkus:** **Sí.** Todo el flujo de agenda, detalle, atención, historial, derivaciones, análisis, integración con Secretaría y consulta pública del Portal opera directamente sobre Laravel y PostgreSQL sin llamadas HTTP a Quarkus.
- **Integración de los cuatro roles:**
  - `ROL004` (Administrador): autenticación y módulo completos en Laravel + PostgreSQL.
  - `ROL002` (Secretaría): autenticación y módulo completos en Laravel + PostgreSQL, incluyendo derivaciones y reprogramación enlazada.
  - `ROL003` (Médico): autenticación y módulo completos en Laravel + PostgreSQL.
  - `ROL001` (Paciente): autenticación, registro, OTP y recuperación migrados en Fase 2; el panel funcional propio de `/paciente/inicio` (reservas web/consulta de citas del paciente) sigue marcado en alcance futuro y debe revisarse/definirse en la verificación integral de Fase 5.
- **Pruebas y contratos pendientes para Fase 5:**
  - **218 casos omitidos** en `tests/Feature` (`AdministracionPanelTest`, `SecretariaPanelTest`, `PatientPanelTest`, `ApiResilienceTest`, `LiveBackendIntegrationTest`, `LiveHttpStackTest`) corresponden a contratos HTTP mockeados/live de Quarkus heredados; en Fase 5 deben auditarse para retirar los obsoletos y completar cualquier cobertura HTTP/navegador faltante sobre Laravel.
  - **Pruebas en navegador real (Playwright / DevTools):** recomendadas en Fase 5 para validar visualmente los cuatro paneles y recorridos E2E en navegador real.
- **Funciones externas bloqueadas:**
  - **Google OAuth/OIDC real:** continúa deshabilitado (`UnavailableGoogleIdentityProvider`) hasta incorporar una biblioteca criptográfica confiable (p. ej. `google/apiclient` o librería OIDC/JWK validada) mediante operación autorizada con acceso a paquetes.
  - **Correo SMTP real / Worker de notificaciones:** las pruebas usan `Mail::fake()`; la entrega efectiva de OTP/recuperación y el despacho de la tabla `notificaciones` dependen de credenciales SMTP privadas en producción.
- **Riesgos a atender en Fase 5:**
  - Verificar en navegador real la experiencia completa de los cuatro roles y la limpieza o archivo definitivo de `QuarkusApiClient` si ya ningún controlador activo lo requiere.
  - Confirmar ejecución única del scheduler (`php artisan schedule:work` / cron) para `clinical:maintenance slots` y `clinical:maintenance reception`.
  - Mantener intactas las 21 tablas, 2 vistas, 42 funciones, 32 triggers y la secuencia Flyway `B9 -> V10 -> R` al preparar la entrega final.
