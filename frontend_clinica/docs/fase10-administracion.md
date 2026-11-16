# Fase 10 — Panel de administración en Blade

Fecha de verificación: 12 de noviembre de 2026. Alcance: exclusivamente ROL004, sobre el Laravel existente. Sin cambios de backend, migraciones, secretos, dependencias ni autenticación.

## Estado de entrega

Implementados los módulos y sus conexiones mediante el cliente HTTP real de Laravel. Hay 41 rutas administrativas protegidas, 27 variantes de pantalla comprobadas por Feature y 13 componentes Blade compartidos.

**Pendiente de aceptación con infraestructura real:** Quarkus no estaba accesible en `127.0.0.1:8080` ni `127.0.0.1:18090`. El clúster y el fixture aislados de Fase 9 fueron retirados al cerrar aquella fase. No se arrancó el backend contra una base desconocida, no se modificaron credenciales y no se utilizó PostgreSQL desde Laravel para suplir la API. Las pruebas con `Http::fake` y las capturas de respuestas Blade no se presentan como E2E Laravel–Quarkus–PostgreSQL.

## A. Diagnóstico inicial

- Laravel 13 existente, PHP 8.5.10, Composer 2.10.3, Node 22.21.0, Vite 8.3.5 y Tailwind 4. Dependencias ya instaladas. Composer declara PHP `^8.3`; se utilizó el PHP 8.5 disponible, compatible con las dependencias instaladas.
- `routes/web.php` ofrecía login, registro, OTP, Google, recuperación y cuatro inicios por rol. El administrador utilizaba la vista genérica `dashboard/home.blade.php`.
- `CheckRolSession` exige sesión local y autorización remota en cada petición; actualiza el rol desde Quarkus. Un 401 invalida la sesión y un problema temporal del verificador devuelve 503 sin descartarla.
- `QuarkusApiClient` ya enviaba Bearer desde la sesión y centralizaba URL, timeout y serialización booleana. Se conserva ese cliente y sus métodos anteriores.
- Los controladores clínicos heredados estaban vacíos. No se reconstruyeron módulos backend. Las clases nuevas están bajo `Controllers/Administracion` para evitar duplicarlos o modificar los futuros módulos de Secretaría.
- Se reutilizaron `layouts/app`, el skip link, Vite, la fuente Instrument Sans alojada en los assets compilados, las variables CSS de identidad y los mecanismos de sesión existentes.
- Resultado inicial de Laravel: **69 casos, 52 aprobados, 17 omitidos y 270 aserciones**.
- El inventario `backend_clinica/docs/inventario-api-fase9.md` se contrastó con controladores, DTO y servicios Java de personal, especialidades, agenda y sanciones. Los contratos administrativos devuelven JSON directo; autenticación usa `data`.

## B. Skills y herramientas ejecutadas

Se inspeccionaron `.agents/skills`, los SKILL.md y la presencia de scripts y referencias. Se leyeron las instrucciones de `ui-ux-pro-max`, `design-system`, `design-taste-frontend` y `ui-styling`; también la referencia completa de app screens y las referencias de tokens, componentes y estados. No se reinstalaron ni modificaron skills.

| Skill / herramienta | Ejecución y decisión |
| --- | --- |
| ui-ux-pro-max / `search.py` | Diseño healthcare/admin con variance 3, motion 2 y density 7; consulta Laravel; búsquedas UX de tablas, navegación, errores, foco y responsive. |
| Reintento Laravel | El primer resultado proponía Inertia. La búsqueda específica de Blade devolvió CSRF, method spoofing y conservación de valores. Se aplicó esta segunda respuesta. |
| Limitación de búsqueda | El generador de sistema seguía proponiendo patrones de landing incluso al estrechar el brief: esos patrones no se persistieron ni aplicaron. Para la composición se usó la modalidad app de tasteskill. La búsqueda concreta de agrupación con fieldset no obtuvo coincidencia verificada; se aplicó la guía general del SKILL.md para agrupar campos con `fieldset` y `legend`. |
| design-system | Arquitectura de tres capas, especificaciones de componentes y estados. `validate-tokens.cjs` pasó en vistas administrativas y componentes. |
| design-taste-frontend / `draw.mjs` | Se consultaron las opciones y los tipos reales; `--app --kind smb-admin` ofreció Accent ladder y Framed sheet. Se eligió **Framed sheet, seed 893080048**, conservando paleta y tipografía. Sin redibujos. |
| `lint.mjs --app` | HTML renderizado: **0 FAIL, 0 WARN**. CSS/JS: 0 FAIL; avisos por azul solicitado y sombra del diálogo, ambos justificados. El linter no reconoce directamente `.blade.php`, por lo que también se ejecutó sobre sus respuestas HTML. |
| `inspect.mjs --app` | Chrome real, assets Vite de producción, anchuras 1440, 768 y 390. Informes y capturas locales en `storage/app/private/fase10/visual`. |
| ui-styling | Solo patrones compatibles de formularios, foco y responsive. No se instaló shadcn, Radix, React ni una biblioteca adicional. |

Los alias `python` y `py` no funcionaban, pero se encontró y utilizó Python 3.14.4 en `C:/Users/LENOVO/AppData/Local/Python/pythoncore-3.14-64/python.exe`. Chrome estaba instalado; el primer intento aislado agotó la conexión CDP. Las inspecciones posteriores se ejecutaron con autorización fuera del aislamiento. No se cambió la instalación global.

`frontend_clinica/AGENTS.md` pide instalar/configurar Boost; ya figura instalado en Composer. No se ejecutó `boost:install` porque esta tarea prohíbe modificar configuraciones de IA. Tampoco se cambiaron Composer ni sus versiones.

## C. Sistema de diseño

Se amplía el mismo entrypoint `resources/css/app.css` con `administracion.css`. No hay un segundo framework ni un bundle alternativo de componentes.

| Rol | Valor |
| --- | --- |
| Marco y fondo | `#F8FAFC` |
| Hoja y campos | `#FFFFFF` |
| Texto | `#172B4D` |
| Identidad | `#16324F` |
| Texto secundario | `#526176`, oscurecido respecto a la propuesta inicial para favorecer contraste |
| Acción / hover / foco | `#2563EB` / `#1D4ED8` |
| Bordes de superficie / controles | `#E2E8F0` / `#8492A6` |
| Confirmación positiva | `#15803D` sobre `#EFFAF3` |
| Advertencia | `#92400E` sobre `#FFFBEB` |
| Error | `#B91C1C` sobre `#FEF2F2` |

La arquitectura es primitiva → semántica → componente; los componentes no introducen colores hexadecimales propios. Se conservan aliases existentes como `--ink`, `--muted`, `--surface` y `--font`.

- Una familia: Instrument Sans, pesos 400/500/600, comprobada en Chrome.
- Escala funcional: 13 px para notas, 15 px para contenido, 16 px para campos y secciones, 24 px para títulos y 28 px para cifras. Títulos de móvil a 22 px.
- Espaciado basado en 4 px; separaciones habituales de 16/24 px. Radios de 6 px en controles, 8 px en paneles y 16 px en la hoja.
- Controles de al menos 44 px. Foco visible de 3 px. Sombra reservada a diálogos; paneles sin sombra.
- Escritorio: sidebar de 232 px, encabezado y hoja blanca. Bajo 1101 px: menú modal nativo, con Escape y devolución de foco. Bajo 640 px: formularios de una columna y métricas como lista.
- Las tablas conservan sus columnas y se desplazan dentro de una región etiquetada y enfocable. La página y los filtros no se ensanchan para acomodarlas.
- Sin gráficas, porcentajes, actividad reciente o cifras comerciales inventadas. Sin animaciones de entrada; solo feedback y respeto a movimiento reducido.

La identidad propia es la semana clínica de lunes a domingo y el recorrido personal → agenda → bloqueo → citas afectadas. La navegación se mantiene discreta; el azul destaca la acción principal.

## D. Estructura Blade

```text
resources/views/
  layouts/administracion.blade.php
  components/admin/
    alerts, badge, button, confirmation, empty, field, form,
    heading, icon, notice, pagination, state, table
  administracion/
    dashboard.blade.php
    error.blade.php
    partials/{brand,navigation,doctor-selector}.blade.php
    personal/{index,form,show,password}.blade.php
    especialidades/{index,form,show}.blade.php
    agendas/{index,form}.blade.php
    bloqueos/{index,form,show}.blade.php
    pacientes/{index,show,incidencias}.blade.php
    sanciones/{form,show}.blade.php
```

Son 22 vistas y parciales en `administracion`, 13 componentes y un layout. Médicos y secretarios comparten vistas y componentes con campos y capacidades específicos.

## E. Controladores y rutas

Controladores concretos: `DashboardController`, `MedicoController`, `SecretariaController`, `EspecialidadController`, `AgendaController`, `BloqueoController`, `PacienteController` y `SancionController`. Se apoyan en `AdminController` y el controlador abstracto de personal.

Los 41 nombres están bajo `administracion.*`. Todas las rutas pertenecen al grupo con `AdministrativeResponses` y el middleware existente `rol:ROL004`. Se mantiene `/administracion/inicio` y el nombre `administracion.home`.

Form Requests: personal, especialidad, horario, duración, bloqueo, sanción, estado y contraseña, con una base compartida para autorización, atributos y mensajes. El cambio de estado y las operaciones sensibles requieren confirmación explícita. No hay rutas DELETE.

## F–G. Pantallas, operaciones y endpoints

Todos los endpoints de esta tabla llevan el prefijo `/api/v1`. **Conexión implementada a la API real configurable; ejecución de esta fase probada con Http::fake, pendiente de E2E real por indisponibilidad de Quarkus.** Ninguna pantalla de producción contiene fixtures.

| Pantalla / rutas Laravel | Funciones y endpoints Quarkus | Pruebas |
| --- | --- | --- |
| Panel `/administracion/inicio` | Tres catálogos con `estado=Activo`: GET `/personal/medicos`, `/personal/secretarias`, `/personal/especialidades`; accesos a todos los módulos. | Renderizado, identidad de sesión, cero real, fallo de catálogo, autorización. |
| Médicos `/administracion/medicos`, `/crear`, `/{codigo}`, `/{codigo}/editar` | GET lista/detalle, POST alta, PUT edición y `/{codigo}/estado` en `/personal/medicos`. Cambio opcional de contraseña mediante el PUT de edición existente. | Alta, edición, contraseña opcional, estado, filtros, vínculos inactivos conservables, mensajes y payload permitido. |
| Secretarios `/administracion/secretarias`, `/crear`, `/{codigo}`, `/{codigo}/editar`, `/{codigo}/password` | GET/POST/PUT de `/personal/secretarias`; PUT estado y contraseña. | Listado, alta, edición, estado y éxito de contraseña con **204 sin cuerpo**. |
| Especialidades `/administracion/especialidades`, `/crear`, `/{codigo}`, `/{codigo}/editar` | GET/POST/PUT `/personal/especialidades`; PUT estado; GET `/{codigo}/medicos`. El catálogo solicita `estado=Todos`; el selector médico usa GET sin filtro. | Listado, creación, edición solo de campos permitidos, estado, médicos vinculados y conflictos. |
| Agendas `/administracion/agendas?medico=…`; `/{medico}/horarios/crear`; `/{medico}/horarios/{horario}/editar` | GET/POST `/administracion/medicos/{codMed}/horarios`; PUT `/administracion/horarios/{codHor}` y `/estado`; PUT `/administracion/medicos/{codMed}/duracion`. | Siete días, descansos, crear/editar/estado, duración entera permitida, pertenencia al médico y conflicto 409. |
| Bloqueos `/administracion/bloqueos?medico=…`; `/medicos/{medico}/crear`; `/{bloqueo}` | GET/POST `/administracion/medicos/{codMed}/bloqueos`; GET `/administracion/bloqueos/{codBlo}` y `/citas-afectadas`. | Consulta, fecha única, jornada completa, intervalo parcial y ausencia de reconstrucción cuando la trazabilidad es incompleta. |
| Pacientes `/administracion/pacientes`, `/{paciente}` | GET `/administracion/pacientes` con `page,size=20,q,estado`; GET detalle, incidencias y sanciones del paciente. | Paginación real, filtros, datos administrativos y estado de reserva informado por backend. |
| Sanciones `/administracion/sanciones`; `/pacientes/{paciente}/sanciones/crear`; `/…/{sancion}` | El índice ayuda a localizar al paciente; no inventa un catálogo global. GET incidencias/sanciones/detalle y POST `/administracion/pacientes/{codPac}/sanciones` con `{motivo,dias}`. | Historiales paginados independientes, `vigente=false` literal, aplicar sanción, días 1–365 y rechazo de duplicada. |

Los catálogos de personal y especialidades no están paginados en el contrato actual. Se filtra el estado en Quarkus y se busca sobre el catálogo devuelto, sin aparentar una búsqueda remota inexistente. El dashboard hace una petición por catálogo; no recorre páginas para fabricar totales. Si una consulta temporal falla, informa que el indicador no está disponible.

Las especialidades inactivas no se ofrecen para nuevos vínculos; en edición puede conservarse un vínculo activo ya existente, según el servicio real. La duración de especialidad no es editable por el PUT del catálogo. Cambiar la duración general del médico **también alinea los vínculos activos**, como hace Quarkus; la interfaz advierte ese efecto y los conflictos con citas vigentes.

Los bloqueos no reprograman automáticamente. La habilitación del paciente y la vigencia de sanciones se toman de los DTO, sin deducirlas con el reloj del navegador. No se muestra historia clínica y no existe levantamiento anticipado de sanciones.

## H. Componentes compartidos

`button` ofrece variantes primaria, secundaria, texto y peligro. `field` cubre texto, correo, contraseña, fecha, hora, número, select y textarea, con label, obligatoriedad, ayuda, old input y error asociado. Se comparten formulario CSRF/method spoofing, confirmación, diálogo nativo, tabla, paginación basada en `hayMas`, encabezado, badges, avisos, resumen de errores y estado vacío.

El módulo JS proporciona menú accesible, confirmación con Escape, foco, estado de envío, bloqueo de doble envío y recuperación al volver mediante bfcache. La confirmación por checkbox sigue siendo exigida en servidor para estados, contraseña de secretario, duración, bloqueo y sanción.

## I. Errores y seguridad

- Bearer siempre en sesión del servidor; las vistas no reciben el token. Sin localStorage ni llamadas clínicas directas desde el navegador.
- `QuarkusApiClient::administrative` conserva los métodos existentes y añade interpretación común de errores, fallo de conexión, JSON y 204. No reintenta escrituras.
- `QuarkusApiException` usa el renderizador de Laravel y `ShouldntReport`; evita registrar como excepciones inesperadas los rechazos administrativos. Las respuestas 400/409/422 pueden mostrar el mensaje de negocio; los 5xx no exponen el cuerpo técnico.
- Los errores 401 posteriores a validar el rol también invalidan la sesión. 403, 404 y 423 muestran una pantalla recuperable. Un fallo temporal conserva la sesión; el middleware de rol debe validarla otra vez antes de permitir acciones.
- Rechazos de formulario conservan datos seguros y especialidades, excluyendo contraseña, confirmación y CSRF. Las contraseñas reflejadas por una respuesta errónea se redactan antes de crear mensajes.
- Las contraseñas nunca se precargan, aparecen en URLs ni se incorporan a mensajes de éxito. El backend sigue asignando ROL002/ROL003 y el actor de auditoría.
- Los controladores envían exclusivamente campos validados: no propagan roles, administrador ni actor recibidos del formulario.
- Respuestas administrativas con `Cache-Control: no-store, private`, `Pragma: no-cache` y política de referencia same-origin.
- CSRF permanece activo. La prueba funcional fuerza su comprobación real; el servidor HTTP iniciado también rechazó un POST sin token con 419.
- Contraseñas: reglas diferentes para médicos y secretarios según sus servicios, confirmación y límite de 72 bytes; el backend mantiene la decisión definitiva.

## J. Resultados automatizados

| Verificación | Resultado |
| --- | --- |
| Nueva batería `AdministracionPanelTest` | **87 aprobadas, 632 aserciones, 0 fallos/errores/omisiones** |
| Suite completa final | **156 casos: 139 aprobados, 17 omitidos, 902 aserciones, 0 fallos/errores** |
| Diferencia respecto al inicio | 87 casos aprobados adicionales; los 52 casos anteriores siguen aprobados |
| Omisiones | 5 pruebas demo opt-in, 7 de integración backend y 5 del stack HTTP real, sin infraestructura privada activada |
| Laravel Pint | Aprobado sobre todos los archivos PHP creados/modificados |
| `php artisan route:list --path=administracion` | 41 rutas |
| `php artisan view:cache` | Aprobado; además se renderizaron las 27 variantes mediante peticiones Feature |
| Laravel HTTP iniciado en loopback | GET login 200; inicio administrativo sin sesión 302; POST administrativo sin CSRF 419 |

Evidencias no versionadas: `storage/logs/fase10-admin.xml`, `fase10-suite.xml`, logs de inspección y artefactos privados. El servidor Laravel temporal se detuvo al acabar la comprobación HTTP.

La prueba de seguimiento de errores conserva explícitamente la cookie entre peticiones para reproducir el comportamiento real de las sesiones JSON cifradas. No se cambió su configuración de seguridad para acomodar las pruebas.

## K. Compilación

`npm.cmd run build` aprobado con Vite 8.3.5. Se utilizó `npm.cmd` porque PowerShell impide ejecutar su alias `npm.ps1`; no se cambió la política de ejecución.

El aviso preexistente de `fontaine` opcional continúa: afecta al fallback optimizado de fuentes y no impide compilar ni cargar Instrument Sans. No se añadió una dependencia para silenciarlo.

## L–M. Revisión visual y capturas

Se generaron respuestas HTML desde las **rutas protegidas de Laravel** con sesión y autorización simuladas únicamente en las pruebas; se abrieron en Chrome con CSS, JS y fuentes del build de producción. No son maquetas separadas ni un modo demo incorporado al producto.

La revisión automatizada complementaria comprobó **96 combinaciones: 27 vistas × 1440/768/390 px y cinco estados adicionales × las mismas tres anchuras**, con 0 fallos: título, etiquetas, CSRF, fuente cargada, ausencia de contraseñas precargadas o Bearer, ausencia de desbordamiento global y de contenido recortado fuera de las regiones de tabla, sidebar adaptable y consola sin excepciones JS.

Se probaron apertura del menú, foco, Escape, confirmación de sanción, un único envío aceptado ante solicitudes repetidas y disposición horizontal **844×390 con movimiento reducido**. La petición real de sanción en este chequeo de navegador se interceptó para no confundirlo con una escritura E2E; las escrituras están comprobadas por Feature contra los contratos.

El inspector de la skill se ejecutó para doce vistas representativas y cinco estados adicionales a las tres anchuras. Todos tienen **0 FAIL**. Se abrieron las capturas para revisar la composición y los estados; los informes están en `visual/<pantalla>/report.json`.

| Captura / pantalla | FAIL | WARN |
| --- | --- | --- |
| dashboard | 0 | 0 |
| medicos | 0 | 1 |
| medico-alta | 0 | 0 |
| secretarios | 0 | 1 |
| especialidades | 0 | 1 |
| agendas | 0 | 0 |
| bloqueos | 0 | 1 |
| bloqueo-detalle | 0 | 1 |
| pacientes | 0 | 1 |
| paciente-detalle | 0 | 0 |
| sancion-alta | 0 | 0 |
| password | 0 | 1 |
| medico-validacion | 0 | 0 |
| horario-conflicto | 0 | 0 |
| medicos-vacio | 0 | 1 |
| servicio-indisponible | 0 | 1 |
| secretario-exito | 0 | 0 |

Los nueve avisos son `hero-void`: espacio inferior en catálogos cortos, estado vacío, contraseña o indisponibilidad. Se conserva el marco de trabajo de altura estable y la información necesaria para cada tarea; no se añaden filas ficticias ni decoración para llenar el espacio. El linter permite justificar el azul solicitado y la sombra de un diálogo que realmente flota. La referencia reconstruida `before` también tiene 0 FAIL y un aviso de espacio inferior; no se cuenta entre las diecisiete vistas finales.

Capturas locales:

- `storage/app/private/fase10/visual/<pantalla>/{desktop,tablet,mobile}-fold.jpg`
- `storage/app/private/fase10/visual/<pantalla>/{desktop,tablet,mobile}-page-01.jpg`
- `storage/app/private/fase10/menu-open.jpg`
- `storage/app/private/fase10/confirmation-open.jpg`
- `storage/app/private/fase10/browser-qa.json`
- `storage/app/private/fase10/browser-states-qa.json`

La referencia `before` se reconstruyó desde la vista genérica original, que sigue intacta; no se presenta como captura tomada antes de empezar a programar. Se compara su cuenta y aviso de módulos pendientes con los flujos administrativos ahora disponibles. No se tomaron capturas de pacientes reales.

No se usaron fotografías, clips ni datos de prueba en las vistas de producción. Las personas y cifras de las capturas pertenecen exclusivamente a `tests/Support/AdministrativeFixtures.php`; no hay contenido empresarial ficticio pendiente de sustituir en el producto.

## N. Pendientes y límites

1. Ejecutar la aceptación E2E con Quarkus y una base aislada autorizada: altas, edición, estados, 204 de contraseña, conflictos con citas reales del fixture, trazabilidad y sanciones. La comprobación local actual no demuestra persistencia ni transacciones PostgreSQL.
2. Probar con volumen realista de catálogos; hoy la API de personal no ofrece paginación ni búsqueda remota. No se cambió ese contrato.
3. La revisión con lector de pantalla y dispositivos físicos sigue pendiente; se comprobó semántica, teclado y viewports en Chrome.
4. Levantamiento anticipado de sanciones y reprogramación automática no se implementan porque carecen de la política/endpoint exigidos.

## O–P. Archivos y Git

Archivos modificados existentes: `app/Services/QuarkusApiClient.php`, `routes/web.php`, `resources/css/app.css` y `resources/js/app.js`.

Archivos nuevos: excepción, middleware administrativo, diez clases de controladores (ocho concretas y dos compartidas), nueve Form Requests, CSS/JS administrativo, layout, 22 vistas/parciales, 13 componentes, prueba Feature, fixtures y este informe. El listado exacto se incluye al final.

Los artefactos de diseño, herramientas temporales de revisión, capturas y resultados están en storage ignorado; no se incorporan herramientas de IA al repositorio. Sin modificaciones en `backend_clinica`, `.agents`, `.codex`, secretos, lockfiles ni documentos universitarios.

Se conservaron las seis eliminaciones anteriores del usuario y `EVALUACION PROCESUAL.pdf` no rastreado. No se crearon ramas, no se hizo staging, commit ni push.

## Q. Preparación de Fase 11

Reutilizar la sesión y el cliente HTTP, pero crear rutas y permisos ROL002 propios. Implementar búsqueda acotada de pacientes, cupos y reserva presencial con el `codSlot` recibido; QR/check-in, cancelación, inasistencia e historial según el inventario. Mantener tokens QR fuera de URLs y logs. Validar la política pendiente de reprogramación y registro presencial antes de ofrecer esas acciones. **La Fase 11 no se ha iniciado.**

## Crítica visual registrada

ROUND 1

Seen in one second: panel clínico con acceso al personal y a la agenda.

Idea (one sentence): la semana de atención y la trazabilidad del bloqueo organizan el trabajo administrativo.

Live thing: formularios Blade y filtros que envían operaciones a Laravel; sin animación de entrada.

Layout words by section: shell=marco claro / resumen=franja dividida / operación=lista / edición=formulario agrupado.

Scores: design 7.5 · usability 7.0 · creativity 6.5 · content 8.0 → overall 7.2.

Caps applied: las exigencias de fotografías, hero y movimiento de landing no corresponden a app screens.

Top three problems (most visible first), each with the screenshot it shows in and the fix:
1. `dashboard/desktop-fold.jpg`: el botón de menú aparecía junto al sidebar; se reforzó la regla de visibilidad por breakpoint.
2. `paciente-detalle/mobile-page-01.jpg`: la anchura intrínseca de la tabla ensanchaba filtros del historial; se usaron tracks `minmax(0,1fr)` y contenedores de tabla encogibles.
3. Detalles de tabla en móvil: se ampliaron las áreas de acción y se reforzó la navegación del resumen de errores hacia los campos.

ROUND 2

Seen in one second: acción principal y datos administrativos con una misma jerarquía.

Idea (one sentence): la semana clínica enlaza al profesional con sus intervalos, ausencias y citas afectadas.

Live thing: selección de médico, configuración semanal y confirmaciones accesibles.

Layout words by section: shell=hoja / catálogo=tabla / semana=filas por día / perfil=datos e historiales.

Scores: design 8.0 · usability 8.0 · creativity 7.0 · content 8.5 → overall 7.85.

Caps applied: sin topes específicos de landing; la aceptación E2E y el lector de pantalla no están demostrados por las imágenes.

Top three problems (most visible first), each with the screenshot it shows in and the fix:
1. Catálogos cortos: queda espacio inferior con el fixture de una fila; se conserva el marco y se documenta el aviso sin inventar registros.
2. Historiales en móvil: se mantiene desplazamiento horizontal explícito para conservar todos los campos; no es equivalente a una tabla completamente visible de una vez.
3. Agendas extensas: la lista de siete días requiere desplazamiento; se priorizó integridad y controles legibles sobre una cuadrícula comprimida.

Las puntuaciones son una evaluación propia de diseño, no una medición automática ni un premio externo.

## Inventario exacto de archivos

Estado final: 4 archivos existentes modificados y 62 archivos nuevos dentro de frontend_clinica.

Modificados:

```text
frontend_clinica/app/Services/QuarkusApiClient.php
frontend_clinica/resources/css/app.css
frontend_clinica/resources/js/app.js
frontend_clinica/routes/web.php
```

Nuevos:

```text
frontend_clinica/app/Exceptions/QuarkusApiException.php
frontend_clinica/app/Http/Controllers/Administracion/AdminController.php
frontend_clinica/app/Http/Controllers/Administracion/AgendaController.php
frontend_clinica/app/Http/Controllers/Administracion/BloqueoController.php
frontend_clinica/app/Http/Controllers/Administracion/DashboardController.php
frontend_clinica/app/Http/Controllers/Administracion/EspecialidadController.php
frontend_clinica/app/Http/Controllers/Administracion/MedicoController.php
frontend_clinica/app/Http/Controllers/Administracion/PacienteController.php
frontend_clinica/app/Http/Controllers/Administracion/PersonalController.php
frontend_clinica/app/Http/Controllers/Administracion/SancionController.php
frontend_clinica/app/Http/Controllers/Administracion/SecretariaController.php
frontend_clinica/app/Http/Middleware/AdministrativeResponses.php
frontend_clinica/app/Http/Requests/Administracion/AdminRequest.php
frontend_clinica/app/Http/Requests/Administracion/BloqueoRequest.php
frontend_clinica/app/Http/Requests/Administracion/DuracionRequest.php
frontend_clinica/app/Http/Requests/Administracion/EspecialidadRequest.php
frontend_clinica/app/Http/Requests/Administracion/EstadoRequest.php
frontend_clinica/app/Http/Requests/Administracion/HorarioRequest.php
frontend_clinica/app/Http/Requests/Administracion/PasswordRequest.php
frontend_clinica/app/Http/Requests/Administracion/PersonalRequest.php
frontend_clinica/app/Http/Requests/Administracion/SancionRequest.php
frontend_clinica/docs/fase10-administracion.md
frontend_clinica/resources/css/administracion.css
frontend_clinica/resources/js/administracion.js
frontend_clinica/resources/views/administracion/agendas/form.blade.php
frontend_clinica/resources/views/administracion/agendas/index.blade.php
frontend_clinica/resources/views/administracion/bloqueos/form.blade.php
frontend_clinica/resources/views/administracion/bloqueos/index.blade.php
frontend_clinica/resources/views/administracion/bloqueos/show.blade.php
frontend_clinica/resources/views/administracion/dashboard.blade.php
frontend_clinica/resources/views/administracion/error.blade.php
frontend_clinica/resources/views/administracion/especialidades/form.blade.php
frontend_clinica/resources/views/administracion/especialidades/index.blade.php
frontend_clinica/resources/views/administracion/especialidades/show.blade.php
frontend_clinica/resources/views/administracion/pacientes/incidencias.blade.php
frontend_clinica/resources/views/administracion/pacientes/index.blade.php
frontend_clinica/resources/views/administracion/pacientes/show.blade.php
frontend_clinica/resources/views/administracion/partials/brand.blade.php
frontend_clinica/resources/views/administracion/partials/doctor-selector.blade.php
frontend_clinica/resources/views/administracion/partials/navigation.blade.php
frontend_clinica/resources/views/administracion/personal/form.blade.php
frontend_clinica/resources/views/administracion/personal/index.blade.php
frontend_clinica/resources/views/administracion/personal/password.blade.php
frontend_clinica/resources/views/administracion/personal/show.blade.php
frontend_clinica/resources/views/administracion/sanciones/form.blade.php
frontend_clinica/resources/views/administracion/sanciones/show.blade.php
frontend_clinica/resources/views/components/admin/alerts.blade.php
frontend_clinica/resources/views/components/admin/badge.blade.php
frontend_clinica/resources/views/components/admin/button.blade.php
frontend_clinica/resources/views/components/admin/confirmation.blade.php
frontend_clinica/resources/views/components/admin/empty.blade.php
frontend_clinica/resources/views/components/admin/field.blade.php
frontend_clinica/resources/views/components/admin/form.blade.php
frontend_clinica/resources/views/components/admin/heading.blade.php
frontend_clinica/resources/views/components/admin/icon.blade.php
frontend_clinica/resources/views/components/admin/notice.blade.php
frontend_clinica/resources/views/components/admin/pagination.blade.php
frontend_clinica/resources/views/components/admin/state.blade.php
frontend_clinica/resources/views/components/admin/table.blade.php
frontend_clinica/resources/views/layouts/administracion.blade.php
frontend_clinica/tests/Feature/AdministracionPanelTest.php
frontend_clinica/tests/Support/AdministrativeFixtures.php
```

## Reproducción de verificaciones

Desde frontend_clinica, con las dependencias ya instaladas:

```powershell
php artisan route:list --path=administracion
php artisan test --compact
npm.cmd run build
php vendor/bin/pint --test app/Exceptions/QuarkusApiException.php app/Http/Controllers/Administracion app/Http/Middleware/AdministrativeResponses.php app/Http/Requests/Administracion app/Services/QuarkusApiClient.php routes/web.php tests/Feature/AdministracionPanelTest.php tests/Support/AdministrativeFixtures.php
```

La exportación visual es opt-in mediante CLINICA_ADMIN_SNAPSHOTS=1 al ejecutar AdministracionPanelTest. Sus respuestas y capturas quedan en storage privado ignorado; no habilita acceso de demostración ni altera las rutas de producción.
