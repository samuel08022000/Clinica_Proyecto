# FASE 6 — PREPARACIÓN DEL BACKEND DEL PANEL PACIENTE (`ROL001`)

## A. Resumen ejecutivo y alcance

Se diseñó, implementó y verificó contra **PostgreSQL `16.15`** la capa backend de servicios, guardias de autorización, contratos de validación (`FormRequest`), middleware de respuestas seguras y pruebas de integración/concurrencia para el futuro **Panel del Paciente (`ROL001`)**, sin modificar la apariencia actual del proyecto ni construir todavía las pantallas visuales definitivas (reservadas para la fase de diseño frontend con Codex).

- **Principio rector:** única identidad clínica (`usuarios` + `pacientes`), conexión PostgreSQL mínima (`clinica_app`), actor trazado por transacción mediante `set_config('app.cod_usu', ..., true)` y cero dependencia de Quarkus o Java.
- **Vistas actuales conservadas sin cambios visuales:** `frontend_clinica/resources/views/dashboard/home.blade.php` y la ruta `GET /paciente/inicio` (`AuthController::dashboard`) permanecen intactas como portada provisional del paciente autenticado.

---

## B. Matriz de requerimientos, SQL, servicios, contratos y pruebas

| Capacidad / Requisito del Paciente | Objeto PostgreSQL reutilizado | Servicio / Clase Laravel | Validación (`FormRequest`) | Prueba verificada (`tests/Postgres`) | Estado |
|---|---|---|---|---|---|
| Perfil del paciente, estado de cuenta y sanción vigente | `pacientes`, `usuarios`, `incidencias_sanciones`, `citas`, `derivaciones`, `estado_reserva_paciente(text)` | `PatientProfileService::profile()` + `ClinicalRoleGuard::require('ROL001')` | Sesión `ROL001` (`CheckRolSession` + `AccountStatusService`) | `PatientBackendMigrationTest` (casos 1, 2, 5) | **Listo y probado** |
| Historial de incidencias y sanciones propias | `incidencias_sanciones`, `citas`, `especialidades` | `PatientProfileService::incidences(array $filters)` | Parámetros `tipoIncidencia`, `desde`, `hasta`, `page`, `size` | `PatientBackendMigrationTest` (casos 3, 5) | **Listo y probado** |
| Consulta de derivaciones médicas emitidas al paciente (solo lectura) | `derivaciones`, `citas`, `medicos`, `especialidades` | `PatientProfileService::referrals(array $filters)` | Filtro `estado`, `page`, `size` | `PatientBackendMigrationTest` (casos 3, 5) | **Listo y probado** |
| Consulta de análisis clínicos de una cita propia (solo lectura) | `analisis`, `citas` | `PatientProfileService::analyses(string $appointmentCode)` | Identificador alfanumérico (`codCit`) + pertenencia `cod_pac` (`404` si ajena) | `PatientBackendMigrationTest` (casos 3, 5) | **Listo y probado** |
| Catálogo de especialidades y médicos activos | `especialidades`, `medico_especialidades`, `medicos`, `usuarios`, `roles` | `PatientAvailabilityService::specialties()`, `doctors(string $specialtyCode)` | Identificador alfanumérico `codEsp` | `PatientBackendMigrationTest` (caso 2) | **Listo y probado** |
| Consulta de disponibilidad `D..D+14` y exclusión de solapamientos propios | `v_agenda_disponibilidad`, `citas`, `fecha_clinica()`, `estado_reserva_paciente(text)` | `PatientAvailabilityService::slots(array $filters)` | `AvailabilityRequest` (`codEsp`, `fecha`, `codMed`) | `PatientBackendMigrationTest` (casos 2, 4) | **Listo y probado** |
| Reserva web de cita por el paciente autenticado | `reservar_cita(cod_pac, cod_slot, cod_actor, 'Web', NULL)` | `PatientAppointmentService::reserve(string $slotCode)` | `ReserveRequest` (`codSlot`) | `PatientBackendMigrationTest` (casos 2, 5, 6 + `ConcurrentPatientReserveWorker`) | **Listo y probado** |
| Listado y detalle de citas propias (`proximas`, `pasadas`, `todas`) | `v_historial_citas`, `citas`, `ahora_clinica()` | `PatientAppointmentService::list()`, `detail()` | `AppointmentFilterRequest` | `PatientBackendMigrationTest` (casos 2, 3) | **Listo y probado** |
| Pase QR seguro de una cita `Confirmada` vigente | `citas`, `cita_admite_checkin(text)` *(sin exponer `QR_HMAC` ni ejecutar `registrar_checkin`)* | `PatientAppointmentService::checkInPass(string $appointmentCode)` | Identificador alfanumérico + propiedad + estado `Confirmada` no vencida | `PatientBackendMigrationTest` (casos 2, 3, 4) | **Listo y probado** |
| Cancelación por paciente con motivo y bloqueo post-horario | `cancelar_cita(cod_cit, cod_actor, motivo)` + guardia `esVencida` | `PatientAppointmentService::cancel(string $appointmentCode, string $reason)` | `CancelAppointmentRequest` (`motivo` 1..2000, `confirmacion` accepted) | `PatientBackendMigrationTest` (casos 3, 4) | **Listo y probado** |
| Reprogramación por paciente conservando `cod_cit_padre` y bloqueo si sancionado o vencida | `reprogramar_cita(cod_cit, cod_slot_nuevo, cod_actor, motivo)` + `estado_reserva_paciente` | `PatientAppointmentService::reschedule(...)` | `RescheduleAppointmentRequest` (`codSlotNuevo`, `motivo`, `confirmacion`) | `PatientBackendMigrationTest` (casos 3, 4, 5) | **Listo y probado** |
| Bandeja de notificaciones clínicas (`RF-26`) | `public.notificaciones`, `tomar_notificaciones`, `finalizar_notificacion` | *(Sin servicio activo en esta fase)* | — | Verificado en PostgreSQL que `clinica_app` **no** tiene `SELECT`/`EXECUTE` en `runtime-permissions.sql` | **Diferido documentado** |

---

## C. Inventario y contratos técnicos para la futura capa visual (Codex)

### 1. Archivos creados o actualizados para el backend del paciente

- `frontend_clinica/app/Services/Clinical/ClinicalRoleGuard.php`: añade soporte explícito de `ROL001`, verificando usuario `Activo`, rol `ROL001` `Activo`, fila existente en `pacientes` con `estado_cuenta != 'Inactivo'` y `email_verificado = true`.
- `frontend_clinica/app/Exceptions/ClinicalOperationException.php`: enruta excepciones bajo `paciente/*` hacia `PatientResponses::renderFailure`.
- `frontend_clinica/app/Http/Middleware/PatientResponses.php`: cabeceras `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer`, manejo seguro de `503` (`Retry-After: 30`), redirección a `login` en `401`, respuestas JSON automáticas si `expectsJson()`, fallback flash sin exponer `motivo` o credenciales QR (`safeInput`) y soporte automático para la futura vista `paciente.error` cuando Codex la cree.
- `frontend_clinica/app/Services/Paciente/PatientSupport.php`: utilidades compartidas de lectura segura (`ClinicalSqlException::fromQuery`), resolución de perfil desde `cod_usu` autenticado y validación de parámetros (`identifier`, `date`, `page`, `text`).
- `frontend_clinica/app/Services/Paciente/PatientProfileService.php`: `profile()`, `incidences(array $filters = [])`, `referrals(array $filters = [])`, `analyses(string $appointmentCode)`.
- `frontend_clinica/app/Services/Paciente/PatientAvailabilityService.php`: `specialties()`, `doctors(string $specialtyCode)`, `slots(array $filters)`.
- `frontend_clinica/app/Services/Paciente/PatientAppointmentService.php`: `list(array $filters = [])`, `detail(string $appointmentCode)`, `checkInPass(string $appointmentCode)`, `reserve(string $slotCode)`, `cancel(string $appointmentCode, string $reason)`, `reschedule(string $appointmentCode, string $newSlotCode, string $reason)`.
- `frontend_clinica/app/Http/Requests/Paciente/`:
  - `PacienteRequest.php` (base abstracta que exige `session('cod_rol') === 'ROL001'`, lista blanca `safeInput`, soporte HTML y JSON `422`).
  - `AvailabilityRequest.php`: `codEsp` requerido alfanumérico `<= 64`, `fecha` requerida `Y-m-d`, `codMed` opcional alfanumérico `<= 64`.
  - `ReserveRequest.php`: `codSlot` requerido alfanumérico `<= 64`.
  - `AppointmentFilterRequest.php`: `alcance` (`proximas|pasadas|todas`), `estado` (`Confirmada|En Espera|Atendida|Cancelada|No Asistió|Afectada/Pendiente Reprogramación|Reprogramada`), `desde`, `hasta` (`>= desde`), `page` (`0..10000`), `size` (`1..50`).
  - `CancelAppointmentRequest.php`: `motivo` (`1..2000`, no vacío), `confirmacion` (`accepted`).
  - `RescheduleAppointmentRequest.php`: `codSlotNuevo` requerido alfanumérico `<= 64`, `motivo` (`1..2000`, no vacío), `confirmacion` (`accepted`).

### 2. Contratos de respuesta y mapeo de errores

#### DTO principales entregados por los servicios

- **`PatientProfileService::profile()`**
  - Claves: `codPac`, `codUsu`, `nombreCompleto`, `email`, `ci`, `fechaNacimiento`, `telefono`, `ciudadResidencia`, `estadoCuenta`, `bloqueadoHasta`, `emailVerificado`, `estadoReserva`, `habilitadoParaReservar` (`bool`), `sancionVigente` (`null` o `{codInc, codCit, tipoIncidencia, motivo, fechaFinSancion, fechaRegistro}`), `resumen` (`{citasProximas, derivacionesPendientes, incidenciasRegistradas}`).
- **`PatientAvailabilityService::slots()`**
  - Claves: `fecha`, `codEsp`, `codMed`, `habilitadoParaReservar` (`bool`), `slots` (lista de `{codSlot, codMed, nombreMedico, codEsp, nombreEspecialidad, fecha, horaInicio, horaFin, estadoDisponibilidad}`). Nunca expone `cod_usu_medico` ni `cod_hor`.
- **`PatientAppointmentService::list()`**
  - Claves: `citas` (cada fila con `codCit`, `codSlot`, `codPac`, `codMed`, `nombreMedico`, `codEsp`, `nombreEspecialidad`, `fecha`, `horaInicio`, `horaFin`, `estado`, `canalReserva`, `motivoCancelacion`, `motivoReprogramacion`, `canceladoEn`, `horaLlegadaCheckin`, `fechaAtencion`, `codCitPadre`, `creadoEn`, `qrDisponible`, `esVencida`), `alcance`, `page`, `size`, `hayMas`.
- **`PatientAppointmentService::detail()` / `reserve()` / `cancel()` / `reschedule()`**
  - Mismos campos de detalle **sin** incluir `tokenQr` en texto (solo booleano `qrDisponible`), añadiendo indicadores de estado de acción para la interfaz: `puedeCancelar` (`bool`), `puedeReprogramar` (`bool`), `puedeVerPaseQr` (`bool`).
- **`PatientAppointmentService::checkInPass()`**
  - Claves: `codCit`, `codPac`, `nombrePaciente`, `nombreMedico`, `nombreEspecialidad`, `fecha`, `horaInicio`, `horaFin`, `estado`, `tokenQr`, `admiteCheckin` (`bool`).

#### Errores de dominio estandarizados (`ClinicalOperationException`)

| HTTP Status | `errorCode` | Cuándo se produce |
|---|---|---|
| `400` | `VALIDACION`, `FECHA_INVALIDA` | Identificadores malformados, rango `desde > hasta`, fecha fuera de ventana `D..D+14`, paginación inválida |
| `401` | `UNAUTHENTICATED` / sesión inválida | Sesión expirada o inválida en middleware `PatientResponses` / `CheckRolSession` |
| `403` | `ROL_INSUFICIENTE`, `PERFIL_INACTIVO`, `PROHIBIDO` | Rol distinto de `ROL001`, usuario o perfil `Inactivo`, correo sin verificar |
| `404` | `NO_ENCONTRADO` | Cita, análisis o especialidad inexistente, o perteneciente a otro paciente (**anti-IDOR**) |
| `409` | `PACIENTE_NO_HABILITADO`, `CITA_VENCIDA`, `QR_NO_DISPONIBLE`, `CONFLICTO` | Paciente sancionado intentando reservar/reprogramar, cita vencida tras fin de turno, cita no `Confirmada` al pedir QR, choque concurrente de slot o paciente |
| `422` | `VALIDACION` (Laravel) | Fallo de reglas en `FormRequest` (JSON o redirect `withErrors` + flash seguro) |
| `503` | `SERVICIO_NO_DISPONIBLE`, `QR_NO_DISPONIBLE` | Indisponibilidad DB temporal (`Retry-After: 30`) |

---

## D. Controles de seguridad y reglas de negocio aplicadas

1. **Cero confianza en parámetros del cliente (`codPac` / `codUsu`):** los servicios `PatientProfileService` y `PatientAppointmentService` ignoran cualquier identificador de paciente enviado en peticiones y derivan `codPac` exclusivamente del `cod_usu` autenticado en la sesión Laravel (`ClinicalRoleGuard::require('ROL001')`).
2. **Prevención estricta de IDOR:** `detail`, `checkInPass`, `cancel`, `reschedule` y `analyses` filtran por `WHERE cod_cit=? AND cod_pac=?` respondiendo uniformemente `404 NO_ENCONTRADO` si la cita pertenece a otro paciente.
3. **Principio de mínimo privilegio en PostgreSQL:** los bloqueos previos en `assertMutableConfirmed` se realizan mediante `SELECT` simple sin `FOR UPDATE` sobre `citas` (puesto que `clinica_app` no posee privilegio `UPDATE` directo sobre `citas`), delegando los bloqueos de fila exclusivos de forma atómica a las funciones `SECURITY DEFINER` `cancelar_cita` y `reprogramar_cita`.
4. **Cierre de brecha temporal en citas vencidas:** mientras `marcar_inasistencias_vencidas()` corre cada 5 minutos, `PatientAppointmentService::cancel()` y `reschedule()` bloquean explícitamente cualquier cita `Confirmada` cuyo `(fecha + hora_fin) <= ahora_clinica()` con `409 CITA_VENCIDA`.
5. **Restricciones de derivaciones y QR:** el paciente puede leer sus derivaciones (`PatientProfileService::referrals()`), pero `PatientAppointmentService::reserve()` invoca `reservar_cita(..., 'Web', NULL)`, respetando que la programación de derivaciones corresponda a Secretaría/Médico/Admin; a su vez, `checkInPass()` solo entrega el QR de citas `Confirmada` no vencidas sin exponer la clave HMAC (`configuracion_seguridad`) ni llamar `registrar_checkin`.

---

## E. Separación clara: completado vs. pendiente

1. **Completado y verificado en Fase 6 (listo para consumo):**
   - Toda la capa de servicios (`PatientProfileService`, `PatientAvailabilityService`, `PatientAppointmentService`, `PatientSupport`), guardia `ROL001` (`ClinicalRoleGuard`), middleware (`PatientResponses`), 6 `FormRequest` en `app/Http/Requests/Paciente/`, y suite real `tests/Postgres/PatientBackendMigrationTest.php` + `ConcurrentPatientReserveWorker.php`.
2. **Pendiente en backend / permisos antes de construir pantallas asociadas:**
   - **Controladores HTTP y rutas de Paciente:** crear los controladores bajo `App\Http\Controllers\Paciente\*` y el grupo de rutas `prefix('paciente')->name('paciente.')->middleware([PatientResponses::class, 'rol:ROL001'])` conectando los servicios y `FormRequest` ya preparados.
   - **Notificaciones al paciente (`RF-26`):** `clinica_app` no tiene privilegio `SELECT` sobre `public.notificaciones` (`scripts/clinical/runtime-permissions.sql`). Cuando se implemente el módulo y el worker de notificaciones (`tomar_notificaciones`/`finalizar_notificacion`), se deberá añadir un script incremental de permisos con `GRANT SELECT (...) ON public.notificaciones TO clinica_app` limitado al usuario propietario de cada notificación.
3. **Asignado a Codex en la fase de diseño visual:**
   - Diseño e implementación de vistas Blade bajo `resources/views/paciente/*` (incluyendo `paciente/error.blade.php`), barra de navegación de paciente (`layouts/paciente.blade.php` o equivalente coherente con Admin/Secretaría/Médico), pantalla de reserva paso a paso, agenda de citas propias, visor QR, historial de derivaciones/análisis e incidencias/sanciones, conservando la accesibilidad y tokens institucionales vigentes.

---

## F. Evidencia de pruebas de integración y concurrencia PostgreSQL

Ejecutado contra la base clínica aislada `clinica_laravel_phase1_tests` (`scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres/PatientBackendMigrationTest.php`) con Quarkus apagado y `Http::fake()` comprobando **0 llamadas HTTP salientes**:

- **Resultado de `PatientBackendMigrationTest.php`:** **6 tests, 6 passed, 0 failed, 0 skipped, 77 assertions (`85,468 ms`)**:
  1. `patient_role_guard_rejects_wrong_roles_inactive_unverified_and_unauthenticated_accounts` — rechaza `403` sin login, roles `ROL002`/`ROL003`/`ROL004`, usuario `Inactivo`, paciente `Inactivo` y `email_verificado = false`.
  2. `patient_consults_availability_reserves_web_excludes_overlaps_lists_and_shows_safe_qr_pass` — valida catálogo activo, ventana `D..D+14` (rechaza `D+15`), reserva `Web`, ocultación del slot ocupado, conflicto `409` en doble reserva, listado `proximas`, auditoría `bitacora` y entrega segura de QR sin autochequeo.
  3. `patient_idor_isolation_blocks_access_and_mutations_on_another_patients_records` — comprueba `404` para detalle, pase QR, cancelación, reprogramación y análisis de otro paciente, además de listados vacíos cruzados.
  4. `patient_can_reschedule_and_cancel_future_appointment_and_cannot_mutate_expired_or_non_confirmed` — valida cadena `cod_cit_padre` en reprogramación `Web`, cancelación con motivo, denegación QR en canceladas (`409 QR_NO_DISPONIBLE`) y bloqueo `409 CITA_VENCIDA` en citas vencidas.
  5. `sanctioned_patient_cannot_reserve_or_reschedule_and_can_read_own_incidences_referrals_and_analyses` — valida lectura de derivaciones y análisis propios, sanción temporal aplicada por `ROL004` y bloqueo `409 PACIENTE_NO_HABILITADO` al intentar reservar o reprogramar.
  6. `concurrent_web_reservations_by_patient_have_single_winner_and_clean_middleware_headers` — lanza dos procesos PHP independientes simultáneos (`tests/Support/ConcurrentPatientReserveWorker.php`) garantizando exactamente **1 ganador**, rechazo transaccional (`P4090`/`23P01`/`23505`) al segundo, cabeceras seguras en `PatientResponses` y limpieza de `actor_actual()` tras la transacción.
