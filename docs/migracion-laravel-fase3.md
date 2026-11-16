# Migración a Laravel — Fase 3 de 5

Fecha de verificación: 2026-11-15. Zona clínica: `America/La_Paz`.

## A. Estado de implementación

### Administrador

Implementado directamente en Laravel y PostgreSQL, sin HTTP interno ni credenciales Quarkus:

- médicos: listado, búsqueda local sobre el catálogo, detalle, alta, edición, vínculo lógico con especialidades, cambio de estado y revocación de sesiones;
- secretarías: listado, detalle, alta, edición, cambio de estado, cambio administrativo de contraseña bcrypt y revocación;
- especialidades: listado, filtros, detalle, alta, edición, activación/inactivación y médicos vinculados;
- agendas: horarios ISO, pausas, duración 20/30/45/60, estados y generación PostgreSQL de slots;
- bloqueos: alta, consulta, trazabilidad y citas afectadas;
- pacientes: listado paginado, búsqueda, detalle, habilitación efectiva, incidencias y sanciones;
- sanciones: alta por `registrar_sancion`, vigencia, historial y bloqueo efectivo de reservas.

### Secretaría

Implementado directamente en Laravel y PostgreSQL:

- búsqueda y detalle administrativo de pacientes;
- especialidades activas, médicos elegibles y disponibilidad D..D+14;
- reserva atómica por `reservar_cita`, canal Ventanilla y QR generado por PostgreSQL;
- reserva con `codDer` opcional; paciente y destino se reconstruyen desde el ticket servidor;
- listado, detalle, filtros, historial y paginación de citas;
- cancelación, reprogramación enlazada e inasistencia;
- acreditación y check-in QR por `verificar_token_qr`, `cita_admite_checkin` y `registrar_checkin`;
- consulta interna de derivaciones pendientes y protección de ticket consumido.

No se crearon motores PHP paralelos de slots, QR, reserva o sanciones. Las vistas Blade y su diseño se conservaron. Solo se añadieron los campos ocultos necesarios para transportar un `codDer` ya validado.

### Integración y seguridad

`AdministrationService` y `ReceptionService` son fachadas locales de casos de uso. Los controladores Blade ya no inyectan `QuarkusApiClient`. Cada servicio vuelve a comprobar el rol y el perfil activo, incluso si se invoca fuera del middleware. Las escrituras usan `ClinicalTransaction`, que fija `app.cod_usu` en la misma conexión y transacción.

`QuarkusApiClient` se conserva porque Quarkus sigue siendo referencia histórica y `PortalController` aún lo usa para el catálogo público. No hay referencias al cliente Quarkus dentro de controladores o servicios de Administración y Secretaría.

### Procesos programados

- `clinical:maintenance slots`: diario a las 00:00, zona `America/La_Paz`.
- `clinical:maintenance reception`: cada cinco minutos para inasistencias vencidas y sanciones vencidas.
- ambos usan `withoutOverlapping`; no se inició ningún scheduler Quarkus.
- se requiere ejecutar un único `php artisan schedule:work` o configurar un único cron de Laravel en producción.

## B. Interacción con PostgreSQL

Tablas principales usadas: `usuarios`, `roles`, `medicos`, `secretarias`, `pacientes`, `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `bloqueos_citas_afectadas`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`, `notificaciones` y `bitacora`.

Vistas usadas: `v_agenda_disponibilidad` y `v_historial_citas`.

Funciones reutilizadas:

- `actor_actual`, `fecha_clinica`, `ahora_clinica` y `estado_reserva_paciente`;
- `bloquear_agenda_medico` y `generar_slots_agenda`;
- `reservar_cita`, `cancelar_cita`, `reprogramar_cita`;
- `verificar_token_qr`, `cita_admite_checkin`, `registrar_checkin`;
- `registrar_inasistencia`, `marcar_inasistencias_vencidas`;
- `registrar_sancion` y `desbloquear_sanciones_vencidas`.

Se conservan los triggers de validación, regeneración de slots, eventos de cita, sanciones, bloqueos y auditoría. No se alteraron B9, V10 o R. El script incremental `phase3-permissions.sql` concede a `clinica_app` solo columnas de personal/catálogos/agenda y ejecución de las funciones autorizadas. No concede `DELETE`, `INSERT` directo en `citas`, superusuario, creación de esquema ni acceso al secreto QR.

Verificación final de la base principal:

- PostgreSQL 16.15;
- 21 tablas de dominio y soporte;
- 2 vistas;
- 42 funciones clínicas;
- 32 triggers;
- 40 claves foráneas, 80 índices y 2 exclusiones;
- 0 restricciones inválidas y 0 triggers deshabilitados;
- B9, V10 y R aplicadas correctamente;
- 0 usuarios en la base principal;
- QR inicializado, sin exponer el secreto.

## C. Archivos

### Creados

- `frontend_clinica/app/Exceptions/ClinicalOperationException.php`: error de dominio seguro y representación HTTP.
- `frontend_clinica/app/Services/Clinical/ClinicalRoleGuard.php`: autorización de servicio y revalidación de perfil.
- `frontend_clinica/app/Services/Clinical/ClinicalMaintenanceService.php`: trabajos SQL sin identidad humana residual.
- `frontend_clinica/app/Services/Administration/AdministrationService.php`: casos de uso administrativos.
- `frontend_clinica/app/Services/Reception/ReceptionService.php`: recepción, citas, QR y derivaciones.
- `frontend_clinica/app/Console/Commands/ClinicalMaintenance.php`: comando de mantenimiento.
- `frontend_clinica/app/Console/Commands/BootstrapClinicalAdministrator.php`: aprovisionamiento interactivo del primer ROL004, sin contraseña por argumentos ni valor predeterminado.
- `frontend_clinica/app/Http/Requests/Secretaria/RescheduleRequest.php`: contrato de reprogramación.
- `scripts/clinical/phase3-permissions.sql` y `Apply-Phase3-Permissions.ps1`: permisos incrementales y protegidos por marcador de base aislada.
- `frontend_clinica/tests/Postgres/AdministrationReceptionMigrationTest.php`: integración real Fase 3.
- `frontend_clinica/tests/Support/ConcurrentReserveWorker.php` y `ConcurrentCheckinWorker.php`: conexiones independientes para carreras reales.
- este documento.

### Modificados

- controladores de `app/Http/Controllers/Administracion/` y `app/Http/Controllers/Secretaria/`: sustitución del cliente HTTP por servicios locales;
- `ClinicalSqlException.php`, `AdministrativeResponses.php` y `ReceptionResponses.php`: mapeo seguro de SQLSTATE y errores locales;
- `ReservationRequest.php` y `ReservationBrowseRequest.php`: `codDer` opcional;
- `CitaController.php`, `ReservaController.php`, `routes/web.php`: reprogramación y entrada por derivación;
- `routes/console.php`: programación Laravel;
- las dos vistas de reserva: conservación segura de `codDer`;
- `DatabaseSeeder.php`: no crear identidades demo;
- pruebas Feature antiguas: clasificadas como contratos HTTP Quarkus obsoletos; sus reemplazos persistentes están en la suite PostgreSQL.
- `ClinicalInfrastructureTest.php`: ya no supone que la base aislada de pruebas permanece vacía después de otras integraciones.

### Reutilizados sin modificación histórica

Todo `backend_clinica/`, B9, V10, R, las 21 tablas, las vistas, las funciones, triggers y Blade existentes. No se eliminó Quarkus ni se inició la Fase 4.

## D. Contratos y errores

Las rutas visibles existentes se conservaron. Se añadió:

- `POST /secretaria/citas/{cita}/reprogramar`, campos `codSlot`, `motivo`, `confirmacion`.

La reserva acepta `codPac`, `codSlot` y `codDer` opcional. Una URL `GET /secretaria/reservas/nueva?codDer=...` reconstruye `codPac`, `codEsp` y `codMed` desde PostgreSQL; no confía en destinos enviados por el navegador.

Se conservan `codCit`, `nombreCompleto`, `habilitadoParaReservar`, `checkinRegistrado`, `page`, `size` y `hayMas`. Los SQLSTATE P400x/P403x/P404x/P409x, exclusiones, serialización y deadlocks se transforman en 400/403/404/409/503 sin devolver SQL, bindings, stack traces, token QR o secreto HMAC.

## E. Permisos y auditoría

- ROL004 es obligatorio dentro de `AdministrationService`.
- ROL002 y perfil `secretarias.estado=Activo` son obligatorios dentro de `ReceptionService`.
- ROL001/ROL003 no obtienen acceso por invocación directa del servicio.
- el actor proviene de `Auth::guard('web')`, nunca de formularios;
- `ClinicalTransaction` fija `app.cod_usu` con ámbito local a la transacción;
- después de cada operación comprobada, `actor_actual()` vuelve a `NULL`;
- inactivar personal o cambiar su contraseña revoca sesiones persistentes;
- los triggers siguen siendo la única fuente de bitácora; PHP no duplica registros.

El comando `clinical:bootstrap-admin` es una excepción de aprovisionamiento inicial local: solo funciona cuando no existe ningún ROL004, pide la contraseña de forma oculta, aplica la política de 10–72 bytes con mayúscula/minúscula/número y se serializa mediante advisory lock. No se ejecutó durante esta fase.

## F. Pruebas reales

Comandos principales ejecutados:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Verify-Database.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres --testdox
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit --testdox
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase artisan clinical:maintenance slots
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase artisan clinical:maintenance reception
npm.cmd run build
vendor\bin\pint --test <archivos de Fase 3>
```

Resultados:

- suite PostgreSQL Fases 1–3: 27 pruebas, 334 aserciones, 27 aprobadas, 0 fallidas, 0 omitidas;
- pruebas específicas Fase 3: 10 pruebas, 71 aserciones;
- suite Feature global: 246 casos, 28 aprobados, 0 fallidos, 218 omitidos, 174 aserciones;
- los omitidos son contratos HTTP Quarkus antiguos de Administración/Secretaría, pruebas live de Java no iniciado y panel Paciente fuera de alcance; no se hicieron pasar con tokens falsos;
- concurrencia real: dos procesos y dos conexiones `clinica_app`; una sola reserva ganó y un solo check-in ganó;
- mantenimiento en base de pruebas: 0 slots adicionales, 0 inasistencias y 0 desbloqueos en ese instante, ejecución exitosa e idempotente;
- Vite 8.3.5: compilación aprobada; aviso no bloqueante por dependencia opcional `fontaine`, no instalada por la restricción de no añadir dependencias;
- Pint dirigido: aprobado;
- 80 rutas Laravel registradas;
- contenedores activos durante la prueba: PostgreSQL Fase 1 y la base habitual; ningún contenedor Quarkus estaba activo.

Los fixtures Fase 3 quedan únicamente en `clinica_laravel_phase1_tests`. No se borró la bitácora porque es inmutable. La base principal mantuvo 0 usuarios y no recibió datos demo.

## G. Problemas encontrados y correcciones

- Los tests HTTP heredados pretendían autenticar con `auth_token` Quarkus. Se clasificaron como obsoletos y se sustituyeron por identidad Laravel y PostgreSQL real.
- Una limpieza inicial de fixtures intentó borrar bitácora y PostgreSQL la rechazó correctamente. Se retiró; los fixtures usan datos únicos en la base aislada.
- Las vistas administrativas exigían `habilitadoParaReservar`, `vigente` y la clave `incidencias`; los contratos locales se corrigieron y se verificaron renderizando Blade.
- Los intentos duplicados de reserva/check-in se validaron primero secuencialmente y después con procesos concurrentes independientes.
- PowerShell bloqueó `npm.ps1`; se utilizó `npm.cmd`, sin cambiar políticas del equipo.

## H. Guía manual PowerShell

Desde la raíz del proyecto:

```powershell
# 1. Iniciar únicamente PostgreSQL aislado
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Start-Database.ps1

# 2. Aplicar una vez los permisos incrementales (idempotentes)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Apply-Phase3-Permissions.ps1

# 3. Verificar PostgreSQL, B9/V10/R, tablas y triggers
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Verify-Database.ps1

# 4. Crear interactivamente el primer administrador, solo si aún no existe ROL004
# La contraseña se solicita de forma oculta; no la escriba en el comando.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 artisan clinical:bootstrap-admin

# 5. Iniciar Laravel contra la base principal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 artisan serve --host=127.0.0.1 --port=8000

# 6. En otra consola, ejecutar el único scheduler autorizado
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Invoke-Laravel.ps1 artisan schedule:work
```

Abra `http://127.0.0.1:8000/login`. Con una identidad autorizada:

1. Administración → Médicos: alta, edición, especialidades y estado.
2. Administración → Secretarías: alta, edición, acceso y estado.
3. Administración → Especialidades, Agendas y Bloqueos.
4. Administración → Pacientes/Sanciones.
5. Cierre sesión e ingrese como Secretaría creada desde Administración.
6. Secretaría → Pacientes → Nueva reserva; seleccione especialidad, fecha, médico y slot.
7. Use el token QR de la cita de prueba en Check-in; no lo copie a logs ni documentación.
8. Revise Citas/Historial; cancele o marque inasistencia solo cuando la ventana lo permita.
9. Para derivación, abra la reserva con `?codDer=<código-de-prueba>`; el servidor impondrá paciente y destino.
10. Pruebe una URL de Administración con Secretaría y una URL de Secretaría con Paciente/Médico; deben devolver 403.

Comprobaciones de proceso, sin detener nada:

```powershell
docker ps --format "{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}"
Get-NetTCPConnection -State Listen | Where-Object LocalPort -in 8080,35443,8000
```

No debe existir un proceso Quarkus escuchando en 8080. Para detener Laravel use `Ctrl+C`. Para detener solo PostgreSQL de esta fase, conservando el volumen:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Stop-Database.ps1
```

## I. Preparación para Fase 4

Funcionan sin Quarkus: autenticación Laravel, Administración completa, recepción, reservas, QR/check-in, cancelación, inasistencia, sanciones, reprogramación y consumo de derivaciones.

Pendiente de Fase 4: agenda clínica del Médico, atención, cierre `marcar_cita_atendida`, emisión de derivaciones, análisis clínicos y sus vistas/pruebas. Pueden reutilizarse `ClinicalRoleGuard`, `ClinicalTransaction`, `ClinicalOperationException`, consultas de citas, disponibilidad, historial y el mapeo de SQLSTATE. Las funciones `marcar_cita_atendida`, `crear_derivacion` y las operaciones de análisis permanecen sin permisos de aplicación Fase 3 hasta implementar autorización ROL003.

Estado: **Fase 3 implementada y verificada para Administración y Secretaría**. Quarkus permanece intacto como referencia. No se realizó commit ni push.
