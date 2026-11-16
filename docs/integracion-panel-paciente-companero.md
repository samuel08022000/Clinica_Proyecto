# Integración del panel Paciente del compañero

## Referencia y alcance

- Repositorio: `Proyecto Clinica`.
- Rama histórica de origen: `módulo-paciente`.
- Pull Request de referencia: <https://github.com/samuel08022000/Clinica_Proyecto/pull/1>.
- Commit reconocido: `612d212f185c4276a3d32e92c8b53548f1743432` (`Modulo paciente: citas, perfil y conexion con backend`).
- El commit fue comprobado localmente como objeto `commit` y conserva el resumen original de **22 archivos modificados y 1517 líneas añadidas**.
- No se realizó merge, checkout, restore, reset, clean, rebase, creación de rama, commit ni push.

## Seguridad previa y recuperación

Antes de editar se inspeccionó `git status`. El árbol ya contenía numerosos cambios locales de las Fases 1–6, incluida la eliminación completa de `backend_clinica/`; esos cambios se conservaron.

Referencias recuperables comprobadas:

- `HEAD` previo: `2f3b15341b03287d8ac17612f334badb3abe0342`.
- `stash@{0}`: `respaldo antes de integrar cambios del equipo`.
- `stash@{1}`: `Respaldo frontend Codex fase 1`.
- Respaldo focalizado previo a esta integración: `.integration-backups/patient-panel-20261116/`. Contiene copias con hash SHA-256 de cada archivo existente que la integración modificó, incluida la prueba E2E adaptada.

Los siete archivos solicitados no existían en el árbol actual. Cada uno fue inspeccionado individualmente mediante `git show 612d212f185c4276a3d32e92c8b53548f1743432:<ruta>` antes de recuperarlo.

## Archivos originales recuperados

1. `frontend_clinica/resources/css/paciente.css`
2. `frontend_clinica/resources/views/layouts/paciente.blade.php`
3. `frontend_clinica/resources/views/paciente/home.blade.php`
4. `frontend_clinica/resources/views/paciente/citas.blade.php`
5. `frontend_clinica/resources/views/paciente/nueva-cita.blade.php`
6. `frontend_clinica/resources/views/paciente/perfil.blade.php`
7. `frontend_clinica/resources/views/paciente/_cita.blade.php`

Comparación normalizada contra `612d212`:

- `paciente.css`: **idéntico**, 142/142 líneas.
- `layouts/paciente.blade.php`: **idéntico**, 50/50 líneas.
- `paciente/citas.blade.php`: **idéntico**, 37/37 líneas.
- `paciente/home.blade.php`: conserva estructura y diseño; solo cambia el texto de la acción de perfil de “Actualizar” a “Consultar”, porque el backend actual expone perfil propio de solo lectura.
- `paciente/nueva-cita.blade.php`: conserva sus 88 líneas y flujo visual de tres pasos; los parámetros antiguos `esp`/`med` se adaptaron a los contratos Laravel `codEsp`/`codMed`.
- `paciente/perfil.blade.php`: conserva las tarjetas y la presentación original; el formulario de actualización antiguo se reemplazó por datos de contacto de solo lectura.
- `paciente/_cita.blade.php`: conserva tarjeta, fecha, médico, especialidad, badge y mensajes; las acciones ahora abren el detalle seguro y el formulario de cancelación con motivo/confirmación.

## Código visual conservado

Se mantuvieron la composición original del compañero, la navegación lateral/horizontal, el header, la tarjeta de cita, pestañas, badges, paneles, grilla, selector de horarios, formularios y responsive móvil. No se realizó rediseño ni se introdujo un sistema visual alternativo.

El CSS original se incorpora desde la versión actual de `resources/css/app.css` mediante `@import './paciente.css';`; no se restauró una versión histórica de `app.css`.

Para completar funciones ya soportadas por Fase 6 se añadieron, con los mismos componentes y clases visuales:

- `resources/views/paciente/detalle.blade.php`.
- `resources/views/paciente/reprogramar.blade.php`.
- `resources/views/paciente/error.blade.php`.

## Adaptación a Laravel puro

### Controladores y rutas

Se creó el namespace `App\Http\Controllers\Paciente`:

- `DashboardController`: compone inicio, próxima cita y resumen.
- `AppointmentController`: listado, búsqueda de disponibilidad, reserva, detalle, cancelación y reprogramación.
- `ProfileController`: consulta el perfil del paciente autenticado.

El grupo `paciente.*` actual utiliza:

```php
->middleware([PatientResponses::class, 'rol:ROL001'])
```

Se conservan autenticación Laravel, revalidación de cuenta, rol `ROL001`, CSRF, constraints alfanuméricos y respuestas privadas sin caché.

### Servicios de Fase 6 reutilizados

No se reconstruyó el backend ni se creó otro motor de reservas. Los controladores consumen directamente:

- `PatientProfileService`.
- `PatientAvailabilityService`.
- `PatientAppointmentService`.
- Sus `FormRequest` de reserva, cancelación y reprogramación.
- `PatientResponses` y `ClinicalRoleGuard`.
- Las funciones PostgreSQL ya existentes para reservar, cancelar y reprogramar.

Se añadieron tres contratos pequeños (`PatientProfileProvider`, `PatientAvailabilityProvider`, `PatientAppointmentManager`) y bindings en la versión actual de `AppServiceProvider`. Los servicios existentes implementan esos contratos sin alterar su lógica clínica; esto permite probar el cableado HTTP sin simular PostgreSQL en la suite Feature.

`PatientAppointmentPresentation` adapta únicamente los DTO de Fase 6 a los nombres visuales que esperan las tarjetas Blade (`fechaTexto`, `horaTexto`, badge y permisos visibles). La autorización real permanece en servicios y PostgreSQL.

### Identidad y protección de propiedad

- Ninguna ruta o formulario acepta `codPac` o `codUsu` como identidad del paciente.
- Los controladores solo pasan `codSlot`, `codCit`, `codSlotNuevo` y motivos a los servicios.
- `PatientSupport::patient()` deriva `codPac` desde el `cod_usu` autenticado en la sesión Laravel.
- Detalle, cancelación y reprogramación conservan la protección anti-IDOR de Fase 6 (`404` uniforme para citas ajenas).
- Las pruebas Feature envían deliberadamente un `codPac` ajeno y verifican que nunca se transmite al contrato clínico.

## Código histórico no recuperado

No se restauró ninguno de estos elementos:

- `backend_clinica/`, Java, Maven ni `application.properties`.
- `QuarkusPacienteApi.php`, `MockPacienteApi.php`, `PacienteApi.php` ni dependencias HTTP internas.
- Variables `PATIENT_API_DRIVER` o configuración Quarkus.
- Versiones antiguas de `routes/web.php`, `config/clinic.php`, `AppServiceProvider.php` o `resources/css/app.css`.
- Migraciones históricas.

La búsqueda final en controladores, contratos, servicios Paciente, vistas, rutas, configuración y estilos activos produjo **0 coincidencias** para referencias prohibidas de Quarkus/driver/backend Java.

## Funcionalidades verificadas

- Inicio del Paciente con próxima cita y resumen.
- Perfil propio, sin identificador de paciente editable.
- Catálogo de especialidades y médicos.
- Consulta de disponibilidad dentro de `D..D+14`.
- Reserva web con el paciente derivado de sesión.
- Listado de citas próximas, pasadas y canceladas.
- Detalle de cita propia.
- Cancelación permitida con motivo y confirmación.
- Reprogramación permitida con nuevo slot, motivo y confirmación.
- Rechazo de otros roles y sesiones revocadas/expiradas.
- Protección de propiedad/anti-IDOR en operaciones de citas.
- Reserva concurrente con un único ganador.
- Ausencia de llamadas HTTP internas al backend.

## Pruebas ejecutadas

| Verificación | Comando | Resultado |
|---|---|---|
| Feature Paciente | `php artisan test --compact tests/Feature/PatientPanelTest.php` | **14 passed**, 68 assertions |
| Feature completa | `php artisan test --compact` | **244 passed**, 1564 assertions |
| PostgreSQL Paciente + HTTP real + autorización + reserva + concurrencia | `scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres/PatientBackendMigrationTest.php` | **7 passed**, 106 assertions |
| PostgreSQL completa + E2E de cuatro roles | `scripts/clinical/Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres` | **45 passed**, 708 assertions |
| Sintaxis PHP | `php -l` sobre contratos, servicios, proveedor, presenter, request, controladores, rutas y prueba modificados | **Sin errores** |
| Laravel Pint | `vendor/bin/pint --format agent` limitado a los PHP de esta integración | **Passed/fixed**; solo ajustó formato del controlador de citas |
| Compilación Blade | `php artisan view:cache` | **Passed**; caché limpiada después con `view:clear` |
| Compilación Vite | `npm.cmd run build` | **Passed**, Vite 8.3.5 |

La suite Paciente incluye una prueba HTTP real de 29 aserciones que inicia sesión, renderiza las vistas, busca disponibilidad, reserva, consulta detalle, reprograma y cancela contra PostgreSQL. La suite PostgreSQL completa incluye además las pruebas de concurrencia de reserva Paciente, reserva de recepción, check-in y atención médica. La suite Feature completa verifica que Administración, Secretaría, Médico, portal y autenticación continúan funcionando.

## Problemas pendientes y decisiones explícitas

1. **Edición de perfil no habilitada:** Fase 6 solo proporciona consulta segura de perfil. No se inventó una escritura directa ni una función PostgreSQL nueva. La vista indica contactar con la clínica.
2. **Paginación visual:** el diseño original muestra hasta 50 resultados por pestaña; cuando el servicio indica más páginas, el contador añade `+`. No se rediseñó el listado para incorporar paginación.
3. **Advertencia opcional de Vite:** el build informa que `fontaine` permitiría optimizar fallbacks de fuentes. La compilación finaliza correctamente y no se añadió esa dependencia innecesaria.
4. **QR del paciente:** el backend de Fase 6 mantiene su servicio seguro de pase QR, pero el frontend histórico recuperado no incluía pantalla QR y no forma parte del conjunto funcional solicitado en esta integración.

## Estado final

La integración queda en Laravel 13 + PostgreSQL 16, sin Quarkus y sin comunicación HTTP interna. El diseño original del compañero se mantiene y las operaciones clínicas se delegan a los servicios y funciones PostgreSQL de Fase 6. No se realizó commit ni push.
