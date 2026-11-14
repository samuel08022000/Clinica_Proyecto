# Fase 13 — Auditoría, limpieza y compatibilidad Flyway

## 1. Diagnóstico y alcance

Fuente de verdad: checkout local de Clinica_Proyecto, sin ramas nuevas, commits ni push. Se revisaron los directorios versionados, configuración, SQL, rutas/imports, controladores, servicios, repositorios, entidades, Blade, recursos, scripts y tests. El [inventario por archivo](fase13-inventario.md) registra los candidatos por contenido y las referencias dinámicas. Se usaron demo/mock/fake/fixture/seed/sample/test-only/simulated/simulad/prueba/temporal/placeholder/hardcoded/TODO/FIXME/dummy como búsqueda, no como criterio automático de borrado. Dependencias vendor/node_modules y artefactos target son productos generados, no código propio que deba auditarse como fuente. No se leyeron ni restauraron los documentos personales ausentes.

Los paneles Administración y Secretaría ya usan Quarkus real; no se encontró un servicio habitual que simule éxito clínico ni un Http::fake fuera de tests. Sí había cuentas demo publicadas en el login, SQL histórico con seed, generadores de credenciales compartidas, esqueletos vacíos, consultas antiguas sin consumidores y contenido público desactualizado. Se implementaron cambios, no solo recomendaciones.

## 2. Git inicial

HEAD: b16283b Integrar y validar paneles de administracion y secretaria. Commits previos: 5bc814a, 24c6d97, 9b8374c y 91141f0.

El primer git status --short mostró exclusivamente seis eliminaciones previas y dos archivos sin seguimiento. Se conservaron exactamente:

- `D Analisis de Requerimientos Clinica.docx`
- `D Documento_de_Requisitos_Sistema_Gestion_Citas_Medicas.docx`
- `D EVALUACION CONTINUA.pdf`
- `D HISTORIAS DE USUARIO CLINICA (1).xlsx`
- `D PROYECTO CLINICA.docx`
- `D diccionario de datos.docx`
- `?? EVALUACION PROCESUAL.pdf`
- `?? frontend_clinica/docs/fase10-administracion.md`

## 3–8. Inventario y decisiones de migraciones

El [README obligatorio de Flyway](../backend_clinica/src/main/resources/db/migration/README.md) documenta nombre exacto, tipo, ruta, objetivo, tablas, columnas, funciones, triggers, índices, restricciones, dependencias, servicios, perfiles, demo y seguridad de **cada** archivo.

| Archivo | Tipo y versión | Decisión |
|---|---|---|
| V1__control_citas_postgresql_completo.sql | V1 | Intacta; motor clínico, 19 tablas, dos vistas, cuatro roles funcionales, pgcrypto/btree_gist, funciones, triggers y restricciones. |
| V2__seed_usuarios_prueba.sql | V2 | Intacta por historia; cuatro cuentas, perfiles, contraseña/BCrypt común, catálogos y agenda ficticia. B9 evita su aplicación en instalaciones nuevas. |
| V3__corregir_credenciales_usuarios_demo.sql | V3 local, db/dev-migration | Existe localmente y se conserva intacta; corrige cuatro hashes demo, guardada por perfil/placeholder/base/cuentas. No se copia al classpath. |
| V4__identidades_google.sql | V4 | Intacta; identidad externa Google, unicidad y contraseña local opcional. |
| V5__administracion_agendas_consistentes.sql | V5 | Intacta; versiones de slots, trazabilidad de bloqueos, duración y serialización de reservas. |
| V6__reservas_recepcion_errores_y_habilitacion.sql | V6 | Intacta; habilitación de paciente y actor, correo verificado, canales y errores tipados. |
| V7__checkin_qr_validacion_y_concurrencia.sql | V7 | Intacta; validación HMAC del QR original y check-in concurrente autorizado. |
| V8__cancelaciones_inasistencias_recepcion.sql | V8 | Intacta; cancelaciones/inasistencias con actor e incidencias, conserva antecedentes. |
| V9__sanciones_temporales_consistentes.sql | V9 | Intacta; vigencia efectiva, bloqueo por paciente, sanciones no superpuestas y desbloqueo. |
| R__verificacion_email_otp.sql | R, sin versión | Intacta; verificación de correo y OTP limitado, hash y consumo único. |
| B9__instalacion_sin_datos_demo.sql | B9, nueva | Arranque acumulativo funcional V1 + V4–V9, sin V2/V3; R se aplica después. |

V1 no se modificó ni ejecutó manualmente. Las entidades y servicios dependen de su esquema, vistas y reglas; los cuatro roles no son datos demo. V4–V9 son cambios funcionales indispensables y no se eliminaron por su prefijo. No se hallaron otras migraciones clínicas Flyway. Laravel conserva tres migraciones de infraestructura users/cache/jobs; no se usan para migrar la base clínica. Los SQL integrity y fixtures son tests opt-in y están separados del classpath habitual.

## 9–13. Tabla de decisiones de código y datos

| Elemento | Era demo/real/test | Acción aplicada | Justificación | Prueba |
|---|---|---|---|---|
| V2 histórica | Demo con historia real | Conservar checksum; añadir B9 sin seed | No invalidar bases anteriores ni borrar usuarios | FlywayCompatibilityTest y upgrade tests |
| V3 local | Demo histórico local | Conservar SQL original y ubicación dev; eliminar generadores/plantilla/verificador | La base habitual ya la registró; no crear nuevas contraseñas compartidas | Aplicación y validación de V3 original en servidor aislado |
| Selector demo de login | Demo habitual local | Eliminar config, Blade, JS y CSS | Publicaba identidades ficticias por defecto | PortalFrontendTest en entorno local con flag antiguo activo |
| /hello y GreetingResource | Ejemplo de framework | Eliminar recurso y tests de ejemplo; sustituir por RuntimeSecurityIT | No era una función clínica ni una comprobación de autenticación | GET /auth/me requiere 401; /hello devuelve 404 |
| Endpoints OTP/reset demo | Herramienta dev/test | Mover a DevelopmentAuthController condicionado a dev/test/integration | No registrar rutas de herramientas en el paquete habitual | RuntimeSecurityIT; suites OTP y recuperación |
| DemoOtpDeliveryProvider e interfaz OtpDeliveryProvider | Doble/herramienta necesaria | Conservar guardas de perfil, opt-in, clave y loopback | QA de OTP sin enviar correo real; receipt no confirma entrega | DemoOtpDeliveryProviderTest y PasswordRecoveryPersistenceTest |
| SMTP, Google, OTP real | Real | Conservar; mailer mock por defecto solo test | Evitar entrega simulada normal sin romper proveedores reales | Suites SMTP, Google, registro, autenticación y recuperación |
| dummyHash de autenticación | Seguridad real | Conservar | Compara BCrypt también para cuentas inexistentes, sin crear usuarios ni credenciales válidas | AuthServiceTest y PatientRegistrationServiceTest |
| Cinco esqueletos Java y seis controladores PHP vacíos | Obsoleto | Eliminar | Sin rutas, inyección ni consumidores; implementación actual en controladores administrativos/Secretaria | Compilación, rutas y regresión |
| AuditoriaRepository | Obsoleto sustituido | Eliminar | Sin consumidores ni llamadas a sus métodos; historial e incidencias actuales en CitaRepository/IncidenciaRepository | Búsqueda de referencias, compilación y flujos de historial/sanciones |
| pacientes.blade.php | Vista antigua sin consumidor | Eliminar | Sin ruta ni view/include; reemplazada por módulos actuales | route:list, view:cache, panel tests |
| import.sql | Plantilla comentada vacía | Eliminar | No aportaba datos ni esquema; Flyway es la autoridad | Migraciones y arranque |
| DatabaseSeeder | Demo Laravel | Dejar seeder vacío sin crear Test User | No generar identidades paralelas a Quarkus | Inspección, regresión Laravel y ausencia de migraciones automáticas en Composer |
| DemoLoginIntegrationTest y verify-demo-logins | Test de credencial compartida sustituido | Eliminar | LiveBackendIntegrationTest/LiveHttpStackTest y browser-e2e ya cubren los cuatro roles con contraseñas aleatorias propias | Integración real aislada |
| ExampleTest Feature/Unit | Ejemplos repetidos | Eliminar y quitar suite Unit vacía | assertTrue(true) no aportaba cobertura; PortalFrontendTest cubre home y CSRF | PHPUnit |
| Http::fake, mocks JUnit, fixtures Fases 9–12 | Test necesario | Conservar aislados | Resiliencia, límites, concurrencia, QA reproducible | PHPUnit/JUnit y recorridos reales separados de fault injection |
| Entidades JPA, modelo User/factory y migraciones Laravel | Real/framework o potencialmente obsoleto | Conservar | ORM/PSR-4/configuración tienen referencias dinámicas; User está en config/auth, factory en HasFactory, migraciones son historial de infraestructura | Inspección de consumidores; no eliminar por falta de imports textuales |
| Imágenes de agenda/recepción, JS y CSS de paneles | Recurso real | Conservar | Consumidos por vistas y entrada Vite | build, Blade y navegador |
| Portal con “API pendiente” y recordatorio no entregado | Contenido obsoleto | Actualizar a operaciones reales de recepción, QR y consulta; retirar anuncio de recordatorio | No presentar infraestructura incompleta como servicio al paciente | PortalFrontendTest y recorrido web |

## 14. Configuración final

Flyway usa migrate-at-start y validate-on-migrate; baseline-on-migrate=false rechaza adopción silenciosa de esquemas ajenos. clean-disabled=true. Validar antes de migrate mediante validate-at-start no sirve para una base vacía con pendientes, por lo que no se habilitó. dev conserva la ubicación V3 original, placeholder development y out-of-order=true; habitual/test mantienen orden por defecto y classpath. Un artefacto normal que apunte a una base con V3 debe resolver explícitamente esa ubicación original; no ignorar missing ni regenerar su SQL.

Se apagó SQL logging por defecto para evitar exposición accidental; se corrigió la clave CORS obsoleta a quarkus.http.cors.enabled. SMTP mock predeterminado pasa a false y test lo fija true. SmtpOtpDeliveryProvider rechaza mock fuera de test; el proveedor de entrega permanece disabled hasta configurarse. Las variables backend Google duplicadas se retiraron del ejemplo frontend, no de archivos .env. El ejemplo Laravel usa cache file/queue sync; Composer no ejecuta migraciones automáticas. Los .env reales, credenciales, sesión, autorización y requisitos de contraseña no se alteraron. pgAdmin pasó a un perfil opcional; DBeaver y PostgreSQL no requieren sus credenciales. PHPUnit fija ENV y SERVER y usa quarkus.test para sus Http::fake, mientras las suites Live seleccionan explícitamente la URL aislada. Start-Applications espera las respuestas HTTP reales y el verificador comprueba los códigos de salida nativos.

## 15–17. Archivos de esta fase

### Creados

- `backend_clinica/src/main/java/org/clinica/controller/DevelopmentAuthController.java`
- `backend_clinica/src/main/resources/db/migration/B9__instalacion_sin_datos_demo.sql`
- `backend_clinica/src/main/resources/db/migration/README.md`
- `backend_clinica/src/test/java/org/clinica/RuntimeSecurityIT.java`
- `backend_clinica/src/test/java/org/clinica/service/FlywayCompatibilityTest.java`
- `backend_clinica/src/test/java/org/clinica/service/HistoricalMigrations.java`
- `docs/configuracion-dbeaver.md`
- `docs/fase13-auditoria.md`
- `docs/fase13-inventario.md`

### Modificados

- `.env.docker.example`
- `README.md`
- `backend_clinica/.env.example`
- `backend_clinica/.gitignore`
- `backend_clinica/README.md`
- `backend_clinica/db/dev-migration/README.md`
- `backend_clinica/docs/gmail-smtp.md`
- `backend_clinica/docs/integracion-fase9.md`
- `backend_clinica/docs/inventario-api-fase9.md`
- `backend_clinica/src/main/java/org/clinica/controller/AuthController.java`
- `backend_clinica/src/main/resources/application.properties`
- `backend_clinica/src/test/java/org/clinica/service/AgendaMigrationUpgradeTest.java`
- `backend_clinica/src/test/resources/integration-fase12-integrity.sql`
- `docker-compose.yml`
- `docs/ejecucion-integracion.md`
- `frontend_clinica/.env.example`
- `frontend_clinica/README.md`
- `frontend_clinica/app/Http/Controllers/AuthController.php`
- `frontend_clinica/app/Http/Controllers/PortalController.php`
- `frontend_clinica/composer.json`
- `frontend_clinica/config/clinic.php`
- `frontend_clinica/database/seeders/DatabaseSeeder.php`
- `frontend_clinica/docs/fase12-validacion.md`
- `frontend_clinica/phpunit.xml`
- `frontend_clinica/resources/css/app.css`
- `frontend_clinica/resources/js/app.js`
- `frontend_clinica/resources/views/auth/login.blade.php`
- `frontend_clinica/resources/views/welcome.blade.php`
- `frontend_clinica/tests/Feature/AuthFlowTest.php`
- `frontend_clinica/tests/Feature/GoogleAndPasswordRecoveryFlowTest.php`
- `frontend_clinica/tests/Feature/PortalFrontendTest.php`
- `frontend_clinica/tests/Feature/RegistrationOtpFlowTest.php`
- `scripts/integration/Audit-Database.ps1`
- `scripts/integration/Invoke-Verification.ps1`
- `scripts/integration/Start-Applications.ps1`
- `scripts/integration/browser-e2e.mjs`
- `scripts/integration/session-expiry.mjs`

### Eliminados por Fase 13

- `backend_clinica/scripts/dev/V3__corregir_credenciales_usuarios_demo.sql.template`
- `backend_clinica/scripts/dev/prepare-demo-credentials.ps1`
- `backend_clinica/scripts/dev/prepare_demo_credentials.php`
- `backend_clinica/scripts/dev/verify-demo-logins.ps1`
- `backend_clinica/src/main/java/org/clinica/GreetingResource.java`
- `backend_clinica/src/main/java/org/clinica/controller/AtencionCheckinController.java`
- `backend_clinica/src/main/java/org/clinica/controller/EspecialidadController.java`
- `backend_clinica/src/main/java/org/clinica/controller/IncidenciaNotificacionController.java`
- `backend_clinica/src/main/java/org/clinica/controller/ReservaCitaController.java`
- `backend_clinica/src/main/java/org/clinica/repository/AuditoriaRepository.java`
- `backend_clinica/src/main/java/org/clinica/service/IncidenciaNotificacionService.java`
- `backend_clinica/src/main/resources/import.sql`
- `backend_clinica/src/test/java/org/clinica/GreetingResourceIT.java`
- `backend_clinica/src/test/java/org/clinica/GreetingResourceTest.java`
- `frontend_clinica/app/Http/Controllers/AgendaHorarioController.php`
- `frontend_clinica/app/Http/Controllers/AtencionCheckinController.php`
- `frontend_clinica/app/Http/Controllers/EspecialidadController.php`
- `frontend_clinica/app/Http/Controllers/IncidenciaNotificacionController.php`
- `frontend_clinica/app/Http/Controllers/PersonalController.php`
- `frontend_clinica/app/Http/Controllers/ReservaCitaController.php`
- `frontend_clinica/resources/views/pacientes.blade.php`
- `frontend_clinica/tests/Feature/DemoLoginIntegrationTest.php`
- `frontend_clinica/tests/Feature/ExampleTest.php`
- `frontend_clinica/tests/Unit/ExampleTest.php`

Los archivos previos del usuario no forman parte de esta lista de eliminación. Los informes históricos Fases 9/12 conservan resultados de aquellas fechas y ahora incluyen una advertencia de cambios de Fase 13; no se atribuyen sus resultados antiguos a esta ejecución.

## 18–20. Compatibilidad y PostgreSQL

La lectura autorizada de la base habitual se hizo mediante BEGIN READ ONLY: PostgreSQL 15.19, db_clinica, diez filas históricas exitosas (V1–V9 incluida V3, más R). Conteos por rol: ROL001=4, ROL002=1, ROL003=1, ROL004=1. No se seleccionaron contraseñas, hashes, tokens ni datos clínicos personales. No se ejecutó migrate, repair, clean ni escritura sobre esa base. La disponibilidad histórica de V3 es por tanto necesaria, no una suposición.

Los escenarios mutables se ejecutan únicamente en PostgreSQL 15.19 aislado, proyecto clinica-fase12, puerto 35441 y tmpfs; cada base de compatibilidad se crea de forma exclusiva y se elimina en finally. Se conserva SQL/checksums de V1/V2/V4–V9/R con git diff vacío. B9 no toca cuentas históricas. Una instalación que solo haya alcanzado V1 todavía seguirá por V2: necesita una decisión particular para cambiar esa cadena y no queda declarada libre de demo.

| Escenario | Resultado comprobado |
|---|---|
| A — Base vacía | B9 + R aplicadas; cuatro roles, cero usuarios/perfiles/especialidades/horarios/slots/citas precargados. V2 y V3 no se ejecutan, incluso con V3 local presente y out-of-order=true. |
| B — Historial anterior | Upgrade real desde V4–V8: cinco casos aprobados con citas, QR, bloqueos, incidencias y sanciones conservados. La prueba adicional reproduce V1/V2/V4 + R y valida que B9 no interviene ni cambia cuentas/hashes/historial. |
| B — V3 local | SQL local original aplicado exclusivamente en db_clinica del servidor aislado 35441, luego upgrade y validate sin repetir V3. Checksum y cuentas conservados. Los diez checksums resueltos coinciden además con la lectura del historial habitual de 5433. |
| C — Fixtures/test | Fixtures aleatorias creadas bajo src/test con guardas, ownership y puerto exclusivo; no hay cuentas V2 en el dataset de navegador. Contraseñas y QR no se imprimen. |
| Integridad persistida | 16 aserciones de operaciones reales aprobadas: reservas/actor/canal, cancelación, check-in, inasistencia, bloqueo, sanción y auditoría. Cero migraciones fallidas, restricciones sin validar, triggers deshabilitados, citas huérfanas, slots activos duplicados o QR almacenados inválidos. |

FlywayCompatibilityTest: **2/2**. AgendaMigrationUpgradeTest: **5/5**. Columnas, restricciones, índices, triggers, vistas y funciones PL/pgSQL del arranque nuevo coinciden con el esquema histórico, normalizando únicamente finales CRLF/LF en la comparación.

## 21–23. Verificaciones ejecutadas

| Verificación de Fase 13 | Resultado |
|---|---|
| Compilación Java 25 / Quarkus 3.39.4 | Aprobada dentro de Maven verify. |
| Maven verify offline | **BUILD SUCCESS**, sin omisiones. |
| JUnit/Surefire | **261 pruebas, 0 fallos, 0 errores, 0 omitidas**; autenticación, Google, OTP, recuperación, personal, especialidades, agendas, reservas, QR, recepción, cancelaciones, historial, sanciones y migraciones. |
| Failsafe / paquete prod | **1 prueba aprobada**: /auth/me exige 401, /hello y endpoints demo devuelven 404. |
| Laravel con Quarkus/PostgreSQL reales | **255/255 pruebas, 1867 aserciones, sin fallos ni omisiones**; incluye 12 casos Live y las 243 regresiones internas. |
| PHPUnit bajo variables heredadas de producción | **28/28 casos dirigidos aprobados**; ENV/SERVER y URL de cliente aislados. No se desactivó CSRF del servidor HTTP. |
| Chrome real | **181/181 comprobaciones aprobadas**: roles, paneles, CRUD, reservas concurrentes, QR, cancelación, inasistencia, historial, sanción, revocación y responsive/teclado. Cero excepciones JavaScript. |
| SQL de solo lectura del dataset | Metadatos e **16 operaciones persistidas** aprobadas. |
| Contrato HTTP real | **7/7 comprobaciones** de validación, autorización y exclusión de material sensible. |
| Fallos controlados | **15/15 comprobaciones**. Chrome/Laravel y autenticación Quarkus reales; las fallas upstream son simuladas explícitamente solo en QA. |
| Blade view:cache | Aprobado. |
| Vite build | Aprobado; aviso opcional fontaine, sin bloquear build. |
| Laravel Pint --test | Aprobado. Se formatearon cuatro archivos que no cumplían Pint; las aserciones se conservaron. |
| route:list | 79 rutas propias, sin accesos demo. |
| Composer validate | Aprobado, sin cambios de dependencias. |
| Docker Compose config | Aprobado para base y fullstack; solo postgres_db en perfil predeterminado, pgAdmin opcional sin contraseña universal. No se arrancó el fullstack habitual. |
| Sintaxis de scripts | 6 JavaScript y 9 PowerShell de integración aprobados. |
| git diff --check | Aprobado; sin errores de espacios. |

Se utilizaron JDK 25.0.4.1, PHP 8.5.10, Node 22.21.0 y PostgreSQL 15.19. Evidencia privada de esta corrida: target/fase13-backend-final.log, target/fase13-legacy-final.log y target/integration-fase12/{browser-e2e,contract-http,fault-e2e,database-audit}.json, junto a capturas de Chrome y XML. No publicar runtime, fixtures ni perfiles del navegador. Expiry y Visual adicionales de Fase 12 se conservaron y revisaron, pero no se repitieron; la sesión y la revisión responsive se cubrieron en las pruebas ejecutadas indicadas.

Se detuvieron las aplicaciones registradas y el contenedor temporal con verificación de etiquetas y tmpfs. No se eliminaron volúmenes habituales. Al cierre solo permanece activo clinica_postgres_nuevo en 127.0.0.1:5433.

Los errores de preparación encontrados (variables heredadas de PHPUnit en ENV/SERVER y URL, arranque HTTP aún no listo, manejo de stderr en Windows, validación antes de migrate, selección de B9 al intentar reconstruir V4–V8, saltos CRLF en comparación de funciones y caché incremental de clases eliminadas) se corrigieron. No se relajaron las aserciones clínicas ni se eliminaron pruebas para ocultar fallos. Las comparaciones de cuentas y hashes se hacen en memoria con mensajes genéricos; no imprimen secretos.

## 24–26. Documentación entregada

- [Guía detallada de todas las V/R, V3 local y B9](../backend_clinica/src/main/resources/db/migration/README.md).
- [Configuración DBeaver y consultas solo lectura](configuracion-dbeaver.md).
- [Arranque de PostgreSQL, Quarkus, Laravel y Vite; integración y cierre](ejecucion-integracion.md).
- README raíz/backend/frontend actualizados; README de db/dev-migration ya no recomienda regenerar contraseñas compartidas.

## 27–28. Límites y riesgos restantes

- Las cuentas históricas siguen en las bases anteriores por requisito expreso de conservar datos. No se declara limpia la base habitual de cuentas demo ni se rotaron credenciales. Reutilización, desactivación o retiro de una cuenta requiere revisión individual autorizada y sus referencias.
- V3 local pendiente en una cadena histórica podría actualizar cuatro cuentas al iniciar dev; en la base habitual observada ya está aplicada. Mantener el archivo exacto y no regenerarlo. Cambiar perfiles requiere incluir su ubicación para validate.
- B9 crea una instalación sin usuarios. El primer administrador sigue requiriendo un alta individual aprobada; solo se documentó una propuesta segura. No se creó ningún administrador ni contraseña inicial.
- SMTP y Google conservan código y pruebas; no se enviaron mensajes a destinatarios reales ni se efectuó consentimiento OAuth externo. La prueba local no certifica proveedores, HTTPS, producción o capacidad.
- La cola notificaciones no acredita entrega automática de recordatorios: no existe un worker de entrega clínica completo. Se retiró el anuncio público de esa capacidad. No se eliminó su esquema ni sus funciones reales de cola.
- Los inicios Paciente/Médico son vistas autenticadas de cuenta; los paneles operativos completos de esos roles no se añadieron en esta fase.
- La comprobación del navegador utiliza cuentas sintéticas autorizadas en la base aislada; no se inició sesión con contraseñas de usuarios habituales.
- Los reportes privados, runtime, fixtures, cookies y perfiles de navegador quedan ignorados bajo target; no publicarlos. El aviso opcional fontaine de Vite no impide build.

## 29. Git final y entrega

Cambios de Fase 13: **9 archivos creados, 37 modificados y 24 eliminados**, además de las seis eliminaciones y dos archivos sin seguimiento que ya existían al iniciar. No se preparó staging.

```text
 M .env.docker.example
 D "Analisis de Requerimientos Clinica.docx"
 D Documento_de_Requisitos_Sistema_Gestion_Citas_Medicas.docx
 D "EVALUACION CONTINUA.pdf"
 D "HISTORIAS DE USUARIO CLINICA (1).xlsx"
 D "PROYECTO CLINICA.docx"
 M README.md
 M backend_clinica/.env.example
 M backend_clinica/.gitignore
 M backend_clinica/README.md
 M backend_clinica/db/dev-migration/README.md
 M backend_clinica/docs/gmail-smtp.md
 M backend_clinica/docs/integracion-fase9.md
 M backend_clinica/docs/inventario-api-fase9.md
 D backend_clinica/scripts/dev/V3__corregir_credenciales_usuarios_demo.sql.template
 D backend_clinica/scripts/dev/prepare-demo-credentials.ps1
 D backend_clinica/scripts/dev/prepare_demo_credentials.php
 D backend_clinica/scripts/dev/verify-demo-logins.ps1
 D backend_clinica/src/main/java/org/clinica/GreetingResource.java
 D backend_clinica/src/main/java/org/clinica/controller/AtencionCheckinController.java
 M backend_clinica/src/main/java/org/clinica/controller/AuthController.java
 D backend_clinica/src/main/java/org/clinica/controller/EspecialidadController.java
 D backend_clinica/src/main/java/org/clinica/controller/IncidenciaNotificacionController.java
 D backend_clinica/src/main/java/org/clinica/controller/ReservaCitaController.java
 D backend_clinica/src/main/java/org/clinica/repository/AuditoriaRepository.java
 D backend_clinica/src/main/java/org/clinica/service/IncidenciaNotificacionService.java
 M backend_clinica/src/main/resources/application.properties
 D backend_clinica/src/main/resources/import.sql
 D backend_clinica/src/test/java/org/clinica/GreetingResourceIT.java
 D backend_clinica/src/test/java/org/clinica/GreetingResourceTest.java
 M backend_clinica/src/test/java/org/clinica/service/AgendaMigrationUpgradeTest.java
 M backend_clinica/src/test/resources/integration-fase12-integrity.sql
 D "diccionario de datos.docx"
 M docker-compose.yml
 M docs/ejecucion-integracion.md
 M frontend_clinica/.env.example
 M frontend_clinica/README.md
 D frontend_clinica/app/Http/Controllers/AgendaHorarioController.php
 D frontend_clinica/app/Http/Controllers/AtencionCheckinController.php
 M frontend_clinica/app/Http/Controllers/AuthController.php
 D frontend_clinica/app/Http/Controllers/EspecialidadController.php
 D frontend_clinica/app/Http/Controllers/IncidenciaNotificacionController.php
 D frontend_clinica/app/Http/Controllers/PersonalController.php
 M frontend_clinica/app/Http/Controllers/PortalController.php
 D frontend_clinica/app/Http/Controllers/ReservaCitaController.php
 M frontend_clinica/composer.json
 M frontend_clinica/config/clinic.php
 M frontend_clinica/database/seeders/DatabaseSeeder.php
 M frontend_clinica/docs/fase12-validacion.md
 M frontend_clinica/phpunit.xml
 M frontend_clinica/resources/css/app.css
 M frontend_clinica/resources/js/app.js
 M frontend_clinica/resources/views/auth/login.blade.php
 D frontend_clinica/resources/views/pacientes.blade.php
 M frontend_clinica/resources/views/welcome.blade.php
 M frontend_clinica/tests/Feature/AuthFlowTest.php
 D frontend_clinica/tests/Feature/DemoLoginIntegrationTest.php
 D frontend_clinica/tests/Feature/ExampleTest.php
 M frontend_clinica/tests/Feature/GoogleAndPasswordRecoveryFlowTest.php
 M frontend_clinica/tests/Feature/PortalFrontendTest.php
 M frontend_clinica/tests/Feature/RegistrationOtpFlowTest.php
 D frontend_clinica/tests/Unit/ExampleTest.php
 M scripts/integration/Audit-Database.ps1
 M scripts/integration/Invoke-Verification.ps1
 M scripts/integration/Start-Applications.ps1
 M scripts/integration/browser-e2e.mjs
 M scripts/integration/session-expiry.mjs
?? "EVALUACION PROCESUAL.pdf"
?? backend_clinica/src/main/java/org/clinica/controller/DevelopmentAuthController.java
?? backend_clinica/src/main/resources/db/migration/B9__instalacion_sin_datos_demo.sql
?? backend_clinica/src/main/resources/db/migration/README.md
?? backend_clinica/src/test/java/org/clinica/RuntimeSecurityIT.java
?? backend_clinica/src/test/java/org/clinica/service/FlywayCompatibilityTest.java
?? backend_clinica/src/test/java/org/clinica/service/HistoricalMigrations.java
?? docs/configuracion-dbeaver.md
?? docs/fase13-auditoria.md
?? docs/fase13-inventario.md
?? frontend_clinica/docs/fase10-administracion.md
```

Sin commit ni push. Sin modificar .agents, .codex ni archivos .env reales; sin restaurar documentos académicos ni eliminar archivos personales. Los cambios quedan en el directorio de trabajo para revisión del usuario.
