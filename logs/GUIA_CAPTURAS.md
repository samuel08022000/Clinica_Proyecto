# Guía de capturas para el docente — Fase 4

Fecha de preparación: **21 de noviembre de 2026, America/La_Paz**. Las capturas siguientes están **por realizar**. Los resultados citados provienen de las ejecuciones del [informe final](AUDITORIA_FINAL.md), no de imágenes fabricadas.

Repositorio: `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica`. Aplicación: `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica`.

Son **13 capturas principales**. Se pueden colocar dos archivos lado a lado cuando se indica. No mostrar .env, cuerpos de excepciones, contraseñas, OTP, tokens, datos clínicos ni otras ventanas con información privada. Los ejemplos en tests usan información sintética. No provocar errores en servicios reales ni crear registros de demostración con Log::info manual.

Los comandos de pruebas usan el canal null solo en su proceso y restauran su valor anterior. Las comillas incluidas en `'"null"'` conservan el nombre literal del canal para el lector de entorno. No modifican el .env. Los comandos de capturas omiten --log-junit para no sobrescribir los informes ya generados.

## C01 — Carpeta documental e inventario

| Campo | Qué mostrar |
|---|---|
| Abrir | Explorador y editor, lado a lado si es posible. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\logs` y `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\logs\INVENTARIO_LOGS.md`. |
| Parte | Los documentos y las columnas del inventario: evento, nivel, archivo/método, condición, destino, estado y prueba. Elegir unas filas representativas, sin capturar toda la tabla. |
| Explicar | logs raíz documenta; storage/logs guarda ejecución privada. Hay 31 nombres nuevos, variantes de fallo y siete WARNING conservados. |

Alternativa de terminal, sin abrir registros:

~~~powershell
Get-ChildItem -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\logs' -File |
    Select-Object Name, Length
~~~

## C02 — Rotación configurada y handler activo

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor y terminal. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\config\logging.php` y `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\.env.example`. |
| Parte | Stack con daily; daily con path, LOG_LEVEL y max_files/LOG_DAILY_DAYS. En el ejemplo de entorno mostrar solo líneas LOG. |
| Explicar | El stack principal usa rotación diaria y retiene 14 archivos. El nivel es configurable. No implica que browser de Boost rote: ese canal de desarrollo sigue siendo single. |

Comando de lectura que imprime solo configuración no sensible y consulta el handler, sin emitir mensajes ni conectar con PostgreSQL:

~~~powershell
Set-Location -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica'
@'
<?php
require 'frontend_clinica/vendor/autoload.php';
$app = require 'frontend_clinica/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = $app['config'];
$handler = $app['log']->channel()->getLogger()->getHandlers()[0];
echo json_encode([
    'canal' => $c->get('logging.default'),
    'stack' => $c->get('logging.channels.stack.channels'),
    'archivos_retenidos' => $c->get('logging.channels.daily.max_files'),
    'nivel' => $c->get('logging.channels.daily.level'),
    'handler' => $handler::class,
    'archivo_resuelto' => $handler->getUrl(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
'@ | php
~~~

No usar config:show completo ni abrir el .env privado en la captura.

## C03 — INFO después del commit

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor, dos archivos lado a lado. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Administration\AdministrationService.php`, método createDoctor; y `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Clinical\ClinicalTransaction.php`, método run. |
| Parte | Declaración del evento admin.doctor.created y llamada a ClinicalLog::completed después de transaction y de los catch. |
| Explicar | La transacción debe terminar antes del INFO. ClinicalLog::event usa info por defecto; no es necesario que exista un Log::info literal. Si hay rollback no se emite el éxito. |

Si se necesita enseñar el nivel por defecto, abrir también `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Support\ClinicalLog.php`, event/completed. No requiere ejecutar una operación clínica.

## C04 — WARNING de bloqueo

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Auth\LoginAttemptService.php`. |
| Parte | ensureAllowed: límite, auth.login.blocked y nivel warning; excepción con espera. |
| Explicar | El rechazo usa una categoría controlada. No se registran correo, IP completa, contraseña o claves del caché. No se provoca un bloqueo en una cuenta real. |

Para un WARNING anterior, se puede sustituir esta captura por sendOtp en `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Auth\AuthMailService.php`: solo registra la clase del error, nunca el OTP.

## C05 — Archivo real, salida segura y exclusión Git

| Campo | Qué mostrar |
|---|---|
| Abrir | Terminal; no abrir el cuerpo completo del archivo. |
| Ruta exacta observada | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\storage\logs\laravel-2026-11-21.log`. |
| Parte | Nombre del archivo y prefijos con fecha, entorno, nivel y evento, sin JSON/contextos. |
| Explicar | El archivo ya existía al iniciar la auditoría y tenía dos INFO de autenticación. No fueron generados para esta captura. El comando elige el último diario disponible; registros posteriores pueden cambiar el contenido observado. Fechas de aplicación en UTC. |

~~~powershell
$logDirectory = 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\storage\logs'
$dailyLog = Get-ChildItem -LiteralPath $logDirectory -Filter 'laravel-????-??-??.log' -File |
    Sort-Object Name -Descending | Select-Object -First 1
if ($dailyLog) {
    $dailyLog | Select-Object Name, Length
    Get-Content -LiteralPath $dailyLog.FullName | ForEach-Object {
        if ($_ -match '^\[(?<fecha>[^\]]+)\] (?<entorno>[A-Za-z0-9_-]+)\.(?<nivel>INFO|WARNING|ERROR): (?<evento>(auth|admin|reception|doctor|patient|notification|clinical|app)\.[a-z_.]+)') {
            [pscustomobject]@{
                Fecha = $Matches['fecha']
                Entorno = $Matches['entorno']
                Nivel = $Matches['nivel']
                Evento = $Matches['evento']
            }
        }
    } | Select-Object -Last 10 | Format-Table -AutoSize
} else {
    Write-Output 'No hay archivo diario disponible. No crear un registro artificial.'
}
git -C 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica' check-ignore -v frontend_clinica/storage/logs/laravel-2026-11-21.log frontend_clinica/.env
~~~

No incluir browser.log ni contextos completos en la captura. .gitignore evita versionar archivos, no sustituye una protección HTTP o de permisos.

## C06 — Relación operación/evento comprobada por prueba

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor y resultado del caso focalizado. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Feature\ClinicalLoggingTest.php`. |
| Parte | test_real_service_emits_one_safe_info_only_after_commit, callback onWrite y un caso del proveedor mutations; relacionarlo con el evento de C03. |
| Explicar | Se ejecuta el servicio real con base ficticia; se exige INFO único, commit terminado y ausencia de valores sensibles. No es una reserva real PostgreSQL ni prueba de entrega de correo. |

Este caso ya se ejecutó dentro de las 74 pruebas. Comando para focalizarlo al hacer la captura, sin sumarlo de nuevo al total:

~~~powershell
Set-Location -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica'
$previousLogChannel = [Environment]::GetEnvironmentVariable('LOG_CHANNEL', 'Process')
try {
    $env:LOG_CHANNEL='"null"'
    php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter test_real_service_emits_one_safe_info_only_after_commit tests/Feature/ClinicalLoggingTest.php
} finally {
    [Environment]::SetEnvironmentVariable('LOG_CHANNEL', $previousLogChannel, 'Process')
}
~~~

La correlación integral de una acción del navegador con una transacción PostgreSQL sigue pendiente de demostración en entorno aislado. Los nombres de las entradas reales de C05 se pueden localizar en AuthenticationService::login/trustedLogin y SessionService::logout; no atribuirles un usuario u origen que no se haya comprobado.

## C07 — Versión de Mockery y suites PHPUnit

| Campo | Qué mostrar |
|---|---|
| Abrir | Terminal y editor. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\composer.json`, require-dev; y `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\phpunit.xml`. |
| Parte | Mockery declarado y Unit/Feature seleccionables. |
| Explicar | Mockery 1.6.15 ya estaba instalado; PHPUnit 12.5.35 ejecuta las pruebas. La suite Unit contiene 24 casos; la selección no incluye tests/Postgres. |

~~~powershell
Set-Location -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica'
php -r "require 'vendor/autoload.php'; echo 'Mockery: ', Composer\InstalledVersions::getPrettyVersion('mockery/mockery'), PHP_EOL; echo 'PHPUnit: ', Composer\InstalledVersions::getPrettyVersion('phpunit/phpunit'), PHP_EOL;"
~~~

No ejecutar composer require/install para esta evidencia.

## C08 — shouldReceive, once, with, andThrow y shouldNotReceive

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Auth\AuthenticationServiceMockeryTest.php`. |
| Parte | setUp muestra Mockery::mock; el método test_account_rejection_does_not_clear_attempts_or_call_the_guard muestra shouldReceive/once/with/andThrow y llamadas prohibidas. Se puede dividir en dos imágenes si no cabe legiblemente. |
| Explicar | AuthenticationService es real, AccountStatusService es simulado. Un rechazo debe impedir limpiar intentos e iniciar sesión. PHPUnit comprueba resultado/log; Laravel verifica expectativas y cierra Mockery en tearDown. Hay SQLite efímero real para el repositorio, no PostgreSQL. |

No describir este caso como “se simula AuthenticationService”; no se simula el sujeto probado.

## C09 — Mail::fake y mailable correcto

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor, dos archivos. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Support\MockeryIsolationTestCase.php` y `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Auth\AuthMailBoundaryTest.php`. |
| Parte | Mail::fake en la base y test_recovery_uses_one_reset_mailable_with_the_clinic_expiry_without_sending_real_mail. |
| Explicar | El fake recoge un PasswordResetMail sintético, comprueba destinatario/URL/vencimiento y que no se encole. No representa entrega SMTP. El segundo método demuestra que el rechazo OTP ocurre antes de Mail::to. El fallo SMTP OTP ya lo cubre AuthMailServiceTest existente. |

Señalar explícitamente la diferencia entre fake de Laravel y mock de fachada; no mostrar correos o enlaces reales.

## C10 — Http::fake y bloqueo de red no simulada

| Campo | Qué mostrar |
|---|---|
| Abrir | Editor, base y prueba lado a lado. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Support\MockeryIsolationTestCase.php` y `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Auth\GoogleOidcBoundaryTest.php`. |
| Parte | Http::preventStrayRequests en la base; Http::fake, respuesta desconocida y aserción unknown_error del primer método. |
| Explicar | La llamada HTTP se intercepta. Solo se permite la URL simulada; no se contacta Google real ni se piden JWKS después de ese rechazo. El cuerpo recibido no se copia al log. |

Los secretos y códigos que aparecen en el test son constantes sintéticas; no abrir la configuración OAuth real.

## C11 — Resultado Unit / Mockery específico

| Campo | Qué mostrar |
|---|---|
| Abrir | Terminal. |
| Ruta exacta | Aplicación `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica`; pruebas en tests/Unit. |
| Parte | Comando y resumen completo, sin recortar errores si aparecieran. |
| Explicar | Resultado verificado: 24 pruebas, 187 aserciones, cero fallos/errores/omitidas. Son 20 métodos expandidos por proveedores de datos, no 24 mocks directos. |

~~~powershell
Set-Location -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica'
$previousLogChannel = [Environment]::GetEnvironmentVariable('LOG_CHANNEL', 'Process')
try {
    $env:LOG_CHANNEL='"null"'
    php vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite=Unit
} finally {
    [Environment]::SetEnvironmentVariable('LOG_CHANNEL', $previousLogChannel, 'Process')
}
~~~

## C12 — Resultado Feature

| Campo | Qué mostrar |
|---|---|
| Abrir | Terminal de la aplicación. |
| Ruta exacta | `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Feature`. |
| Parte | Comando y resumen final. |
| Explicar | Resultado verificado: 432 pruebas, 3.327 aserciones y cero fallos/errores/omitidas. Incluye las 74 específicas de logging. No equivale a pruebas reales de PostgreSQL. |

~~~powershell
Set-Location -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica'
$previousLogChannel = [Environment]::GetEnvironmentVariable('LOG_CHANNEL', 'Process')
try {
    $env:LOG_CHANNEL='"null"'
    php vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite=Feature
} finally {
    [Environment]::SetEnvironmentVariable('LOG_CHANNEL', $previousLogChannel, 'Process')
}
~~~

## C13 — Resultado conjunto y comprobación de orden

| Campo | Qué mostrar |
|---|---|
| Abrir | Terminal; opcionalmente el informe JUnit ya generado. |
| Ruta exacta | Aplicación `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica`; informe `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\.local\clinical-audit\phase4-20261121\combined-random.xml`. |
| Parte | Semilla/orden y resumen total; en XML solo métricas tests/assertions/errors/failures/skipped. |
| Explicar | **456 pruebas únicas y 3.514 aserciones**, cero fallos/errores/omitidas. No sumar otra vez Unit, Feature o las 74 de logging. El orden aleatorio aprobado aporta evidencia de aislamiento para esa semilla; no certifica todas las permutaciones o el despliegue completo. |

~~~powershell
Set-Location -LiteralPath 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica'
$previousLogChannel = [Environment]::GetEnvironmentVariable('LOG_CHANNEL', 'Process')
try {
    $env:LOG_CHANNEL='"null"'
    php vendor/phpunit/phpunit/phpunit --do-not-cache-result --testsuite=Unit,Feature --order-by=random --random-order-seed=20261121
} finally {
    [Environment]::SetEnvironmentVariable('LOG_CHANNEL', $previousLogChannel, 'Process')
}
~~~

Los informes de esta auditoría también están disponibles como unit.xml, feature.xml y logging.xml en esa misma carpeta privada. No hay que generar ni copiar registros reales para presentar estos resultados.

## Qué queda pendiente de capturar o validar

Las trece evidencias no prueban por sí solas integración SQL, locks, triggers, SMTP real, entrega al destinatario, cámara/QR en dispositivos ni seguridad de producción. Un recorrido manual posterior debe usar una base desechable autorizada y datos sintéticos. No usar la base principal ni provocar caídas de Google/SMTP para obtener una imagen.

Si una nueva ejecución muestra un fallo o cambia los conteos, conservar el resultado real y actualizar el informe; no editar una captura para que coincida con los números de esta auditoría.
