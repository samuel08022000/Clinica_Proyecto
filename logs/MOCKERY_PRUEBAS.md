# Mockery y pruebas de componentes — Fase 3

Fecha: **21 de noviembre de 2026 (America/La_Paz)**. Aplicación: `frontend_clinica/`. Este documento describe código y pruebas realmente presentes; no usa README anteriores para acreditar funcionalidad.

**Revisión final de Fase 4:** se inspeccionaron los once archivos Unit y su base sin reescribirlos. Se revalidaron 24 casos Unit / 187 aserciones y 432 Feature / 3.327; el conjunto aleatorio con semilla 20261121 aprobó 456 / 3.514, sin fallos, errores u omisiones. Los detalles actuales están en [AUDITORIA_FINAL.md](AUDITORIA_FINAL.md).

La suite tiene 20 métodos que expanden 24 casos: 20 casos emplean colaboradores Mockery directos o creados por el helper compartido, uno usa un mock de la fachada Mail y tres se apoyan principalmente en Mail::fake/Http::fake y Log::spy. No corresponde presentarlos como 24 mocks directos ni como 24 pruebas puramente unitarias: cuatro casos de AuthenticationService usan SQLite en memoria. Las pruebas de autorización y coordinación tampoco certifican las mutaciones SQL. La revisión de límites y expectativas se documenta en el informe final.

## 1. ¿Qué es Mockito?

Mockito es una biblioteca de Java para sustituir colaboradores por objetos simulados durante pruebas. Permite definir qué devuelve una dependencia, provocar errores controlados y verificar si se llamó con los argumentos esperados.

No se agregó Mockito, Java ni Quarkus al proyecto. La finalidad académica de aislar colaboradores y comprobar interacciones se aplica aquí con herramientas de PHP.

## 2. ¿Qué es Mockery y por qué lo usamos?

Mockery permite crear mocks y espías en PHP. PHPUnit ejecuta los casos y comprueba resultados; Mockery comprueba las interacciones esperadas. Laravel ya integra Mockery en sus pruebas y ofrece fakes especializados para correo y HTTP.

Se verificaron `composer.json`, `composer.lock` y `Composer\InstalledVersions`:

| Componente | Declaración / versión comprobada |
|---|---|
| Mockery | `mockery/mockery:^1.6` en require-dev; lock e instalación **1.6.15** |
| PHPUnit | `phpunit/phpunit:^12.5.12`; instalación **12.5.35** |
| Laravel | Instalación y lock **13.33.0** |

No se instalaron dependencias ni se cambiaron composer.json o composer.lock. Se agregó la suite **Unit** a `phpunit.xml`, manteniendo Feature. Los tres `.test.mjs` que ya estaban en tests/Unit siguen siendo pruebas de Node; PHPUnit no los ejecuta.

Las pruebas nuevas extienden `Tests\Support\MockeryIsolationTestCase` y, a través de él, `Tests\TestCase` de Laravel. El ciclo real del framework en `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithTestCaseLifecycle.php` suma las expectativas de Mockery a las aserciones y ejecuta **Mockery::close()** al terminar cada caso. No se omite tearDown ni se agrega un segundo cierre manual.

## 3. Auditoría anterior a esta fase

Se inspeccionaron composer, phpunit.xml, tests/Unit, tests/Feature, tests/Support, servicios, contratos y consumidores actuales. Estos son mecanismos que ya existían; no se presentan como trabajo nuevo:

| Archivos / área | Dobles o mecanismos existentes |
|---|---|
| `tests/Feature/AdministracionPanelTest.php` | Mockery de AccountStatusService y AdministrationService inyectados en el contenedor; prueba controladores/vistas, no la lógica interna del servicio simulado. |
| `tests/Feature/AdministracionDashboardTest.php`, `AdministracionColasTest.php`, `BitacorasTest.php` | Mocks de estado de cuenta, DashboardService, ColaMetricaService, BitacoraService, renderer PDF; en Colas también Connection/Builder/DatabaseManager. |
| `tests/Feature/SecretariaPanelTest.php` | Mockery de ReceptionService y AccountStatusService, más partialMock de notificaciones. |
| `tests/Feature/PatientPanelTest.php` | Mocks del contenedor para PatientAppointmentManager, PatientAvailabilityProvider y PatientProfileProvider, estado de cuenta y partialMock de notificaciones. |
| `tests/Feature/ApiResilienceTest.php` y `SecurityAuditRegressionTest.php` | Mocks de servicios colaboradores y controles de acceso; no sustituyen un test del servicio que se está simulando. |
| `tests/Feature/AuthMailServiceTest.php` | Mail::fake, mocks de fachada Mail y Log::spy. Ya comprueba fallo SMTP OTP y contexto seguro. |
| `tests/Feature/GoogleAndPasswordRecoveryFlowTest.php` | Http::fake y Log::spy; ya comprueba fallo TLS sin secretos y continuidad del flujo. |
| `tests/Feature/GoogleOidcIdentityProviderTest.php` | Http::fake, bloqueo de peticiones no simuladas y JWT/JWKS sintéticos: firma, claims, rotación de claves y código rechazado. |
| `tests/Feature/AuthFlowTest.php`, `RegistrationOtpFlowTest.php`, `PortalFrontendTest.php`, `SecurityAuditRegressionTest.php` | Http::fake para impedir dependencia de red en los flujos cubiertos. |
| `tests/Feature/ClinicalLoggingTest.php` y `LoggingRotationTest.php` | Las 74 pruebas de Fase 2: dobles manuales, logger en memoria, SQLite en memoria para commits exteriores y archivos temporales para rotación. No se duplicaron ni modificaron. |
| `tests/Support/` | Fixtures sintéticos administrativos, de recepción/dashboard/bitácora y LoggingFixtures; helpers visuales y workers de concurrencia. No todos son mocks de Mockery. |
| `tests/Postgres/AuthenticationMigrationTest.php` | Mail::fake, Http::fake, Log::spy y sustitución del proveedor de identidad mediante contrato, junto con PostgreSQL de integración. Solo inspeccionada. |
| Otros `tests/Postgres/*MigrationTest.php` y `EndToEndClinicWorkflowTest.php` | Fakes HTTP y, en el recorrido completo, correo; acompañan operaciones reales de integración. No se ejecutaron en esta fase. |

Por búsqueda del código anterior: **8 archivos Feature** usaban Mockery::mock directamente; **6** usaban Http::fake; **1** usaba Mail::fake y **2** Log::spy. Los helpers de contenedor `$this->mock` / `partialMock` y las fachadas también emplean Mockery aunque no aparezca una llamada literal Mockery::mock. Estas categorías se superponen y no son cantidades de casos ejecutados.

No se volvieron a crear pruebas equivalentes de fallo SMTP OTP, fallo TLS de Google, firma/claims válidos, los 28 commits correctos/28 fallidos o rotación. Se conservaron y ejecutaron como regresión. La ampliación agrega interacciones negativas, orden entre colaboradores y límites no comprobados por esos casos.

## 4. ¿Qué componentes simulamos ahora?

Todos los caminos de la siguiente tabla parten de `frontend_clinica/`. El componente de la segunda columna se ejecuta realmente: no se convierte en mock ni se sustituyen sus métodos privados.

| Módulo | Clase probada | Dependencia simulada | Archivo de prueba | Escenario y aporte adicional |
|---|---|---|---|---|
| Autenticación | `AuthenticationService` | AccountStatusService, RateLimiter y AuthManager/StatefulGuard mediante Mockery; SessionService real | `tests/Unit/Auth/AuthenticationServiceMockeryTest.php` | Resultado de cuenta aceptado, rechazo de cuenta, contraseña inválida y bloqueo; verificar orden, llamadas prohibidas, ausencia de consulta tras bloqueo y logs exactos. |
| Intentos | `LoginAttemptService` | RateLimiter con Mockery | `tests/Unit/Auth/LoginAttemptServiceMockeryTest.php` | Límite agregado solo después de permitir el par, Retry-After simulado y actualización de dos contadores distintos sin identidad en texto plano. |
| Sesiones | `SessionService` | AuthManager, StatefulGuard y DatabaseManager con Mockery | `tests/Unit/Auth/SessionServiceMockeryTest.php` | Fallo del guard al cerrar sesión: conserva la excepción y no invalida la sesión ni emite éxito anticipado. |
| Google | `GoogleOidcIdentityProvider` | Http::fake y Log::spy | `tests/Unit/Auth/GoogleOidcBoundaryTest.php` | Error desconocido reducido a unknown_error; ID token de tipo incorrecto rechazado antes de consultar JWKS. No hay conexiones reales. |
| Correo | `AuthMailService` | Mail::fake y mock de fachada Mail; Log::spy | `tests/Unit/Auth/AuthMailBoundaryTest.php` | Mailable de recuperación correcto con vencimiento clínico; transporte OTP rechazado antes de invocar Mail::to. El fallo SMTP OTP ya lo cubría AuthMailServiceTest. |
| Administrador | `AdministrationService` y guard de rol real | AuthManager/StatefulGuard, DatabaseManager y ClinicalActorProvider con Mockery | `tests/Unit/Administration/AdministrationServiceMockeryTest.php` | Una identidad no administradora no puede abrir la conexión ni iniciar la creación de médico. No afirma probar el alta SQL. |
| Secretaría | `ReceptionService` y guard real | AuthManager/StatefulGuard, DatabaseManager y ClinicalActorProvider con Mockery | `tests/Unit/Reception/ReceptionServiceMockeryTest.php` | Rechazo por rol y fallo técnico del guard, sin conexión/actor transaccional; WARNING/ERROR seguros sin éxito. |
| Médico | `MedicoAtencionService`, `MedicoDerivacionService`, `MedicoAnalisisService` reales | Colaboradores de identidad, base y actor mediante Mockery | `tests/Unit/Medico/MedicalServicesMockeryTest.php` | Atención, derivación y solicitud requieren rol médico antes de SQL; un estado de análisis inválido se rechaza incluso antes de resolver identidad. |
| Paciente | `PatientAppointmentService` real | Colaboradores de identidad, base y actor mediante Mockery | `tests/Unit/Paciente/PatientAppointmentServiceMockeryTest.php` | Reserva, cancelación y reprogramación no pueden llegar a transacción/SQL con un rol distinto de paciente. |
| Notificaciones | `AppointmentNotificationService` real | DatabaseManager y AuthManager con Mockery; guard de rol real | `tests/Unit/Paciente/AppointmentNotificationServiceMockeryTest.php` | process rechaza el transporte log antes de abrir conexión, consumir outbox o intentar correo. No se simulan consultas SQL. |
| Paciente: coordinación | `AppointmentController` y `NotificationController` reales | PatientAppointmentManager, PatientAvailabilityProvider y AppointmentNotificationService con Mockery | `tests/Unit/Paciente/AppointmentOrchestrationMockeryTest.php` | Si falla la reprogramación o se rechaza el reintento, no se llama a envío ni se anuncia éxito. Son pruebas del controlador, no del servicio simulado. |
| Paciente: HTTP existente ampliado | `AppointmentController` real | Mocks de contrato y notificaciones ya existentes | `tests/Feature/PatientPanelTest.php` | La reprogramación debe preceder al aviso; el envío recibe exactamente el código nuevo devuelto por el colaborador. |
| Secretaría: HTTP existente ampliado | Controladores de recepción reales | Mock/partialMock ya existentes de ReceptionService y notificaciones | `tests/Feature/SecretariaPanelTest.php` | En los casos existentes de formulario inválido y respuesta de escritura inesperada, se prohíben ambos métodos de envío. No se agregan copias de esos casos. |

**Aclaración sobre autenticación:** AuthAccountRepository es final y usa Eloquent directamente. No se falsifica ni se usa un bypass de final: lee una tabla mínima de usuarios sintéticos en **SQLite :memory:**. Se simula AccountStatusService y se usan los servicios reales de intentos/sesión con sus colaboradores sustituibles. Por eso estos cuatro casos son pruebas de componente con persistencia efímera, no pruebas puramente unitarias sin base de datos.

**Aclaración sobre servicios clínicos:** sus pruebas nuevas cubren el corte previo a la operación y sus interacciones. Las mutaciones SQL exitosas, restricciones de propiedad, concurrencia y triggers no se reemplazan por respuestas inventadas de Mockery. Mantienen la cobertura previa y requieren integración PostgreSQL aislada para certificar su comportamiento completo.

## 5. Cómo funcionan: ejemplos reales

### Ejemplo A — El bloqueo debe detener el flujo antes de consultar la cuenta

Archivo: `tests/Unit/Auth/AuthenticationServiceMockeryTest.php`, método `test_mocked_rate_limit_stops_before_any_account_lookup_or_guard_call`.

El AuthenticationService es real. RateLimiter es el colaborador simulado; se configura que el par ya alcanzó su límite y que faltan 37 segundos. Se espera la excepción de dominio, ninguna consulta a la cuenta y ninguna llamada al guard.

~~~php
$this->limiter->shouldReceive('tooManyAttempts')->once()
    ->with(Mockery::type('string'), 5)->andReturnTrue();
$this->limiter->shouldReceive('availableIn')->once()
    ->with(Mockery::type('string'))->andReturn(37);
$this->status->shouldNotReceive('requireActive');
$this->auth->shouldNotReceive('guard');
~~~

PHPUnit comprueba código/espera y que el query log SQLite siga vacío. Log::spy verifica auth.login.blocked con categoría segura, sin un auth.login.failed adicional. Mockery valida al cerrar que las llamadas obligatorias ocurrieron y las prohibidas no ocurrieron.

### Ejemplo B — Una respuesta de cuenta rechazada no permite iniciar sesión

Archivo: `tests/Unit/Auth/AuthenticationServiceMockeryTest.php`, método `test_account_rejection_does_not_clear_attempts_or_call_the_guard`.

~~~php
$this->status->shouldReceive('requireActive')->once()
    ->with('USU000004')->andThrow($error);
$this->limiter->shouldNotReceive('clear');
$this->auth->shouldNotReceive('guard');
~~~

La contraseña sintética se valida con el repositorio efímero real. El colaborador de estado rechaza la cuenta. Se exige la misma excepción, ninguna autoridad de sesión, WARNING con solo el código permitido y ausencia de INFO. Esto agrega control de interacciones a la cobertura previa de resultados/logs.

### Ejemplo C — No notificar antes de tener el código nuevo

Archivo: `tests/Feature/PatientPanelTest.php`, método existente `test_patient_can_search_and_confirm_a_reschedule`.

Las expectativas de `reschedule` y `sendConfirmation` usan `globally()->ordered()`. El segundo método debe recibir `CIT0005`, el código sintético nuevo que devuelve el primero, no el código anterior. Si se envía antes o con otro código, Mockery falla. La prueba HTTP original conserva sus validaciones y redirección.

### Ejemplo D — Un error técnico del guard no se convierte en éxito

Archivo: `tests/Unit/Reception/ReceptionServiceMockeryTest.php`, método `test_guard_outage_preserves_failure_and_never_reaches_clinical_collaborators`.

El guard simulado lanza RuntimeException. ReceptionService es real: propaga esa instancia y registra reception.operation.failed con clase/categoría/estado. DatabaseManager y ClinicalActorProvider tienen llamadas prohibidas. El contexto exacto esperado no incluye el mensaje del error ni el QR sintético recibido.

### Diferencia entre las herramientas

| Herramienta | Qué hace aquí |
|---|---|
| `Mockery::mock` | Simula un colaborador concreto o interfaz y verifica argumentos, cantidad, orden y ausencia de llamadas. |
| `$this->mock / partialMock` | Helpers de Laravel, ya usados en Feature, que crean/registran mocks de Mockery en el contenedor. Los nuevos servicios bajo prueba no son partial mocks. |
| `Mail::fake` | Recoge mailables sin enviarlos; permite assertSent, assertSentCount y assertNothingQueued. No simula un fallo SMTP por sí solo. |
| `Mail::shouldReceive` | Mock de fachada basado en Mockery; en el caso nuevo exige que no se llegue a to() tras una configuración OTP inválida. |
| `Http::fake` | Devuelve respuestas HTTP sintéticas; preventStrayRequests bloquea URLs que no se hayan simulado. assertSent cuenta solicitudes interceptadas, no tráfico real. |
| `Log::spy` | Espía de fachada basado en Mockery: captura llamadas para verificarlas después sin escribir en logs reales. |
| Dobles manuales de Fase 2 | Siguen probando logs/transacciones y no se renombran como Mockery. |

## 6. Ejecución en Windows PowerShell

Desde la raíz del repositorio:

~~~powershell
Set-Location .\frontend_clinica

# Primero, únicamente los PHP nuevos de Mockery/componentes.
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite=Unit

# Después, regresión explícita de Unit y Feature; excluye tests/Postgres.
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite=Unit,Feature

# Si se desea revisar por separado las 74 pruebas de logging ya existentes:
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/ClinicalLoggingTest.php tests/Feature/LoggingRotationTest.php

# Estilo de los archivos PHP nuevos.
php vendor/laravel/pint/builds/pint --test tests/Unit tests/Support/MockeryIsolationTestCase.php
~~~

En la ejecución de regresión se usó además `$env:LOG_CHANNEL='"null"'` solo en ese proceso, para descartar logs generales y proteger los históricos. En una terminal reutilizada, guardar y restaurar el valor anterior al terminar; no se cambia el .env de la aplicación. Las comillas incluidas en el valor conservan el nombre literal del canal null para el lector de entorno.

Los tests nuevos ya configuran el canal null en memoria y usan Log::spy. Su base exige ambiente testing, reemplaza clinical por SQLite :memory:, activa Mail::fake y bloquea HTTP no simulado. No ejecutan los scripts de integración ni activan CLINICAL_PG_TESTS.

## 7. Resultados y verificaciones

El informe final [JUnit privado](../.local/clinical-audit/mockery-phase3-20261121.junit.xml), generado por PHPUnit, contiene:

| Selección final | Casos ejecutados | Aserciones | Fallos | Errores | Omitidos |
|---|---:|---:|---:|---:|---:|
| Unit: pruebas nuevas | **24** | **187** | 0 | 0 | 0 |
| Feature: pruebas existentes, incluidas las ampliadas | **432** | **3.327** | 0 | 0 | 0 |
| Total Unit + Feature | **456** | **3.514** | **0** | **0** | **0** |

Todas pasaron. La primera selección de Unit ejecutó 23 casos con 179 aserciones; después se agregó y ejecutó el caso de prevalidación de notificaciones (1 caso, 8 aserciones). La regresión final ejecutó juntos los 24 nuevos y los 432 Feature. El número de Feature sigue siendo 432: las interacciones adicionales se incorporaron a casos existentes, sin duplicarlos. Sus aserciones aumentaron de 3.308 a 3.327.

Comando final realmente ejecutado desde frontend_clinica:

~~~powershell
$env:LOG_CHANNEL='"null"'
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite=Unit,Feature --log-junit '../.local/clinical-audit/mockery-phase3-20261121.junit.xml'
~~~

El intento anterior de invocar PHPUnit sin filtro fue rechazado por la revisión automática de permisos por riesgo de incluir integración. No se ejecutó ese intento. Se revisó phpunit.xml y el soporte de filtros del PHPUnit instalado; la ejecución autorizada seleccionó explícitamente Unit,Feature. No quedó ninguna ejecución necesaria bloqueada.

Se usó ejecución fuera del sandbox para PHPUnit/Pint por las comprobaciones de lectura/escritura de Windows ya observadas en las fases anteriores. No se cambiaron ACL o dependencias. El JUnit está en .local/clinical-audit, una ruta privada ya ignorada por Git; no es un archivo de ejecución de Laravel copiado a logs.

**Preservación por SHA-256:** se tomaron hashes antes de modificar archivos. Al comparar se conservaron app, config, routes, bootstrap/app.php, .env, composer.json/lock, los SQL y las pruebas/documentos de las fases anteriores, salvo los tres archivos autorizados de configuración/testing ampliados: phpunit.xml, PatientPanelTest.php y SecretariaPanelTest.php. ClinicalLog, ClinicalTransaction y las 74 pruebas de Fase 2 quedaron intactos.

Se compararon **43 archivos existentes en storage/logs**: 42 conservaron su contenido, incluido laravel.log, y no aparecieron nuevos archivos allí. **browser.log cambió durante el intervalo observado**. Se verificó que el Boost instalado escribe ese archivo mediante un canal independiente y se deshabilita en runningUnitTests; no se identifica aquí qué proceso produjo el cambio. No se borró, truncó ni restauró el archivo para ocultar esa actividad. No se certifica inmutabilidad de browser.log ni se atribuye el cambio a estas pruebas.

Se comprobó la sintaxis de los 14 PHP nuevos/ampliados y Pint --test pasó para los 12 PHP nuevos. La validación de UTF-8, bloques de código, enlaces, rutas de fuente y ausencia de marcadores pendientes del documento terminó sin errores. phpunit.xml es XML válido y git diff --check no detectó errores en los tres archivos existentes ampliados.

No se afirma cobertura de código porcentual: no se generó un informe de cobertura ni se instaló un driver para ello. Tampoco se suman resultados de ejecuciones repetidas para inflar el número de casos.


### Archivos creados y modificados en esta fase

| Ruta exacta desde la raíz | Finalidad |
|---|---|
| `frontend_clinica/phpunit.xml` | Agregar descubrimiento de Unit; conservar Feature y su configuración aislada. |
| `frontend_clinica/tests/Support/MockeryIsolationTestCase.php` | Base de aislamiento: ambiente testing, SQLite efímero, HTTP bloqueado, correo fake y espía de logs; helpers sin simular el sujeto probado. |
| `frontend_clinica/tests/Unit/Auth/AuthenticationServiceMockeryTest.php` | Cuatro pruebas del servicio real con colaboradores Mockery y repositorio SQLite real. |
| `frontend_clinica/tests/Unit/Auth/LoginAttemptServiceMockeryTest.php` | Dos pruebas de interacciones con RateLimiter. |
| `frontend_clinica/tests/Unit/Auth/SessionServiceMockeryTest.php` | Fallo simulado del guard antes de invalidación/logout exitoso. |
| `frontend_clinica/tests/Unit/Auth/GoogleOidcBoundaryTest.php` | Dos límites de respuestas de Google no cubiertos por los casos previos, con Http::fake. |
| `frontend_clinica/tests/Unit/Auth/AuthMailBoundaryTest.php` | Dos pruebas de mailable de recuperación y corte previo de OTP. |
| `frontend_clinica/tests/Unit/Administration/AdministrationServiceMockeryTest.php` | Prohibir conexión/actor ante identidad no administradora. |
| `frontend_clinica/tests/Unit/Reception/ReceptionServiceMockeryTest.php` | Rechazo y fallo técnico del colaborador de identidad antes de operar. |
| `frontend_clinica/tests/Unit/Medico/MedicalServicesMockeryTest.php` | Tres entradas médicas protegidas y validación de estado antes de identidad. |
| `frontend_clinica/tests/Unit/Paciente/PatientAppointmentServiceMockeryTest.php` | Tres entradas del paciente protegidas antes de transacción. |
| `frontend_clinica/tests/Unit/Paciente/AppointmentNotificationServiceMockeryTest.php` | Transporte no permitido impide consumir la outbox o enviar. |
| `frontend_clinica/tests/Unit/Paciente/AppointmentOrchestrationMockeryTest.php` | Dos cortes de coordinación que prohíben notificaciones posteriores. |
| `frontend_clinica/tests/Feature/PatientPanelTest.php` | Fortalecer el caso existente de reprogramación con orden global y argumento del aviso. |
| `frontend_clinica/tests/Feature/SecretariaPanelTest.php` | Agregar expectativas negativas de envío a casos existentes de validación y respuesta inesperada. |
| `logs/MOCKERY_PRUEBAS.md` | Esta auditoría, explicación para el docente, resultados y límites. |
| `.local/clinical-audit/mockery-phase3-20261121.junit.xml` | Artefacto privado de verificación, ignorado por Git; métricas reales de la ejecución final. |

Los 16 archivos de código de pruebas/configuración/documentación anteriores al JUnit constituyen el cambio revisable de la fase; el reporte privado es evidencia adicional. Los cambios de aplicación que ya estaban en el árbol de trabajo pertenecen a fases/tareas anteriores y se conservaron.

## 8. Límites y operaciones que requieren integración real

- AdministrationService usa DatabaseManager/SQL directamente y colaboradores final: ClinicalTransaction, ClinicalRoleGuard y SessionService. Altas/ediciones de médicos y secretarías, estados, especialidades, horarios y bloqueos no se aíslan con un repositorio intercambiable actual. No se introdujeron mocks fluent de SQL, eliminación de final, alias/overload ni refactorizaciones para simular esa lógica. La nueva prueba sí comprueba que una identidad no autorizada no alcanza esos colaboradores.
- ReceptionService delega reserva, check-in, cancelación, reprogramación e inasistencia a funciones/consultas PostgreSQL dentro de ClinicalTransaction final. La nueva cobertura de Mockery verifica el corte previo y el fallo del colaborador de identidad; la cobertura HTTP anterior, reforzada, verifica coordinación/validación. No acredita transiciones SQL reales.
- Los tres servicios médicos y PatientAppointmentService también usan SQL y colaboradores final. Se prueban precondiciones e interacciones con los límites sustituibles. Propiedad, disponibilidad, idempotencia SQL, bloqueo de agenda y concurrencia deben comprobarse en una base PostgreSQL desechable, nunca en la principal.
- AppointmentNotificationService se ejecuta realmente en el nuevo caso de prevalidación de transporte, con DatabaseManager que prohíbe conexión. También se simula como colaborador de controladores. Su procesamiento completo depende de outbox, funciones SQL, locks y estado de citas; las pruebas previas con dobles manuales y Mail::fake no acreditan entrega SMTP real ni entrega al buzón.
- Google HTTP es totalmente simulado. Las pruebas no certifican disponibilidad de Google, credenciales reales, conectividad TLS del despliegue ni entrega de correos.
- Las 74 pruebas anteriores de logging se conservaron: no se modificaron ClinicalLog, ClinicalTransaction, LoggingFixtures o sus tests para facilitar estos mocks.
- El cierre de Mockery verifica las expectativas registradas; no sustituye a verificar reglas de negocio o efectos reales en una integración.

## 9. Evidencias para mostrar al docente

Las siguientes capturas están por realizar; no se inventaron imágenes de ejecución:

1. `composer.json`: require-dev de Mockery; versión instalada/lock, sin mostrar .env o credenciales.
2. `phpunit.xml`: suites Unit y Feature; explicar que tests/Postgres queda fuera de esta ejecución.
3. `AuthenticationServiceMockeryTest.php`: once/with/andReturn/andThrow y llamadas prohibidas; aclarar el pequeño repositorio SQLite real.
4. `LoginAttemptServiceMockeryTest.php`: simulación de RateLimiter y Retry-After, sin revelar identidad en los contadores.
5. `ReceptionServiceMockeryTest.php`: excepción simulada, ninguna conexión/actor transaccional y ERROR con contexto técnico cerrado.
6. `AppointmentOrchestrationMockeryTest.php`: un error evita sendConfirmation; el controlador es real, el colaborador es el mock.
7. `AuthMailBoundaryTest.php` y `GoogleOidcBoundaryTest.php`: Mail::fake/Http::fake frente a un mock/spy de fachada; explicar que una solicitud interceptada no es una conexión real.
8. `PatientPanelTest.php`: globally()->ordered() y el código nuevo; `SecretariaPanelTest.php`: shouldNotReceive en casos ya existentes.
9. Terminal con los comandos y conteos finales comprobados de la sección anterior. No mostrar registros privados, cuerpos de correo, tokens, OTP o datos clínicos reales.

Fase 3 limitada a pruebas, configuración de su descubrimiento y documentación. No se hizo commit, push, merge ni cambio de rama, ni se avanza automáticamente a otra fase.
