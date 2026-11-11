# Informe de integración — Fase 9

Ejecución: 11 de noviembre de 2026, America/La_Paz. Referencia inicial de Git: `91141f0`. Alcance: integración y correcciones mínimas; no se construyeron paneles ni se inició Fase 10.

## 1. Diagnóstico y matriz de decisiones

| Componente | Estado inicial comprobado | Problema / solución | Riesgo y alcance |
|---|---|---|---|
| PostgreSQL de Compose | Imagen postgres:15-alpine; puerto publicado solo en loopback | Docker Desktop no pudo iniciar. Se utilizó PostgreSQL 15.19 nativo en un clúster nuevo y exclusivo | No se tocaron volúmenes ni datos de desarrollo |
| Backend | Quarkus 3.39.4, compilación release 25 | Compose ofrecía JDK 21: se ajustó la imagen a JDK 25 | Arranque nativo probado; arranque de la imagen pendiente |
| Wrapper Linux | Archivo `mvnw` versionado con modo 100644 y LF | Compose lo ejecutaba directamente: se añadió `sh` | No se cambió el wrapper ni Git filemode |
| Laravel | 13.33.0; dependencias instaladas requieren PHP >=8.4.1 | Compose ofrecía PHP 8.3: se ajustó a php:8.5-cli | PHP local 8.5.10 probado; imagen pendiente por Docker |
| Cliente PHP | URL configurable; timeout 8 s, conexión 3 s, registro 30 s; Bearer desde sesión | Query boolean false se convertía en 0, incompatible con `vigente=false`: se serializan booleanos como true/false | Cambio limitado a query strings; sin reintentos de escrituras |
| Middleware de roles | Valida sesión y rol con Quarkus antes de mostrar el inicio | Una caída/5xx/404 del verificador eliminaba la sesión local. Ahora deniega con 503, Retry-After 30 y no-store, conservando sesión para reintentar | 401, cuenta no habilitada y sesión revocada siguen cerrando sesión; 403 por rol sigue denegando |
| Límites de autenticación | Rutas numéricas throttle compartían clave por dominio/IP | Registro + OTP + login consumían el límite de recuperación. Se separaron prefijos por operación | Se conservan cantidades y ventanas; Google login/registro comparten el contador de inicio Google |
| Pantallas clínicas | Hay páginas de inicio por rol y formularios de autenticación | Los módulos de personal/recepción/agendas/sanciones aún no tienen pantallas completas | Se probaron por QuarkusApiClient; no se atribuyen a una interfaz inexistente |
| Catálogo del portal | Endpoint público configurable, no obligatorio | Las rutas existentes de especialidades son privadas ROL004/ROL002 | No se abrieron permisos ni se apuntó el portal a una ruta administrativa |

Versiones locales verificadas: Java 25.0.4.1, PHP 8.5.10, Composer 2.10.3, Node 22.21.0, npm 10.9.4, Laravel 13.33.0, Vite 8.3.5. También existe PostgreSQL 16.14 instalado; **la ejecución de esta fase utilizó 15.19**.

Las imágenes seleccionadas se contrastaron con los manifiestos oficiales de [Maven](https://github.com/docker-library/official-images/blob/master/library/maven) y [PHP](https://github.com/docker-library/official-images/blob/master/library/php). Su disponibilidad no equivale a una ejecución de contenedores, que no pudo realizarse.

## 2. PostgreSQL y Flyway

Se descargaron los binarios oficiales EDB para Windows desde la distribución enlazada por [PostgreSQL](https://www.postgresql.org/download/windows/). Clúster exclusivo en `backend_clinica/target/integration-fase9/pgdata`, conectado solamente a 127.0.0.1:35441. El usuario de prueba y las bases son independientes del entorno clínico. Se usaron cuentas sintéticas con contraseñas aleatorias, sin depender de los cuatro usuarios demo.

Bases creadas: `clinica_fase9_tests`, `clinica_fase9_live` y `clinica_fase9_live_final`; la segunda corrida clínica se hizo en una base nueva, sin borrar la primera. Las pruebas heredadas de actualización también crean bases aisladas `clinica_upgrade_*` dentro del mismo clúster.

Se aplicaron correctamente **V1, V2, V4, V5, V6, V7, V8, V9 y R__verificacion_email_otp**, nueve entradas exitosas. No hay V3 en las migraciones principales. V3 existe solamente en `db/dev-migration`; fue excluida del perfil aislado usando explícitamente `classpath:db/migration`, baseline=false y out-of-order=false. No se añadieron ni modificaron migraciones.

Las cinco pruebas de actualización desde versiones 4,5,6,7 y 8 pasaron, conservando citas, bloqueos, QR y sanciones previas según sus escenarios. El arranque de Quarkus validó las nueve migraciones y confirmó que la base final estaba en versión 9 sin cambios pendientes.

Auditoría de solo lectura sobre la base final después de los flujos:

| Comprobación | Resultado |
|---|---:|
| Restricciones PostgreSQL no validadas | 0 |
| Perfiles de paciente huérfanos | 0 |
| Citas sin paciente/slot/médico/especialidad | 0 |
| Slots con más de una cita Confirmada/En Espera | 0 |
| QR almacenados / auténticos y correspondientes a su cita y fecha | 2 / 2 |
| Cancelación / llegada / sanción con actor coincidente en bitácora | 1 / 1 / 1 |
| Vistas administrativas esperadas presentes | 2 |
| Triggers no internos / deshabilitados | 32 / 0 |

Consulta reproducible: `src/test/resources/integration-fase9-integrity.sql`, transacción READ ONLY; no imprime datos personales, QR ni hashes.

La conexión local configurada para desarrollo no estuvo disponible en la comprobación de solo lectura. No se comprobó su historial Flyway ni un respaldo, y **no se ejecutaron migraciones sobre ella**. Antes de desplegar, verificar conectividad, historial/checksums, extensiones y respaldo. La configuración habitual conserva migrate-at-start y baseline-on-migrate: no arrancar contra una base real desconocida para descubrir su estado.

## 3. Estado de Quarkus y Laravel

Quarkus inició en `127.0.0.1:18090`, perfil aislado `integration`, con PostgreSQL 15.19. `/hello` respondió 200 y los endpoints reales respondieron a Laravel. Se usó dev mode exclusivamente para el proveedor DEMO ya existente; no se relajaron sus controles. Scheduler deshabilitado en esta ejecución interactiva para mantener control de los fixtures; su concurrencia está cubierta por las pruebas backend existentes.

Laravel inició mediante PHP HTTP en `127.0.0.1:18091`, con storage, sesiones cifradas y clave efímera exclusivos. Un segundo Laravel en 18092 compartió solo esa sesión de prueba y apuntó al puerto 18099 sin servicio para provocar un fallo de conexión real. El acceso protegido devolvió 503 y al volver al servidor sano se recuperó la misma sesión.

Las cinco referencias a assets generados encontradas en el formulario de login respondieron HTTP 200. La compilación Vite final pasó. Existe un aviso previo de fontaine opcional para fallback de fuentes; no impide compilar y no se agregó una dependencia.

`docker compose config --no-interpolate --quiet` pasó. Docker Desktop devolvió que no podía iniciar: no se ejecutó el stack Compose ni se garantiza su arranque completo. Los contenedores requieren dependencias Composer y assets instalados en el proyecto montado; Composer/Node no forman parte de la imagen PHP CLI. No se cambiaron puertos, volúmenes ni credenciales.

## 4. Autenticación, autorización y errores

Se verificó login, redirección y acceso al inicio de los cuatro roles, conservación de sesión, rechazo cruzado de paneles, logout local/remoto y rechazo de token revocado/inválido. El Bearer permanece en sesión del servidor: no se añadió localStorage. Las pruebas HTTP ejercitan cookies HttpOnly, CSRF (419 sin token), acceso permitido/denegado y cierre de sesión.

Se comprobó con respuestas reales el tratamiento de 401/403/404/409/423; los tests de frontera verifican además que esos errores no se reintentan como escrituras exitosas. Un fallo de transporte o 5xx del verificador de sesión no da acceso al panel: responde 503 y permite reintentar. Un 404 del verificador se considera problema de integración de la API, mientras que el 404 de un recurso clínico se conserva como tal en el cliente.

No se cambió AuthController ni el login/OTP/OAuth del backend. El ajuste de límites de Laravel resuelve la interferencia observada entre operaciones, sin cambiar credenciales ni añadir autenticación paralela.

## 5. Inventario de APIs

Ver [inventario-api-fase9.md](inventario-api-fase9.md): métodos, rutas, roles, parámetros, cuerpos, DTO de respuesta, códigos HTTP y consumidor actual/futuro. Se contrastó con OpenAPI del servidor en ejecución: **65 operaciones, incluyendo GET /hello; ninguna ruta del documento OpenAPI quedó sin inventariar**. Los errores no están completamente descritos por OpenAPI; el inventario se completó desde controladores, servicios y DTO.

No existe todavía una ruta HTTP funcional de reserva web del paciente en ReservaCitaController. La función PostgreSQL central sí soporta canal Web y tiene pruebas de concurrencia compartida con Ventanilla. No se presentó esa prueba SQL como una pantalla web de reservas terminada.

## 6. Flujos reales comprobados

| Flujo | Ejecución y evidencia | Interfaz |
|---|---|---|
| A. Autenticación | Login de los cuatro roles, Quarkus valida PostgreSQL, Laravel conserva sesión y redirige correctamente | Formularios y páginas existentes, HTTP real |
| B. Autorización | Secretario entra a su inicio y recibe 403 al solicitar administración; equivalente con los demás roles | HTTP real; también kernel Laravel |
| C. Reserva | Buscar paciente ficticio, consultar catálogo/cupos, reservar, comprobar canal Ventanilla, secretario y consulta posterior | QuarkusApiClient real; pantalla de recepción pendiente |
| D. Disponibilidad | Exclusión de descanso, ocupación, bloqueo parcial y médico/especialidad inactivos; reactivación coherente | Cliente PHP real; reglas D+14 y concurrencia además en backend |
| E. QR/check-in | QR generado por PostgreSQL en fixture propio de cita del día; acreditación, En Espera, actor y rechazo del segundo intento | Cliente PHP real; entrega/escaneo visual pendientes |
| F. Cancelación | Cancelar cita, verificar actor/motivo, reaparición de cupo permitido y presencia en historial | Cliente PHP real; sin pantalla nueva |
| G. Sanción | Administrador aplica sanción; secretaría ve inhabilitación; reserva rechazada 403 y login sancionado 423 | Cliente PHP real; sin pantalla nueva |

El fixture de QR representa una cita histórica del día usando el generador existente; sus ajustes de fecha se limitan a datos ficticios del bootstrap, sin desactivar triggers. No se divulgó el token en URL ni en listados.

Registro público, OTP y recuperación también recorrieron las rutas Laravel y el backend reales: registro pendiente, obtención privada del código mediante el proveedor DEMO local, verificación, login, solicitud de recuperación, restablecimiento, rechazo de la sesión anterior y login con contraseña nueva. No se enviaron correos. El enlace de recuperación se analizó en memoria, sin solicitar una URL que dejara el token en el access log.

Google OAuth, validación OIDC, SMTP y manejo de recuperación tienen regresión con dobles/pruebas de persistencia. **No se ejecutó consentimiento Google real ni entrega Gmail real.** Las credenciales no fueron modificadas.

## 7. Resultados exactos de pruebas

| Ejecución | Resultado |
|---|---|
| Maven Wrapper, Java 25, PostgreSQL 15.19: `mvnw.cmd -o -DskipITs=false verify` | **261 aprobadas, 0 fallidas, 0 errores, 0 omitidas; BUILD SUCCESS** |
| Desglose Surefire | 42 unitarias + 218 con QuarkusTest = 260 |
| Desglose Failsafe | 1 prueba de aplicación empaquetada, GreetingResourceIT |
| Laravel completo, con ambos grupos reales activados | **69 casos: 64 aprobados, 5 omitidos, 0 fallos/errores; 514 aserciones** |
| Dentro de esa ejecución: LiveBackendIntegrationTest | 7 casos, 165 aserciones, todos aprobados: kernel Laravel y cliente PHP con HTTP real a Quarkus |
| Dentro de esa ejecución: LiveHttpStackTest | 5 casos, 79 aserciones, todos aprobados: HTTP contra ambos servidores y PostgreSQL |
| Dentro de esa ejecución: ApiResilienceTest | 12 casos, 47 aserciones, todos aprobados; dobles HTTP y límites reales del middleware |
| Laravel estándar sin activar infraestructura | **69 casos: 52 aprobados, 17 omitidos, 0 fallos/errores; 270 aserciones** |
| Compilación separada del bootstrap de fixtures | `mvnw.cmd -o -DskipTests test-compile`: BUILD SUCCESS |
| Composer `check-platform-reqs` | aprobado |
| Vite `npm run build` | aprobado |
| Pint, solo archivos PHP cambiados/nuevos | aprobado |
| Compose, validación estática sin interpolar secretos | aprobado; ejecución de contenedores pendiente |
| Diff Git, whitespace | sin errores |

Las cinco omisiones de la suite completa son DemoLoginIntegrationTest, opt-in heredado que depende de credenciales demo. Se sustituyó su dependencia para esta verificación por fixtures aleatorios, no se eliminaron pruebas. En la ejecución estándar se omiten además los 12 casos reales nuevos porque requieren los servidores aislados.

La primera corrida de los tests de frontera reprodujo seis fallos antes de corregir middleware/booleanos. La primera corrida real tuvo 11 éxitos y un fallo de recuperación; el contador compartido fue identificado y corregido. Los resultados finales anteriores se obtuvieron después de los cambios. No hay fallos finales conocidos en las suites ejecutadas.

Los tests backend incluyen reservas concurrentes Web/Ventanilla, dos reservas para el mismo slot, superposición de paciente, reserva/bloqueo, doble check-in, cancelación/check-in, inasistencia manual/automática y sanción/reserva. Esto no es una prueba de carga del sistema completo ni una garantía de latencia.

Evidencia local generada (no versionada): `target/fase9-backend-pg15.log`, `target/fase9-fixture-build.log`, `target/fase9-laravel-live.xml`, `target/fase9-laravel-standard.xml`, `target/fase9-integrity.log`, reportes Surefire/Failsafe y logs/OpenAPI en `target/fase9-runtime`. Los reportes antiguos que ya existían en target no se contaron como ejecución nueva; el total Maven se contrastó con el log de esta corrida.

## 8. Archivos y motivo

Modificados:

- `docker-compose.yml`: versiones de runtime compatibles y ejecución Linux del wrapper con sh.
- `frontend_clinica/app/Http/Middleware/CheckRolSession.php`: denegación recuperable cuando la API no puede validar la sesión.
- `frontend_clinica/app/Services/QuarkusApiClient.php`: booleanos HTTP compatibles; Pint también corrigió la sangría de registerPatient, sin alterar su comportamiento.
- `frontend_clinica/routes/web.php`: prefijos de rate limiting por operación; rutas y controladores conservados.

Nuevos:

- `frontend_clinica/tests/Feature/ApiResilienceTest.php`: errores de frontera, query booleana y aislamiento de límites.
- `frontend_clinica/tests/Feature/LiveBackendIntegrationTest.php`: siete escenarios aislados con backend real.
- `frontend_clinica/tests/Feature/LiveHttpStackTest.php`: cinco casos de cookies, CSRF, roles y caída real de red.
- `backend_clinica/src/test/java/org/clinica/integration/IsolatedIntegrationFixture.java`: herramienta CLI que solo admite PostgreSQL 15 en 127.0.0.1:35441, bases `clinica_fase9_live[_sufijo]` vacías y argumentos vacíos; aplica migraciones y genera datos aleatorios privados. Nunca es endpoint ni test que se ejecute automáticamente.
- `backend_clinica/src/test/resources/integration-fase9-integrity.sql`: auditoría de solo lectura con conteos sin datos sensibles.
- Los dos documentos de integración de Fase 9 en `backend_clinica/docs`.

No se cambió código de producción del backend, entidades, funciones SQL, migraciones históricas, vistas Blade, secretos ni archivos de asistentes. Las instrucciones de Boost del frontend no se ejecutaron porque la restricción explícita del usuario prohíbe regenerar configuración de herramientas de IA.

## 9. Repetición segura

Para el backend, preparar PostgreSQL 15 aislado y definir QUARKUS_DATASOURCE_JDBC_URL/USERNAME/PASSWORD hacia esa base, Flyway locations principal, baseline/out-of-order false, scheduler y compose/devservices deshabilitados; usar Java 25 y Maven Wrapper. No reutilizar una base clínica para esta suite: algunos tests crean datos y bases auxiliares.

Los tests PHP normales se ejecutan con `php artisan test --compact`. Los dos grupos reales son opt-in:

1. Crear **una base nueva vacía** `clinica_fase9_live_<sufijo>` en el clúster local reservado 35441. Compilar el bootstrap con test-compile. Ejecutarlo sin argumentos con CLINICA_TEST_JDBC_URL, CLINICA_TEST_DB_USER y CLINICA_TEST_DB_PASSWORD solo en el entorno del proceso. Su classpath es `target/test-classes;target/classes;target/quarkus-app/lib/main/*;target/quarkus-app/lib/boot/*` en Windows después de verify.
2. El bootstrap escribe `target/integration-fase9/fixture.json`, que contiene únicamente credenciales sintéticas pero sigue siendo privado. No mostrarlo ni versionarlo. Usar su demoKey/laravelKey en memoria para iniciar Quarkus integration en 18090 y Laravel en 18091, con storage/sesiones aislados, APP_DEBUG=false, correo DEMO/array y OAuth externo deshabilitado. La ejecución usa ambos servidores en loopback.
3. Iniciar otro Laravel en 18092 con la misma clave/storage de prueba y upstream 18099 deliberadamente vacío para el caso de fallo. No usar puertos ocupados por procesos ajenos.
4. En el proceso PHPUnit definir RUN_CLINICA_LIVE_INTEGRATION=1, RUN_CLINICA_HTTP_STACK=1 y CLINICA_LIVE_FIXTURE con la ruta privada. Configurar APP_KEY sin imprimirla, storage de kernel propio y caché/sesión array. Ejecutar `php vendor/phpunit/phpunit/phpunit --log-junit <ruta-privada-de-resultados>`.
5. El escenario clínico consume sus citas/QR; repetirlo con **otra base nueva**, no borrando datos ni regenerando tokens del entorno clínico. Detener solo los procesos creados por la ejecución y retirar fixture/sesiones privados al terminar.

## 10. Pendientes y recomendaciones para Fase 10

- Restablecer Docker Desktop y probar el perfil fullstack en un proyecto/volumen de pruebas; validar imágenes y arranque completo. El resultado nativo no prueba contenedores.
- Revisar respaldo e historial de la base de despliegue antes de permitir migrate-at-start. V2 contiene datos demo históricos: evaluar su presencia y accesos en despliegues reales, sin reescribir esa migración ni copiar sus credenciales a nuevas pantallas.
- Google externo y Gmail requieren una comprobación controlada separada; no afirmar entrega real a partir del proveedor DEMO.
- Implementar pantallas Laravel de administrador/secretaría sobre el inventario, con manejo explícito de 401/403/409/423/503 y sin reintentos automáticos de reservas, check-in, cancelaciones o sanciones.
- Definir política de registro presencial, entrega autorizada de QR, catálogo público, levantamiento anticipado de sanciones y reservas web del paciente antes de exponer funciones que hoy no tienen contrato HTTP/pantalla.
- Quarkus advierte sobre la propiedad antigua quarkus.http.cors y una propiedad Hibernate obsoleta. No afectan la comunicación servidor a servidor comprobada; revisar configuración en una tarea acotada antes de exposición directa desde navegador.
- Las sesiones opacas actuales viven en memoria del backend; reiniciar Quarkus las invalida y varias instancias necesitarían una política de almacenamiento/afinidad. No se cambió ese diseño.
- No se hizo revisión visual en navegador ni prueba de carga; las cinco pruebas de extremo a extremo son HTTP, no automatización visual.

## 11. Estado de Git y cierre

Se preservaron las seis eliminaciones previas de documentos y el PDF no rastreado del usuario. Los cambios de esta fase son los cuatro archivos modificados y siete nuevos enumerados arriba. No hubo cambios de rama, staging, commit, push, restauración de documentos ni modificaciones de credenciales. Se detuvieron los procesos propios de Quarkus, ambos Laravel y PostgreSQL. Se retiró exclusivamente el directorio temporal propio con clúster, binarios descargados, fixture privado y sesiones de prueba, tras comprobar la ruta y ausencia de enlaces de redirección; se conservaron los logs y resultados no sensibles indicados.

La integración nativa Laravel → Quarkus → PostgreSQL 15 y los flujos A–G quedaron verificados con las distinciones de interfaz de este informe. El despliegue Compose, la base habitual y los proveedores externos quedan explícitamente **sin verificar**. La Fase 9 se detiene aquí; no comienza Fase 10.
