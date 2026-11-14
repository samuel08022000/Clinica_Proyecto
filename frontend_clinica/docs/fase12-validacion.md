> Documento histórico de la fase indicada. En Fase 13 se retiraron /hello, los generadores y el test de credenciales demo; sus resultados anteriores se conservan como evidencia. Para comandos vigentes consultar ../../docs/ejecucion-integracion.md y docs/fase13-auditoria.md desde la raíz.

# Fase 12 — Integración real y validación final

Ejecución: 12 de noviembre de 2026. Referencia inicial: `5bc814a`. Alcance: auditar los paneles existentes, probar la integración y corregir defectos comprobados. No se construyeron módulos nuevos ni se rediseñaron los paneles.

## 1. Diagnóstico inicial

Se revisaron Git, los commits recientes de Fases 9–11, Compose, ambos entornos sin imprimir valores privados, rutas, middlewares, QuarkusApiClient, controladores/DTO, migraciones y pruebas. Se leyeron los informes e inventario de Fase 9 y los informes de Fases 10 y 11.

Administración y Secretaría están implementadas y versionadas. Se conservan 41 rutas administrativas y 17 de Secretaría, los componentes compartidos, el sistema de tokens y la autorización remota. No se duplicó el login ni el cliente REST.

La principal brecha inicial era que Fase 11 había probado sus operaciones con dobles HTTP. Sus cifras no se asumieron como resultados nuevos. La nueva corrida inicial de Laravel reprodujo **242 aprobadas, 17 omitidas, 1598 aserciones** antes de las correcciones de esta fase.

`frontend_clinica/AGENTS.md` solicita instalar Boost. La dependencia ya existe y no se ejecutó su instalador: la instrucción del usuario prohíbe modificar configuraciones de IA. No se modificaron esas instrucciones, skills o secretos.

## 2. Git

HEAD inicial: `5bc814a Implementar panel de secretaria y gestion de recepcion`. También se inspeccionaron los commits de Administración e integración anterior.

Se conservaron las seis eliminaciones documentales preexistentes:

- `Analisis de Requerimientos Clinica.docx`
- `Documento_de_Requisitos_Sistema_Gestion_Citas_Medicas.docx`
- `EVALUACION CONTINUA.pdf`
- `HISTORIAS DE USUARIO CLINICA (1).xlsx`
- `PROYECTO CLINICA.docx`
- `diccionario de datos.docx`

También se preservaron el PDF no rastreado `EVALUACION PROCESUAL.pdf` y el informe no rastreado `frontend_clinica/docs/fase10-administracion.md`. No se restauraron documentos, crearon ramas, añadieron archivos al índice, hicieron commits o push.

## 3. Versiones verificadas

| Componente | Versión comprobada |
| --- | --- |
| Java / compilación | JDK 25.0.4.1; release 25 |
| Quarkus | 3.39.4 |
| Maven Wrapper | Distribución fijada 3.9.16; ejecución con caché local |
| PHP | 8.5.10 |
| Laravel | 13.33.0 |
| Composer | 2.10.3; `check-platform-reqs` aprobado |
| Node | 22.21.0 |
| Vite | 8.3.5 |
| PostgreSQL de prueba | 15.19, Debian, amd64 |
| Docker | Desktop 4.92.0; cliente/motor 29.8.0 |

## 4–7. Entorno real utilizado

Docker respondió fuera del sandbox; el primer acceso restringido había sido denegado por permisos del entorno. No fue necesario utilizar PostgreSQL portátil.

Se creó `compose.integration.yml`, proyecto independiente **clinica-fase12**, PostgreSQL 15 solo en `127.0.0.1:35441`, datos en **tmpfs**, sin montar volúmenes habituales. La contraseña es aleatoria y permanece en almacenamiento privado ignorado. El contenedor habitual `clinica_postgres_nuevo`, publicado en 5433, no se utilizó para pruebas ni migraciones.

Las bases nuevas separan suite backend, compatibilidad de Fase 9 y navegador. Los fixtures de Fase 12 exigen PostgreSQL 15, loopback/puerto reservados, nombre de base restringido, marca de propiedad y esquema vacío. Las repeticiones crearon otras bases temporales; no borraron las anteriores.

Quarkus real se ejecutó nativamente con Java 25, perfil integration y puerto 18090. Laravel real se ejecutó con PHP 8.5 en 18091, storage/cookies/clave propios y CSRF normal. Un segundo Laravel en 18092 apuntó a un puerto sin servicio para probar caída y recuperación. Las variables se pasaron solo a esos procesos, sin editar `.env`.

El scheduler se deshabilitó en el recorrido interactivo para controlar los fixtures. Sus funciones y concurrencia se probaron en la suite backend; no se presenta ese recorrido como una ejecución del cron de producción. OTP/recuperación real de Fase 9 usa el proveedor demo ya autorizado exclusivamente en desarrollo, con clave privada; no envía correo.

La revisión visual final utiliza otro Laravel en **modo producción**, debug=false, con los mismos componentes y assets finales. Así no se inyecta la instrumentación local de desarrollo. No se modificó la configuración de Boost ni de las skills.

El perfil `fullstack` del Compose habitual declara Java 25/PHP 8.5. Su arranque completo en contenedores es **NO EJECUTADO**; esta validación acredita PostgreSQL en Docker y Quarkus/Laravel nativos. No se atribuye una prueba de imagen PHP/Maven a la ejecución nativa.

## 8. Migraciones y persistencia

Flyway aplicó desde vacío **V1, V2, V4, V5, V6, V7, V8, V9 y R__verificacion_email_otp**: nueve entradas correctas. No existe V3 en el conjunto principal; la migración V3 de desarrollo queda fuera de `classpath:db/migration`. Se usaron baseline=false y out-of-order=false en el entorno aislado.

Las cinco pruebas de actualización incremental desde V4, V5, V6, V7 y V8 aprobaron, conservando los antecedentes de sus escenarios. No se modificaron migraciones históricas ni se añadió una nueva.

La auditoría de solo lectura de la base del navegador comprobó:

| Integridad | Resultado |
| --- | ---: |
| Migraciones exitosas / fallidas | 9 / 0 |
| Restricciones sin validar | 0 |
| Triggers deshabilitados | 0 |
| Citas con relaciones huérfanas | 0 |
| Slots con más de una reserva efectiva | 0 |
| QR almacenados inválidos o ajenos a cita/fecha | 0 |
| Vistas administrativas esperadas | 2 |
| Fecha clínica coincidente con America/La_Paz | Sí |
| Comprobaciones de persistencia/auditoría por flujo | **16 de 16** |

Estas 16 verificaciones incluyen datos editados y estados del personal, dos vínculos activos del médico, horario conservado tras conflictos, canal Ventanilla y secretario auténtico, cancelación/llegada/sanción con actor coincidente en bitácora, una sola incidencia de inasistencia, bloqueo y cita afectada, vigencia de sanción y unicidad tras conflictos de reserva.

La consulta reproducible está en `backend_clinica/src/test/resources/integration-fase12-integrity.sql`. No devuelve contraseñas, hashes, QR o datos clínicos. El helper fija UTF-8 en la tubería PowerShell: de otro modo las comparaciones de “Reprogramación” se alteraban en tránsito, aunque el estado persistido era correcto.

## 9. Autenticación y autorización

| Flujo | Clasificación y evidencia |
| --- | --- |
| Login y redirección de los cuatro roles | **APROBADO CON BACKEND REAL**. Navegador, Laravel y usuarios sintéticos persistidos. |
| Contraseña incorrecta, Bearer inválido y revocado | **APROBADO CON BACKEND REAL**. Rechazo real; la sesión revocada del navegador vuelve al login. |
| Logout y acceso posterior | **APROBADO CON BACKEND REAL**. Cierre local/remoto y rechazo de reentrada. |
| Regeneración / cookies | **APROBADO CON BACKEND REAL**. Se conserva la regeneración de AuthController; cambio de cookie, HttpOnly y SameSite=Lax comprobados. |
| CSRF | **APROBADO CON BACKEND REAL**. Formularios normales válidos; HTTP 419 al omitir token. |
| Acceso cruzado a paneles | **APROBADO CON BACKEND REAL**. Los cuatro roles reciben 403 al abrir los otros tres paneles. |
| Escrituras entre roles | **APROBADO CON BACKEND REAL**. Cuatro casos HTTP con CSRF válido; Quarkus también rechaza Administración/Secretaría cruzadas. |
| Operación protegida durante caída | **APROBADO CON BACKEND REAL**. Lectura y POST de check-in devuelven 503 antes de operar, con Retry-After; la misma sesión se recupera. |
| Caducidad efectiva | **APROBADO CON BACKEND REAL**. Segundo Quarkus empaquetado, TTL temporal de un minuto; espera natural, API 401 y Laravel exige login. **HTTP/cookies/CSRF, sin atribuirle navegador**. |
| Registro, OTP y recuperación de Fase 9 | **APROBADO CON BACKEND REAL**. Entrega demo privada, persistencia y revocación; sin correo externo. |
| Consentimiento Google / entrega Gmail | **NO EJECUTADO** con proveedores externos. Sus dobles/regresiones no acreditan ese servicio real. |

La política existente permite una sesión vigente por usuario: un nuevo login revoca la anterior. Los observadores de API utilizan cuentas diferentes de las sesiones interactivas. No se amplió ROL004 hacia las APIs exclusivas de ROL002.

## 10. Administración real desde Blade

Todas las filas siguientes son **APROBADO CON BACKEND REAL** mediante formularios de navegador, consultas posteriores a la API y comprobaciones PostgreSQL indicadas arriba.

| Módulo | Operaciones comprobadas | Endpoints Quarkus bajo `/api/v1` |
| --- | --- | --- |
| Médicos | Listar, alta, detalle, editar, asociar dos especialidades, inactivar y reactivar. | GET/POST `/personal/medicos`; GET/PUT `/{codMed}`; PUT `/{codMed}/estado`. |
| Secretarios | Alta, editar teléfono/nombre, inactivar/reactivar, restablecer acceso; 204 aceptado, sesiones previas revocadas y login nuevo válido. | GET/POST `/personal/secretarias`; GET/PUT `/{codSec}`; PUT `/estado` y `/password`. |
| Especialidades | Listar, alta, editar, estados, médicos vinculados; exclusión del catálogo/agenda y rechazo de un slot obsoleto al inactivar. | `/personal/especialidades`, `/{codEsp}`, `/estado`, `/medicos`; catálogo y disponibilidad de Secretaría. |
| Agendas | Crear y editar horario semanal, duración 20 min; superposición, cambio de horario y duración incompatibles con una reserva rechazados. | `/administracion/medicos/{codMed}/horarios`, `/duracion`; `/administracion/horarios/{codHor}`. |
| Bloqueos | Crear bloqueo parcial, consultar detalle y trazabilidad; cita afectada conservada. | `/administracion/medicos/{codMed}/bloqueos`, `/administracion/bloqueos/{codBlo}`, `/citas-afectadas`. |
| Pacientes y sanciones | Perfil, incidencias, sanción de siete días, detalle y vigencia informada; reserva rechazada y login 423 del paciente sancionado. | `/administracion/pacientes`, `/{codPac}`, `/incidencias`, `/sanciones`, `/sanciones/{codInc}`. |

## 11. Secretaría real desde Blade

| Flujo | Clasificación | Evidencia |
| --- | --- | --- |
| A. Buscar por CI/nombre y perfil | **APROBADO CON BACKEND REAL** | Formulario de búsqueda, datos administrativos y habilitación efectiva. La búsqueda por correo también se ejecutó en la integración real heredada. |
| B. Reserva presencial | **APROBADO CON BACKEND REAL** | Paciente → especialidad → médico/fecha → slot → confirmación → detalle. Persistencia Ventanilla y codSec del usuario autenticado. |
| C. Cupo duplicado | **APROBADO CON BACKEND REAL** | Dos secretarios con sesiones distintas; el formulario anterior recibe conflicto y vuelve a disponibilidad. Una sola reserva efectiva. |
| D. QR y llegada | **APROBADO CON BACKEND REAL** | Token emitido por SQL en fixture privado, introducido en POST; estado En Espera, llegada/actor, segundo check-in rechazado y campo vacío. |
| E. Cancelar | **APROBADO CON BACKEND REAL** | Motivo, confirmación, estado Cancelada, actor auditado y cupo libre cuando no existe impedimento. |
| F. Inasistencia | **APROBADO CON BACKEND REAL** | Antecedente vencido aislado, sin llegada; transición real y una incidencia INASISTENCIA_NO_SHOW. Ver limitación temporal del fixture debajo. |
| G. Historial | **APROBADO CON BACKEND REAL** | 23 cancelaciones sintéticas persistidas, página inicial de 20 y segunda de 3; estado/rango y ausencia de información clínica. |
| Citas y ventana | **APROBADO CON BACKEND REAL** | Listado con filtros y tamaño 1, detalle, D+14 aceptado y D+15 rechazado por la API. |
| Restricción por sanción | **APROBADO CON BACKEND REAL** | Perfil restringido decidido por backend; no se calcula ni levanta la sanción en Laravel. |

Se utilizan `/secretaria/pacientes`, sus detalles e historial, `/especialidades`, `/medicos`, `/disponibilidad`, `/citas`, detalles/acreditación/asistencia/cancelar/inasistencia y `/checkin`. No se inventó una API.

**Límite del fixture temporal:** las citas de QR/inasistencia se crean mediante la función real de reserva y se envejecen únicamente en el bootstrap de la base exclusiva, siguiendo la estrategia histórica de Fase 9. Se conservan constraints, triggers, auditoría y generador de firma. No se cambia el reloj ni se desactivan reglas. La prueba demuestra la operación sobre un antecedente que cumple las condiciones al ejecutarla; no demuestra haber esperado el vencimiento natural de una consulta durante el recorrido.

## 12–14. HTTP, navegador, concurrencia, errores y seguridad

- **181/181 comprobaciones** en Chrome → Laravel → Quarkus → PostgreSQL. Incluyen operaciones, estados, privacidad y 27 pantallas/variantes × tres anchuras. No son 181 flujos clínicos distintos.
- **7/7 comprobaciones HTTP adicionales reales**: login normal, formulario máximo 500, rechazo local de 501 con error por campo/old input, rechazo independiente 400 del backend, 422 por credenciales ausentes, GET incapaz de cambiar estado y DTO sin credenciales.
- **5/5 comprobaciones de expiración reales**, con HTTP/cookies/CSRF y un Quarkus temporal empaquetado de TTL reducido.
- **Concurrencia real**: dos solicitudes simultáneas a Quarkus, **201 y 409**; PostgreSQL conserva una sola reserva efectiva. El caso de navegador desactualizado es otra prueba: `php -S` procesa secuencialmente, por lo que no se presenta como prueba de concurrencia simultánea del servidor web.
- La suite backend también aprobó sus escenarios de reserva/slot/paciente, bloqueo, check-in duplicado, cancelación/check-in, inasistencia manual/automática y sanción/reserva. No equivale a una prueba de carga.

| Error / escenario | Clasificación |
| --- | --- |
| 400, 401, 403, 404, 409, 422, 423 | **APROBADO CON BACKEND REAL**, en los escenarios descritos. |
| Servicio sin conexión / caída de transporte | **APROBADO CON BACKEND REAL**: pérdida real de conectividad, autorización bloqueada y recuperación de sesión. |
| Respuestas internas 500/502/503, timeout controlado y JSON inválido | **APROBADO CON SERVICIO SIMULADO**. Proxy local de fallos; autenticación sana llega a Quarkus real. |
| Escritura de resultado desconocido | **APROBADO CON SERVICIO SIMULADO**. Un solo intento, ningún éxito ficticio, QR descartado y recomendación segura de consultar antes de repetir. |

El proxy de fallos aprobó **15/15 comprobaciones**. No se causó una avería deliberada de PostgreSQL o de un servicio habitual para fabricar un 500 real.

Bearer y QR no aparecen en URLs o HTML de resultado, no se usan credenciales en localStorage/sessionStorage, las contraseñas no se conservan en old input, y los actores/canal proceden del backend. Los errores internos de prueba no se reflejan en la pantalla. Se verificó escapado HTML con texto persistido que contenía una etiqueta script; no se ejecutó.

Las cookies locales son HttpOnly/SameSite=Lax y utilizan HTTP solo en loopback. No se afirma haber validado HTTPS, Secure en un despliegue real o una política de producción a partir de este laboratorio.

## 15–17. Resultados exactos de regresión y compilación

| Ejecución de esta fase | Resultado |
| --- | --- |
| Quarkus: `mvnw.cmd -o -DskipITs=false verify` | **261 aprobadas, 0 fallos, 0 errores, 0 omitidas; BUILD SUCCESS**. |
| Desglose backend | Surefire 260: 42 unitarias y 218 QuarkusTest; Failsafe 1 aplicación empaquetada. |
| Laravel final con integración activada | **262 casos: 257 aprobados, 5 omitidos, 1865 aserciones, 0 fallos/errores**. |
| Laravel estándar después de la corrección | **262 casos: 245 aprobados, 17 omitidos, 1610 aserciones, 0 fallos/errores**. |
| Regresión nueva del motivo de bloqueo | **3 aprobadas, 12 aserciones**; antes de corregir reproducía dos fallos. |
| Omisiones finales con infraestructura activa | Cinco DemoLoginIntegrationTest opt-in; no se utilizaron sus contraseñas demo. |
| Omisiones de ejecución estándar | Las cinco demo, siete LiveBackendIntegrationTest y cinco LiveHttpStackTest. |
| Vite | Compilación final aprobada; sin cambio de dependencias o lockfiles. |
| Blade | `php artisan view:cache` aprobado. |
| Laravel Pint | Ejecutado sobre PHP modificado/nuevo; comprobación final registrada en el cierre. |

Los grupos reales se activaron explícitamente con fixtures y servidores aislados. El resto de los casos Laravel conserva `Http::fake` cuando corresponde; no se reclasifica toda la suite como E2E. Los proveedores externos Google/Gmail conservan sus dobles.

Se descartó del conteo Maven un XML antiguo de una clase que no pertenece a esta corrida; el total se contrastó con el log nuevo de Surefire/Failsafe. No se eliminó ninguna prueba. Los tiempos del laboratorio incluyen interrupciones/esperas del entorno y no son métricas de rendimiento.

Vite mantiene un aviso preexistente de fontaine opcional; no impide compilar o cargar Instrument Sans. No se instaló esa dependencia.

## 18. Diseño, accesibilidad y responsive

Se leyeron `ui-ux-pro-max`, `design-system` y `design-taste-frontend`, incluida la referencia app-screens y las de tokens, componentes/estados. El buscador de Laravel se ejecutó para formularios, errores y tablas. El validador de tokens aprobó Secretaría. Se mantuvieron Instrument Sans, colores, espaciado, sidebar y componentes de Fases 10/11.

La revisión adicional de **Laravel en modo producción** aprobó **95/95 comprobaciones**: 22 pantallas × 1440/768/390, fuente, título, labels, desbordamiento, menú/foco/Escape, confirmación con teclado, orientación con movimiento reducido y máximo corregido. Se capturaron esas páginas realmente en Chrome. La medición de 181 comprobaciones y esta revisión son corridas distintas y no se suman como si fueran casos independientes de PHPUnit.

El linter de las **22 respuestas finales** devolvió **0 FAIL, 0 WARN**. El linter de CSS/JS compartido en modo app dio 0 FAIL y tres avisos: dos colores de la identidad existente y la sombra del diálogo flotante. Se mantienen por consistencia autorizada y porque la sombra corresponde a un overlay.

Las primeras exportaciones para el inspector contenían la instrumentación local y rutas de fuentes incompletas. Se exportó la respuesta real de producción y se adaptaron todas las URLs de assets a la raíz del servidor estático del inspector. No se alteraron colores, contenido o reglas CSS para ocultar un fallo. Las páginas vivas ya habían confirmado la fuente cargada; el resultado final del inspector y las capturas se registra en el cierre.

Desktop conserva sidebar y jerarquía; tablet/móvil utilizan menú nativo y formularios reorganizados. Las tablas mantienen las columnas en regiones horizontales identificadas; no existe scroll horizontal de la página. Hay foco visible, etiquetas, mensajes por campo y confirmaciones que reciben foco y cierran con Escape. No se afirma una certificación WCAG, prueba con lector de pantalla o dispositivos físicos.

Observaciones visuales de prioridad baja documentadas: algunas fechas se presentan en ISO y algunos motivos administrativos conservan su código técnico; los detalles breves dejan espacio libre en escritorio y las tablas extensas requieren scroll en móvil. No impiden los flujos comprobados y no se rediseñaron en esta fase.

## 19. Defectos y correcciones

**Defecto de producto corregido:** motivo de bloqueo desalineado. La vista y Form Request admitían 2000 caracteres; `AgendaHorarioService` y la columna real admiten 500. Se ajustaron ambos límites a 500 y se añadió ayuda. Tres pruebas comprueban el límite con caracteres Unicode, rechazo previo a la solicitud clínica y valor conservado. HTTP real confirmó tanto el rechazo local como el 400 independiente de Quarkus.

**Documentación corregida:** README identificaba el backend como Java 21; se actualizó a Java 25 y se enlazó la guía reproducible.

**Correcciones del instrumental, sin alterar negocio:** cuentas separadas por la política de sesión única; lectura correcta de `incidencias` en el DTO de sanciones; esperas de navegación/arranque; progreso a archivo; UTF-8 del auditor y rutas de assets de las exportaciones. Hubo corridas parciales fallidas durante ese ajuste, conservadas en target y excluidas de los resultados finales. La prueba adicional de caducidad se cerró mediante HTTP, sin atribuirle una automatización de navegador fallida.

No se detectó una necesidad de modificar contratos, lógica de producción Quarkus, funciones SQL o migraciones. No se encubrieron fallos suprimiendo pruebas.

## 20. Archivos de entrega

El inventario exacto de creados/modificados se incluye al final. Los scripts son herramientas opt-in de QA: no son endpoints, no evitan el login, no habilitan cuentas demo en producción y no se cargan desde `public/index.php`. Sus credenciales, sesiones, reportes y capturas se generan en directorios ignorados.

Artefactos locales principales, relativos a `backend_clinica/target/integration-fase12/`:

```text
backend-verify.log
legacy-laravel.xml
final-live-laravel.log
browser-e2e.json
database-audit.json
contract-http.json
fault-e2e.json
session-expiry.json
visual-live/report.json
visual-live/<pantalla>-{1440,768,390}.jpg
visual-live/confirmation-mobile.jpg
visual-live/inspect/<pantalla>/report.json
visual-live/inspect/<pantalla>/{desktop,tablet,mobile}-fold.jpg
visual-live/inspect/<pantalla>/{desktop,tablet,mobile}-page-01.jpg
```

El XML final real se escribe en `legacy-laravel.xml` por el helper. El XML estándar está también en `frontend_clinica/storage/logs/fase12-final-standard.xml`. Los fixtures privados no son logs de prueba para compartir y no deben publicarse.

## 21. Decisiones de negocio pendientes

| Función | Estado y propuesta para decisión |
| --- | --- |
| Alta presencial de paciente | **NO EJECUTADO / NO IMPLEMENTADO**. No existe contrato autorizado POST Secretaría/pacientes. Proponer expediente pendiente, identidad y duplicados CI/correo, consentimiento y activación OTP por canal verificado; contraseña definida por el paciente y rol fijado en servidor. Auditar al secretario que inicia el proceso, sin permitir reservas hasta la verificación. |
| Entrega del QR al paciente | **NO EJECUTADO / NO IMPLEMENTADO**. Reutilizar el token/generador SQL y la sesión del portal; diseñar un contrato para consultar exclusivamente la cita del propio paciente, respuesta privada/no-store y representación descargable bajo HTTPS. No poner tokens en URL, listados administrativos o logs. El inicio del portal existe, pero no hay endpoint de entrega que se pueda reutilizar tal cual. |
| Levantamiento anticipado | **NO EJECUTADO / NO IMPLEMENTADO**. Requiere autorización ROL004, motivo, evento de reversión auditable y cálculo efectivo que considere otras sanciones vigentes, conservando el registro original. Sin endpoint o botón simulado hasta aprobar la política. |

## 22. Cómo ejecutar

La guía completa está en [ejecucion-integracion.md](../../docs/ejecucion-integracion.md): requisitos, variables sin valores privados, dependencias, arranque nativo/Compose, datos aislados, acceso autorizado, pruebas, solución de errores y cierre exclusivo del entorno temporal.

Secuencia: Start-Database → Backend verify → Write-Fixtures → Start-Applications legacy → Legacy → Stop-Applications → Start-Applications browser → Browser → Audit → Contract/Fault/Expiry/Visual → Stop-Applications → Stop-Database. Esperar `/hello` y `/login` antes de cada corrida. Para repetir datos mutables, los helpers New-BrowserDataset/New-LegacyDataset crean otra base sin borrar la anterior.

No incluir en un repositorio o presentación las claves/contraseñas de `runtime.json`, fixtures, cookies o perfiles Chrome. Los scripts no necesitan editar secretos habituales.

## 23. Riesgos y límites restantes

- Esta evaluación local aislada no certifica producción, carga sostenida, navegadores/dispositivos físicos o proveedores externos.
- Las sesiones Quarkus viven en memoria; reiniciar invalida sesiones y el despliegue multinstancia necesita una estrategia explícita. No se cambió esa política.
- V2 contiene seed histórico de desarrollo. Revisar aprovisionamiento y accesos demo antes de exposición pública sin reescribir migraciones históricas durante esta fase.
- Quarkus advierte una propiedad CORS no reconocida y una propiedad Hibernate obsoleta. No afectan la comunicación servidor-servidor comprobada; requieren revisión antes de otro modelo de exposición.
- SMTP/Google externos, HTTPS de despliegue, backups/restauración de una base real, lector QR físico/cámara y accesibilidad con lector de pantalla: **NO EJECUTADO**.
- Los fallos 500/502/503 y timeout inducidos mediante proxy siguen clasificados como simulados, aunque el tratamiento Laravel, las cookies y la autorización sana sean reales.

## 24. Presentación universitaria y veredicto

Preparar previamente el entorno aislado nuevo y las cuentas sintéticas; evitar credenciales en diapositivas/capturas. Demostrar login ROL004, personal/especialidad/horario, login ROL002, paciente → reserva → detalle, check-in del fixture del día, cancelación/historial, bloqueo y sanción. Mostrar una reserva concurrente 201/409 y el reporte de integridad. Explicar con claridad la preparación del antecedente temporal de inasistencia y las tres decisiones pendientes.

**Los paneles y sus flujos principales fueron evaluados con backend y persistencia reales.** Las regresiones, integridad y controles descritos aprobaron con los límites señalados. Esto cierra la verificación del alcance de Fase 12, sin afirmar que todo el sistema esté listo para producción ni iniciar otra fase.

## Cierre de evidencia

El resultado final del inspector, revisión de capturas, inventario y estado de procesos/Git se completan a continuación.


### Inspector final y capturas

Diez pantallas, tres anchuras cada una: **30 mediciones, 0 FAIL, 5 WARN**, sin fuentes faltantes.

| Pantalla | FAIL | WARN |
| --- | ---: | ---: |
| Dashboard de Administración | 0 | 0 |
| Formulario de médico | 0 | 0 |
| Agendas | 0 | 0 |
| Bloqueo, detalle | 0 | 1 |
| Sanción, detalle | 0 | 1 |
| Dashboard de Secretaría | 0 | 1 |
| Disponibilidad | 0 | 0 |
| Check-in QR | 0 | 1 |
| Historial | 0 | 0 |
| Recurso inexistente | 0 | 1 |

Los cinco avisos son `hero-void` de escritorio: el marco conserva espacio libre en detalles breves, pocas citas, QR o error. Se mantiene el ancho legible y no se llena con datos inventados. No hay avisos finales de fallback de tipografía.

Se abrieron capturas de las tres anchuras para dashboards, formulario de médico, agenda, bloqueos/detalle y formulario, especialidades, perfiles administrativos, búsqueda, disponibilidad, citas, QR, historial, vacío y error. Se revisó el diálogo móvil y las hojas de scroll del formulario largo e historial, incluido Guardar y Siguiente al pie. Los bloques grises de las hojas de contacto del inspector son casillas sin captura; no son desbordamientos del producto.

Ejemplos con personas exclusivamente sintéticas:

- [Administración, escritorio](../../backend_clinica/target/integration-fase12/visual-live/admin-dashboard-1440.jpg).
- [Disponibilidad, móvil](../../backend_clinica/target/integration-fase12/visual-live/secretaria-disponibilidad-390.jpg).
- [Asistencia persistida](../../backend_clinica/target/integration-fase12/visual-live/secretaria-asistencia-1440.jpg).
- [Confirmación móvil y foco](../../backend_clinica/target/integration-fase12/visual-live/confirmation-mobile.jpg).

Crítica de la revisión: el trabajo y la siguiente acción se identifican en el primer vistazo; formularios y navegación mantienen la identidad compartida. Las mejoras posteriores de mayor utilidad serían localizar fechas ISO, traducir códigos de motivo a mensajes administrativos y mejorar la lectura móvil de tablas sin ocultar información. Se documentan como mejoras de presentación; no se cambió el diseño completo.

### Comprobaciones finales y cierre

- Pint `--test` aprobó los cuatro archivos PHP de aplicación/pruebas creados o modificados; Blade y Vite también aprobaron.
- Los scripts Node y PowerShell pasaron comprobación de sintaxis.
- El escaneo de 42 logs/snapshots generados no encontró coincidencias de secretos del fixture del navegador ni cadenas bcrypt. No sustituye una auditoría universal de todos los archivos del equipo.
- Git diff no presenta errores de whitespace; índice vacío; HEAD permanece `5bc814a`.
- Se detuvieron únicamente los PID registrados de Quarkus/Laravel y el contenedor etiquetado `clinica-fase12-postgres_test-1`, después de verificar su tmpfs. Los procesos secundarios de Fault/Expiry/Visual cerraron al terminar.
- El PostgreSQL habitual `clinica_postgres_nuevo` siguió publicado en `127.0.0.1:5433`. No se ejecutaron pruebas o consultas clínicas sobre él.
- No se eliminaron volúmenes compartidos. Los datos efímeros de PostgreSQL de prueba no se conservan al detenerlo; la evidencia está en target/storage ignorados. Los fixtures privados no deben compartirse.
- No se modificó código de producción del backend, migraciones, dependencias, login, OAuth, OTP, roles, secretos, skills o configuraciones de asistentes.
- No hubo staging, rama nueva, commit, push o inicio de otra fase.

### Inventario exacto de esta fase

**5 archivos existentes modificados:**

```text
README.md
frontend_clinica/app/Http/Requests/Administracion/BloqueoRequest.php
frontend_clinica/resources/views/administracion/bloqueos/form.blade.php
frontend_clinica/tests/Feature/AdministracionPanelTest.php
frontend_clinica/tests/Feature/LiveHttpStackTest.php
```

**21 archivos nuevos:**

```text
backend_clinica/src/test/java/org/clinica/integration/Phase12IntegrationFixture.java
backend_clinica/src/test/resources/integration-fase12-integrity.sql
compose.integration.yml
docs/ejecucion-integracion.md
frontend_clinica/docs/fase12-validacion.md
frontend_clinica/tests/Integration/router.php
scripts/integration/Audit-Database.ps1
scripts/integration/Invoke-Verification.ps1
scripts/integration/New-BrowserDataset.ps1
scripts/integration/New-LegacyDataset.ps1
scripts/integration/Start-Applications.ps1
scripts/integration/Start-Database.ps1
scripts/integration/Stop-Applications.ps1
scripts/integration/Stop-Database.ps1
scripts/integration/Write-Fixtures.ps1
scripts/integration/browser-client.mjs
scripts/integration/browser-e2e.mjs
scripts/integration/contract-http.mjs
scripts/integration/fault-e2e.mjs
scripts/integration/session-expiry.mjs
scripts/integration/visual-audit.mjs
```

Quedan fuera del inventario las seis eliminaciones y los dos archivos no rastreados que ya pertenecían al estado inicial del usuario. Los scripts y fixtures nuevos son herramientas de pruebas opt-in; las skills y herramientas de IA originales no se incorporaron a Git.

