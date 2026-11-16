# Fase 11 — Panel de Secretaría

Verificación: 12 de noviembre de 2026. Implementación sobre Laravel 13 y Blade existentes, exclusiva de ROL002. No se modificaron Quarkus, migraciones, autenticación, secretos, dependencias ni configuraciones de IA.

## Estado de entrega

Los módulos solicitados están implementados con solicitudes a los endpoints reales configurables de Quarkus. Se conservan `/secretaria/inicio`, su nombre `secretaria.home` y las rutas administrativas.

**La integración real Laravel → Quarkus → PostgreSQL no pudo ejecutarse en esta fase.** Los servicios no respondieron en `127.0.0.1:8080` ni `127.0.0.1:18090`; el entorno aislado de Fase 9 había sido retirado según su informe. No se arrancó el backend contra una base desconocida ni se utilizaron datos clínicos reales.

Hay tres evidencias distintas: pruebas Feature con `Http::fake`; revisión de respuestas Blade con assets de producción en Chrome; y recorridos con navegador, Laravel HTTP, cookies y CSRF reales contra un servidor HTTP simulado y aislado. Ninguna sustituye la prueba pendiente con Quarkus y PostgreSQL.

## A. Diagnóstico inicial

- Git inicial: seis documentos universitarios eliminados por el usuario, `EVALUACION PROCESUAL.pdf` no rastreado y el informe de Fase 10 no rastreado. Se conservaron esos estados.
- Se inspeccionaron Composer, package.json, Vite, configuración, rutas, controladores, middleware, cliente HTTP, vistas, CSS, JS y pruebas existentes. PHP 8.5.10, Composer 2.10.3 y Node 22.21.0 estaban disponibles; Laravel 13, Tailwind 4 y Vite 8.3.5 ya estaban instalados.
- La Fase 10 está implementada y versionada en el código actual: 41 rutas ROL004, controladores administrativos, Form Requests, 13 componentes Blade y su hoja de estilo. Su prueba de regresión pasó antes de ampliar Secretaría.
- Secretaría seguía usando el inicio genérico de `AuthController`; los controladores clínicos heredados no implementaban estos flujos. No se duplicaron clases operativas existentes.
- `CheckRolSession` verifica cada petición con autorización remota. El token permanece en la sesión cifrada del servidor. Se conserva el comportamiento 401/revocación y 503 recuperable.
- `QuarkusApiClient` ya centralizaba URL, Bearer, tiempos de espera, booleanos y errores JSON directos. Se reutiliza `administrative`, cuyo nombre histórico no implica autorización ROL004.
- Línea base: **156 casos, 139 aprobados, 17 omitidos, 902 aserciones**.
- El inventario de Fase 9 se contrastó con `SecretariaController`, `RecepcionDtos`, `RecepcionService`, `GestionCitaService`, `AtencionCheckinService`, el repositorio de funciones y el contrato SQL de check-in. No se añadieron endpoints.

## B. Skills detectadas y utilizadas

Se localizaron SKILL.md, scripts y referencias dentro de `.agents/skills`; no se modificaron ni reinstalaron. Se aplicaron `ui-ux-pro-max`, `design-system` y `design-taste-frontend`, incluida su referencia específica de app screens.

| Herramienta | Uso real y resultado |
| --- | --- |
| `ui-ux-pro-max/scripts/search.py` | Healthcare/reception/appointments con `--design-system`, variance 3, motion 2, density 7; consulta específica `--stack laravel`; UX de resumen de errores y tablas responsive. |
| Reintento de búsqueda | El generador propuso una composición de landing; el segundo brief también mezcló recomendaciones de marketing. No se aplicaron ni persistieron esas composiciones, fuentes o paletas. Se conservaron los tokens de Fase 10 y se usó la referencia app. |
| Resultados aplicados | Labels, errores junto al campo y resumen enfocable, old input seguro, paginación real y tablas dentro de su región de scroll. La sugerencia de Eloquent para paginar se adaptó al contrato remoto; no se consultó la base clínica desde Laravel. |
| `design-system` | Referencias de arquitectura de tokens, primitivos, semánticos, componentes y estados. `validate-tokens.cjs` aprobó las vistas de Secretaría. |
| `draw.mjs --app` | Dirección **Framed sheet**, seed **1490212089**, `--kind smb-admin --keep palette,type`; misma familia de composición que Administración. Sin redibujos. |
| `lint.mjs --app` | CSS/JS nuevos: 0 FAIL, 0 WARN. Las 26 respuestas HTML exportadas: 0 FAIL, 0 WARN. |
| `inspect.mjs --app` | Chrome instalado, assets finales de Vite, 1440/768/390 px; informes y capturas en storage privado. Algunos intentos simultáneos agotaron CDP; se repitieron individualmente. |

El buscador se ejecutó con Python 3.14.4 en `C:/Users/LENOVO/AppData/Local/Python/pythoncore-3.14-64/python.exe`, ya disponible. No se instaló Python ni un navegador. Los scripts usan biblioteca estándar de Python o Node y Chrome existentes.

`AGENTS.md` pide configurar Boost; la dependencia ya existe. No se ejecutó `boost:install`, porque el usuario prohíbe modificar herramientas de IA. No se cambió el framework ni se añadieron shadcn, Radix o bibliotecas de cámara.

## C–D. Sistema visual y componentes compartidos

La fuente principal sigue siendo **Instrument Sans 400/500/600**, alojada con Vite. Se utilizan directamente los tokens de `administracion.css`: fondo `#F8FAFC`, superficie blanca, texto `#172B4D`, marca `#16324F`, acción `#2563EB`, secundario `#526176`, bordes `#E2E8F0`, controles `#8492A6` y estados verde/ámbar/rojo existentes.

Se conservan espaciados de 4 px, separaciones habituales 16/24 px, controles de 44 px, foco de 3 px, sidebar de 232 px y radios existentes. `secretaria.css` solo añade disposiciones para paciente, filtros, slots y estados; no define otra paleta o tipografía.

Se reutilizan los trece componentes `x-admin.*`: botones, campos, tabla, paginación, badges, alertas, confirmaciones, formularios, encabezados, iconos, mensajes y vacíos. El prefijo es histórico: estos componentes no contienen reglas de autorización.

Componentes compartidos nuevos:

- `workspace`: shell único con sidebar, header, cuenta, breadcrumb, contenido y diálogos nativos; parametrizado por área y navegación.
- `workspace-brand`: identidad común para ambos roles.
- `appointment-status`: los siete estados literales de Quarkus, con texto y tonos semánticos.
- `appointment-summary`: resumen de turno/cita sin QR.
- `appointments-table`: tabla de citas e historial sobre la tabla existente.
- `patient-results`: búsqueda administrativa y selección de paciente.

Cambios compatibles en componentes existentes: dos SVG uniformes para QR/historial; alertas que aceptan errores locales de una consulta; texto de ayuda de scroll configurable con el valor original por defecto. El layout administrativo usa el shell compartido conservando sus estilos y navegación.

## E. Vistas Blade

```text
resources/views/
  layouts/{administracion,secretaria}.blade.php
  components/{workspace,workspace-brand,appointment-status,
    appointment-summary,appointments-table,patient-results}.blade.php
  secretaria/
    dashboard.blade.php
    error.blade.php
    partials/{navigation,appointment-filters}.blade.php
    pacientes/{index,show}.blade.php
    reservas/{create,confirm}.blade.php
    citas/{index,show,action}.blade.php
    checkin/{index,attendance,accreditation}.blade.php
    historial/show.blade.php
```

Hay 15 vistas/parciales en `secretaria`; las variantes y estados comprobados generan 26 respuestas de pantalla para revisión.

## F. Controladores y validación

Se crearon `Secretaria/DashboardController`, `PacienteController`, `ReservaController`, `CitaController`, `CheckinController` e `HistorialController`, con `ReceptionController` compartido. Este último llama al cliente existente y comprueba que una escritura devuelva código de cita y estado esperados antes de anunciar éxito; no implementa transacciones ni reglas de disponibilidad.

Siete Form Requests: base `ReceptionRequest`, búsqueda, filtros de citas, consulta de reserva, confirmación de reserva, check-in y acción de cita. Valida estructura y mejora la experiencia; Quarkus y PostgreSQL mantienen la autoridad de negocio.

`AppointmentPresentation` determina únicamente qué acciones mostrar según estado, asistencia y fin del turno en America/La_Paz. Un POST directo sigue enviándose a Quarkus, que revalida la transición. No modifica estados, slots, incidencias o sanciones desde PHP.

## G–I. Pantallas, rutas y endpoints

Hay **17 rutas** bajo `/secretaria`, todas con `ReceptionResponses` y el middleware existente `rol:ROL002`. Las cuatro escrituras usan POST y CSRF. Los endpoints siguientes llevan el prefijo `/api/v1`.

| Pantalla / ruta Laravel | Funciones | Quarkus |
| --- | --- | --- |
| Dashboard `/secretaria/inicio` | Citas del día clínico, hasta 20 por consulta; búsqueda, reserva, citas, check-in e historial. No totales globales. | GET `/secretaria/citas?page=0&size=20` |
| Pacientes `/secretaria/pacientes?q=…` | CI, nombre o correo; búsqueda explícita, 3 caracteres alfanuméricos mínimo, máximo 100, límite backend de 20 coincidencias; perfiles e historial. | GET `/secretaria/pacientes` |
| Perfil `/secretaria/pacientes/{paciente}` | Datos administrativos, correo verificado, cuenta y habilitación efectiva; restricción explicada y sin reserva precargada cuando está inhabilitado. | GET `/secretaria/pacientes/{codPac}` |
| Reserva `/secretaria/reservas/nueva` | Buscar/seleccionar paciente, especialidad activa, médico, fecha y slots; resumen previo y confirmación. | GET pacientes/detalle, `/secretaria/especialidades`, `/secretaria/medicos?codEsp=…`, `/secretaria/disponibilidad?codEsp=…&fecha=…&codPac=…&codMed=…` |
| Confirmar reserva POST `/secretaria/reservas` | Solo codPac/codSlot; éxito del backend y enlace a detalle; 409 conserva contexto y consulta slots otra vez, sin mantener seleccionado el rechazado. | POST `/secretaria/citas` |
| Gestión `/secretaria/citas` | Filtros fecha o rango, estado, paciente, médico, página y tamaño; base cero, tamaño inicial 20, máximo 50; siete estados literales. | GET `/secretaria/citas` |
| Detalle `/secretaria/citas/{cita}` | Reserva, canal, secretario, asistencia, acreditación, historial y acciones compatibles. | GET `/secretaria/citas/{codCit}` y `/asistencia` |
| Check-in GET/POST `/secretaria/checkin` | Lector como teclado o pegar QR; valida y acredita llegada; credencial oculta y no conservada. | POST `/secretaria/checkin` con `{tokenQr}` |
| Asistencia `/secretaria/citas/{cita}/asistencia` | Estado, llegada y responsable informados por backend. | GET `/secretaria/citas/{codCit}/asistencia` |
| Acreditación `/secretaria/citas/{cita}/acreditacion` | qrValido/admiteCheckin, sin reconstruir HMAC ni extraer QR. | GET `/secretaria/citas/{codCit}/acreditacion` |
| Cancelación GET/POST `/secretaria/citas/{cita}/cancelar` | Resumen, motivo de 1–2000 caracteres, confirmación y resultado auditado. | POST `/secretaria/citas/{codCit}/cancelar` con `{motivo}` |
| Inasistencia GET/POST `/secretaria/citas/{cita}/inasistencia` | Resumen, asistencia, confirmación; incidencia y responsable del resultado. | POST `/secretaria/citas/{codCit}/inasistencia` con **`{}`** |
| Historial `/secretaria/historial` y `/secretaria/pacientes/{paciente}/historial` | Localizar paciente, consulta administrativa, rango/estado y paginación remota. | GET búsqueda/perfil y `/secretaria/pacientes/{codPac}/historial` |

Todas estas conexiones están implementadas y probadas mediante dobles. La ejecución contra Quarkus real permanece pendiente por indisponibilidad de infraestructura.

La omisión de fechas en el dashboard es deliberada: el servicio real `GestionCitaService.listar` aplica `public.fecha_clinica()` cuando no se proporciona fecha/rango. No existe un endpoint de reloj clínico. Las pistas min/max del selector de reserva usan el reloj del servidor Laravel en America/La_Paz; la API/SQL decide D..D+14 y muestra su rechazo si hay una diferencia entre relojes. El navegador no calcula la ventana.

## J. Flujos funcionales

1. Buscar paciente → perfil → nueva reserva → especialidad/fecha → disponibilidad → slot → resumen → confirmación → detalle de la cita confirmada. Los filtros se pliegan cuando hay resultados para priorizar los turnos, con posibilidad de editarlos.
2. Si la reserva recibe 409, vuelve a la misma consulta con paciente/especialidad/fecha/médico y una disponibilidad nueva. No muestra éxito ni reenvía automáticamente.
3. Abrir check-in → escanear/pegar → POST → asistencia con En Espera, llegada y actor. Duplicada, QR inválido, fecha/estado incompatible y caídas muestran mensajes seguros.
4. Detalle → cancelar con motivo y confirmación → estado y datos de auditoría recibidos. No se ofrece tras check-in o con estado incompatible.
5. Detalle de cita confirmada finalizada sin llegada → inasistencia → incidencia informada por backend. No se permite una fecha manipulada ni una tolerancia añadida.
6. Perfil → historial → rango/estado → página siguiente/anterior, manteniendo el orden recibido.

## K. Estados, errores y seguridad

- ROL001, ROL003 y ROL004 no pueden usar las rutas de recepción. No se amplían permisos en Quarkus ni se aceptan actores, roles, canal o secretario desde el formulario.
- Bearer en sesión del servidor, sin HTML, JS, localStorage o sessionStorage. Cada petición vuelve a validar la sesión remota.
- 401 invalida sesión; 403/404/423 muestran rechazo; 400/409/422 conservan contexto permitido y mensajes de negocio seguros; caídas, timeouts, 5xx y JSON ilegible son recuperables. No hay reintentos de escrituras.
- Un resultado de escritura desconocido recomienda consultar cita/asistencia antes de repetir; una respuesta 200 con DTO inválido no se anuncia como éxito.
- `ReceptionResponses` aplica no-store/private, Pragma no-cache y Referrer-Policy no-referrer. La caída del autorizador conserva sesión y bloquea operaciones clínicas.
- `safeInput` usa lista permitida. QR, Bearer, passwords, actores y campos imprevistos no se guardan como old input. El Form Request de recepción también excluye QR al fallar validación.
- El QR viaja únicamente en el cuerpo del POST. El campo se presenta oculto, vacío tras rechazos y al volver mediante historial/bfcache. No hay cámara, generador alternativo, token en URL o impresión simulada.
- El cliente redacta credenciales reflejadas por errores upstream. Los errores QR no exponen firma, HMAC o detalles SQL. `QuarkusApiException` mantiene `ShouldntReport`.
- Doble envío accidental: formularios compartidos deshabilitan submit, indican envío y usan diálogo cuando corresponde. No se presenta como idempotencia del backend.
- Acciones sensibles requieren confirmación también en servidor. Los valores no se aceptan por estar presentes en un input oculto: los controladores envían exclusivamente el contrato permitido.
- Vacíos de pacientes, especialidades, médicos, slots e historial, errores de campos, conflictos, éxito y acciones incompatibles tienen salidas explícitas. No hay indicadores o datos de producción inventados.

## L–N. Pruebas, Vite y Pint

| Verificación | Resultado |
| --- | --- |
| Batería nueva de Secretaría | **103 aprobadas, 696 aserciones, 0 fallos/errores/omisiones** |
| Suite completa final | **259 casos: 242 aprobados, 17 omitidos, 1598 aserciones, 0 fallos/errores** |
| Regresión administrativa | 87 aprobadas, 632 aserciones |
| Omisiones heredadas | 17: cinco demo opt-in, siete de backend real y cinco del stack HTTP real |
| Vite | `npm.cmd run build`: aprobado, Vite 8.3.5 |
| Pint | Aprobado sobre PHP creado/modificado |
| Rutas | 17 de Secretaría; 41 administrativas conservadas |
| Blade | `php artisan view:cache`: aprobado |
| Linter visual | 26 respuestas HTML y CSS/JS nuevos: 0 FAIL, 0 WARN |

Las pruebas cubren los escenarios de autorización, búsquedas y límites, inhabilitación, catálogos vacíos, selección fresca de slot, payload exacto, conflictos, ventana validada por API, paginación, siete estados, QR y privacidad, cancelación, inasistencia, historial, respuestas inválidas, conexión y CSRF. Las regresiones existentes de login, OTP, OAuth/recuperación con dobles, cliente y middleware se ejecutaron en la suite completa.

Hubo fallos iniciales que se corrigieron: búsqueda enviada como arreglo, regla incompatible de validación de rango, propagación de errores de consulta al componente compartido y expectativas incorrectas de dos pruebas. Los resultados de cierre corresponden al código corregido.

Vite conserva el aviso preexistente de `fontaine` opcional; no impide compilar o cargar Instrument Sans. Se utilizó `npm.cmd` por la política de PowerShell existente, sin cambiarla. No se alteraron lockfiles.

## O–P. Navegador, capturas y responsive

- **78 combinaciones**: 26 respuestas Blade × 1440/768/390 px, comprobación de título, labels, fuente, CSRF, secretos ausentes, ausencia de desbordamiento global o recorte fuera de tablas y consola sin excepciones. Resultado final: **0 fallos**.
- Menú, foco inicial, Escape/devolución de foco, diálogo de confirmación, un único envío aceptado ante repetición rápida y orientación 844×390 con movimiento reducido: aprobados.
- Chrome + servidor Laravel real, sesiones/cookies y CSRF reales + upstream HTTP sintético: **15 comprobaciones aprobadas**; login, búsqueda, disponibilidad, reserva, check-in, duplicada, cancelación, inasistencia, historial, conflicto, contrato mínimo y rechazo CSRF 419. Este recorrido usa almacenamiento y clave efímeros propios, sin modificar `.env`; sus procesos se detuvieron al acabar.
- Inspector de la skill: pantallas principales y estados, a tres anchuras; resultados concretos por pantalla en los reportes privados. Se abrieron capturas para revisar la composición.
- Desktop: sidebar visible, hoja y formularios con el mismo ancho y jerarquía del Administrador. Tablet: sidebar plegado, cuenta y campos reorganizados. Móvil: una columna, controles de 44 px, tablas en regiones horizontales etiquetadas/enfocables; todas las columnas siguen disponibles.
- El primer chequeo marcó tres fallos de fuente en una misma respuesta sin preload, porque comprobaba antes de solicitar el peso 400; al esperar explícitamente su carga, las 78 combinaciones pasaron. No era un desbordamiento del producto.
- No se afirma haber capturado el inicio genérico antes de editarlo; se inspeccionó su código. Las capturas finales proceden de respuestas actuales de las rutas protegidas, sin un modo demo de producción.

Artefactos locales ignorados:

```text
storage/logs/fase11-suite.xml
storage/app/private/fase11/design/direction.json
storage/app/private/fase11/browser-qa.json
storage/app/private/fase11/http-browser-qa.json
storage/app/private/fase11/visual/<pantalla>/report.json
storage/app/private/fase11/visual/<pantalla>/{desktop,tablet,mobile}-fold.jpg
storage/app/private/fase11/visual/<pantalla>/{desktop,tablet,mobile}-page-01.jpg
storage/app/private/fase11/{menu-open,confirmation-open}.jpg
storage/app/private/fase11/http-{reserva-exitosa,checkin-exitoso,reserva-conflicto}.jpg
```

Las personas de capturas son exclusivamente fixtures sintéticos en `tests/Support/ReceptionFixtures.php`. No hay sample data que sustituir en pantallas de producción, fotografías ni recursos externos que acreditar.

## Q–R. Pendientes y límites

1. Aceptación con Quarkus y PostgreSQL en una base nueva aislada autorizada: persistencia, concurrencia real, firma QR, restricciones SQL y auditoría. Las pruebas de esta fase no demuestran esas transacciones.
2. **Alta presencial de pacientes no implementada:** no existe POST `/secretaria/pacientes` ni política aprobada de identidad/contacto/OTP. Se utiliza el paciente ya registrado.
3. **Entrega/impresión del QR pendiente:** las respuestas administrativas solo informan existencia/validez. No se inventó un endpoint para obtener el token.
4. No hay reprogramación automática ni levantamiento de sanciones desde Secretaría.
5. Sin endpoint de reloj clínico: sincronizar relojes del despliegue; las fechas de UI son pistas y las reglas del backend son definitivas.
6. Falta la comprobación con lector de pantalla y dispositivos físicos. Se comprobó teclado, semántica, foco, consola y viewports en Chrome; no se instaló una cámara ni se afirma compatibilidad física de lectores específicos.

## S–T. Archivos y Git

El inventario exacto de archivos de esta fase se añade al final. No se cambió backend, migraciones, secretos, archivos de autenticación, dependencias ni configuración de IA. Las herramientas temporales y sus fixtures están en storage ignorado.

Se conservaron las seis eliminaciones documentales y el PDF no rastreado del usuario, además del informe no rastreado de Fase 10. No hubo staging, branch, commit, push o restauración de documentos.

## U. Recomendaciones para Fase 12

Confirmar primero el alcance y rol de la siguiente fase. Reutilizar workspace, componentes, sesión y cliente; contrastar los contratos disponibles antes de ofrecer nuevas operaciones. Preparar infraestructura aislada reproducible y ejecutar aceptación cruzada de Administración/Secretaría con Quarkus real. Resolver por separado identidad/OTP del registro presencial y entrega segura del QR. **La Fase 12 no se inició.**

## Crítica visual

ROUND 1

Seen in one second: recepción clínica con acceso a reserva y llegadas.

Idea (one sentence): el paciente y el turno enlazan la reserva con su llegada y sus incidencias.

Live thing: búsqueda explícita, consulta de cupos y confirmaciones reales de formularios Blade.

Layout words by section: shell=hoja compartida / hoy=tabla / reserva=selección y resumen / llegada=campo y resultado.

Scores: design 7.5 · usability 7.2 · creativity 7.0 · content 8.0 → overall 7.4.

Caps applied: las cuotas de imágenes y movimiento de landing no corresponden a app screens.

Top three problems (most visible first), each with the screenshot it shows in and the fix:
1. Dashboard móvil: demasiados accesos precedían a las citas; se pasaron consulta/historial al pie y se redujo el texto auxiliar.
2. Disponibilidad móvil: el formulario repetido desplazaba los slots bajo el primer viewport; se pliega cuando hay resultados y sigue editable.
3. Gestión de citas móvil: ayuda redundante y explicación de scroll demasiado larga; se acortaron sin ocultar columnas ni modificar el texto original del Administrador.

ROUND 2

Seen in one second: el trabajo de recepción y la siguiente acción están identificados.

Idea (one sentence): un mismo recorrido conserva al paciente desde la búsqueda hasta la acreditación o incidencia de su cita.

Live thing: formularios conectados, disponibilidad actualizada tras conflicto y feedback de envío.

Layout words by section: shell=marco claro / catálogo=tabla / cupos=grupos por médico / confirmación=resumen / incidencias=acción auditada.

Scores: design 8.0 · usability 8.3 · creativity 7.0 · content 8.5 → overall 7.9.

Caps applied: no topes específicos de landing. Los fixtures y el servidor simulado no acreditan integración clínica real.

Top three problems (most visible first), each with the screenshot it shows in and the fix:
1. Tablas móviles: requieren desplazamiento horizontal para conservar datos; se mantiene región etiquetada y acceso a detalle. Una lista móvil completa sería una mejora futura, no se ocultan columnas.
2. Formularios breves en escritorio: el marco conserva espacio libre; se mantiene un ancho legible y no se llena con decoración o datos ficticios.
3. Recepción QR: la interfaz necesita el contenido presentado por el paciente; entrega/impresión siguen dependiendo del flujo autorizado pendiente.

Evaluación propia, no una puntuación automática ni una certificación externa.


## Evidencia final del inspector

Cada fila incluye 1440, 768 y 390 px: **21 pantallas/estados, 63 mediciones, 0 FAIL y 10 WARN**. Se abrieron las capturas de las tres anchuras y se revisaron también menú, confirmación y resultados HTTP.

| Pantalla / estado | FAIL | WARN |
| --- | ---: | ---: |
| Dashboard | 0 | 0 |
| Pacientes | 0 | 1 |
| Perfil de paciente | 0 | 0 |
| Disponibilidad | 0 | 0 |
| Confirmación de reserva | 0 | 1 |
| Gestión de citas | 0 | 0 |
| Detalle de cita | 0 | 1 |
| Check-in | 0 | 1 |
| Asistencia | 0 | 1 |
| Acreditación | 0 | 1 |
| Cancelación | 0 | 0 |
| Inasistencia | 0 | 0 |
| Historial | 0 | 0 |
| QR inválido | 0 | 0 |
| Reserva con conflicto | 0 | 0 |
| Check-in exitoso | 0 | 1 |
| Historial vacío | 0 | 1 |
| Paciente restringido | 0 | 1 |
| Sin turnos | 0 | 0 |
| Servicio caído | 0 | 1 |
| Administración, regresión | 0 | 0 |

Los diez avisos son `hero-void` en escritorio: espacio libre del marco compartido en formularios breves, resultados pequeños o estados sin datos. Se mantienen el ancho legible y los estados vacíos útiles; no se agregaron adornos o estadísticas para llenar ese espacio. No se registraron avisos de consola, fuentes ausentes o desbordamiento horizontal de página en la comprobación final.

## Comandos de validación

Desde `frontend_clinica`:

```powershell
php artisan route:list --path=secretaria/
php artisan test --compact
npm.cmd run build
php artisan view:cache
```

Pint se ejecutó sobre los archivos PHP creados/modificados de aplicación, rutas y pruebas. El XML final de la suite está en `storage/logs/fase11-suite.xml`; los reportes privados de navegador distinguen explícitamente fixtures y upstream simulado de Quarkus real.

## Inventario exacto de la Fase 11

**9 archivos existentes modificados** (rutas relativas a la raíz del repositorio):

```text
frontend_clinica/app/Exceptions/QuarkusApiException.php
frontend_clinica/app/Services/QuarkusApiClient.php
frontend_clinica/resources/css/app.css
frontend_clinica/resources/js/app.js
frontend_clinica/resources/views/components/admin/alerts.blade.php
frontend_clinica/resources/views/components/admin/icon.blade.php
frontend_clinica/resources/views/components/admin/table.blade.php
frontend_clinica/resources/views/layouts/administracion.blade.php
frontend_clinica/routes/web.php
```

**43 archivos nuevos**, sin staging:

```text
frontend_clinica/app/Http/Controllers/Secretaria/CheckinController.php
frontend_clinica/app/Http/Controllers/Secretaria/CitaController.php
frontend_clinica/app/Http/Controllers/Secretaria/DashboardController.php
frontend_clinica/app/Http/Controllers/Secretaria/HistorialController.php
frontend_clinica/app/Http/Controllers/Secretaria/PacienteController.php
frontend_clinica/app/Http/Controllers/Secretaria/ReceptionController.php
frontend_clinica/app/Http/Controllers/Secretaria/ReservaController.php
frontend_clinica/app/Http/Middleware/ReceptionResponses.php
frontend_clinica/app/Http/Requests/Secretaria/AppointmentActionRequest.php
frontend_clinica/app/Http/Requests/Secretaria/AppointmentFiltersRequest.php
frontend_clinica/app/Http/Requests/Secretaria/CheckinRequest.php
frontend_clinica/app/Http/Requests/Secretaria/PatientSearchRequest.php
frontend_clinica/app/Http/Requests/Secretaria/ReceptionRequest.php
frontend_clinica/app/Http/Requests/Secretaria/ReservationBrowseRequest.php
frontend_clinica/app/Http/Requests/Secretaria/ReservationRequest.php
frontend_clinica/app/Support/AppointmentPresentation.php
frontend_clinica/docs/fase11-secretaria.md
frontend_clinica/resources/css/secretaria.css
frontend_clinica/resources/js/secretaria.js
frontend_clinica/resources/views/components/appointment-status.blade.php
frontend_clinica/resources/views/components/appointment-summary.blade.php
frontend_clinica/resources/views/components/appointments-table.blade.php
frontend_clinica/resources/views/components/patient-results.blade.php
frontend_clinica/resources/views/components/workspace-brand.blade.php
frontend_clinica/resources/views/components/workspace.blade.php
frontend_clinica/resources/views/layouts/secretaria.blade.php
frontend_clinica/resources/views/secretaria/checkin/accreditation.blade.php
frontend_clinica/resources/views/secretaria/checkin/attendance.blade.php
frontend_clinica/resources/views/secretaria/checkin/index.blade.php
frontend_clinica/resources/views/secretaria/citas/action.blade.php
frontend_clinica/resources/views/secretaria/citas/index.blade.php
frontend_clinica/resources/views/secretaria/citas/show.blade.php
frontend_clinica/resources/views/secretaria/dashboard.blade.php
frontend_clinica/resources/views/secretaria/error.blade.php
frontend_clinica/resources/views/secretaria/historial/show.blade.php
frontend_clinica/resources/views/secretaria/pacientes/index.blade.php
frontend_clinica/resources/views/secretaria/pacientes/show.blade.php
frontend_clinica/resources/views/secretaria/partials/appointment-filters.blade.php
frontend_clinica/resources/views/secretaria/partials/navigation.blade.php
frontend_clinica/resources/views/secretaria/reservas/confirm.blade.php
frontend_clinica/resources/views/secretaria/reservas/create.blade.php
frontend_clinica/tests/Feature/SecretariaPanelTest.php
frontend_clinica/tests/Support/ReceptionFixtures.php
```

El informe no rastreado `frontend_clinica/docs/fase10-administracion.md` ya existía al iniciar esta fase y queda fuera de este inventario. Git conserva las seis eliminaciones documentales y el PDF del usuario. Las capturas, reportes y herramientas temporales permanecen en storage ignorado. No hay cambios staged.

