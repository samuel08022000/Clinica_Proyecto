# Presentación docente — Logs y Mockery

Preparada el 21 de noviembre de 2026 para el Sistema de Gestión de Citas Médicas.
Aplicación: `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica`.
Las líneas indicadas se verificaron en el árbol de trabajo actual. Esta fase
prepara **13 capturas**; no tomó imágenes, no ejecutó operaciones funcionales y
no repitió las pruebas ni generó registros de demostración.

## Qué explicar al comenzar

Un **log** registra cuándo ocurrió una operación, su resultado y un contexto
técnico limitado. Ayuda a investigar accesos, reservas, recepción, atención y
notificaciones. No sustituye al expediente clínico ni a la bitácora PostgreSQL.
La carpeta raíz `logs/` contiene documentación; los archivos de ejecución viven
en `frontend_clinica/storage/logs/`.

**Mockery** crea colaboradores simulados para comprobar cómo los usa un componente
real. Permite verificar llamadas, argumentos, cantidades y fallos controlados.
Los fakes `Mail::fake()` y `Http::fake()` son herramientas de Laravel: complementan
a Mockery, pero no deben presentarse como llamadas directas de Mockery.

Estado más reciente acreditado: Mockery **1.6.15**; **31 nombres nuevos en 40 puntos
de emisión**, además de variantes; **siete WARNING anteriores**; rotación diaria
principal y browser, con retención de **14 archivos diarios**.

| Resultado disponible, ejecución completa de Fase 5 | Pruebas | Aserciones | Fallos / errores / omitidos |
|---|---:|---:|---|
| Unit PHP | 24 | 187 | 0 / 0 / 0 |
| Feature | 434 | 3.346 | 0 / 0 / 0 |
| **Total único** | **458** | **3.533** | **0 / 0 / 0** |

Estos resultados se volvieron a consultar en el JUnit de Fase 5; **no son una
nueva ejecución de Fase 6**. Los resultados 456 / 3.514 pertenecen a la ejecución
anterior a las dos pruebas browser. El subconjunto anterior de logging es
74 / 1.075 y ya está incluido en Feature. No sumar otra vez los subconjuntos.
Los archivos `.mjs` de Unit no forman parte de estos conteos PHPUnit.

## Preparación de la terminal

Ejecutar primero este bloque de definiciones en PowerShell. Define rutas y una
función de lectura; no ejecuta pruebas ni escribe logs. Los comandos de cada
captura usan estas variables y `Mostrar-Lineas`.

```powershell
$proyecto = 'C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica'
$app = Join-Path $proyecto 'frontend_clinica'
$documentacion = Join-Path $proyecto 'logs'
$informeJUnit = Join-Path $proyecto '.local\clinical-audit\phase5-20261121\regression.xml'

function Mostrar-Lineas {
    param(
        [Parameter(Mandatory)][string]$Archivo,
        [Parameter(Mandatory)][int]$Desde,
        [Parameter(Mandatory)][int]$Hasta
    )
    $contenido = [IO.File]::ReadAllLines($Archivo)
    if ($Desde -lt 1 -or $Hasta -lt $Desde -or $Hasta -gt $contenido.Length) {
        throw 'El rango ya no coincide con el archivo; revisar la guía.'
    }
    $Archivo
    for ($numero = $Desde; $numero -le $Hasta; $numero++) {
        '{0,4}: {1}' -f $numero, $contenido[$numero - 1]
    }
}
```

No abrir `.env`, credenciales privadas, sesiones, `browser.log`, `laravel.log`
histórico ni el archivo diario completo para una captura pública. Mostrar
únicamente los rangos y las salidas limitadas indicados abajo. Las pruebas
seleccionadas usan fixtures; no sustituirlos por cuentas o datos de pacientes.

## Captura 01 — Documentación y ubicación de los registros

- **Ruta:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\logs\`.
- **Archivo/pantalla:** listado de la carpeta y `README.md`, líneas **5–14**.
- **Parte a mostrar:** definición de log y separación entre documentación y registros.
- **Comando:**

```powershell
Get-ChildItem -LiteralPath $documentacion -Filter '*.md' -File | Select-Object Name
Mostrar-Lineas -Archivo (Join-Path $documentacion 'README.md') -Desde 5 -Hasta 14
```

- **Demuestra:** la carpeta documental existe y no almacena copias de logs privados.
- **Para exponer:** «Aquí está la explicación técnica. Los registros reales se
  guardan dentro de storage, fuera de la carpeta pública».

## Captura 02 — Inventario de operaciones implementadas

- **Ruta/archivo:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\logs\INVENTARIO_LOGS.md`.
- **Líneas:** **17–20**, **28**, **41–42**, **58–60** y **76–86**.
- **Parte a mostrar:** filas de acceso, alta de Secretaría, reserva y check-in;
  total de nombres; sección que enumera los siete WARNING conservados.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $documentacion 'INVENTARIO_LOGS.md') -Desde 17 -Hasta 20
Mostrar-Lineas -Archivo (Join-Path $documentacion 'INVENTARIO_LOGS.md') -Desde 28 -Hasta 28
Mostrar-Lineas -Archivo (Join-Path $documentacion 'INVENTARIO_LOGS.md') -Desde 41 -Hasta 42
Mostrar-Lineas -Archivo (Join-Path $documentacion 'INVENTARIO_LOGS.md') -Desde 58 -Hasta 60
Mostrar-Lineas -Archivo (Join-Path $documentacion 'INVENTARIO_LOGS.md') -Desde 76 -Hasta 86
```

- **Demuestra:** nombre, nivel, emisor, condición y prueba asociada; implementación
  no equivale a afirmar que se ejecutó cada operación en la clínica real.
- **Para exponer:** «El inventario conecta cada evento con el código que lo emite.
  Los nombres repetidos en métodos distintos no aumentan el total de 31».

## Captura 03 — INFO de acceso confirmado y filtrado de contexto

- **Rutas/archivos:**
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Auth\AuthenticationService.php`,
  líneas **52–58**;
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Support\ClinicalLog.php`,
  líneas **18–21**, **25–38** y **45–46**.
- **Parte a mostrar:** `auth.login.success`, nivel `info` predeterminado, lista
  permitida de contexto y llamada final `Log::log`.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'app\Services\Auth\AuthenticationService.php') -Desde 52 -Hasta 58
Mostrar-Lineas -Archivo (Join-Path $app 'app\Support\ClinicalLog.php') -Desde 18 -Hasta 21
Mostrar-Lineas -Archivo (Join-Path $app 'app\Support\ClinicalLog.php') -Desde 25 -Hasta 38
Mostrar-Lineas -Archivo (Join-Path $app 'app\Support\ClinicalLog.php') -Desde 45 -Hasta 46
```

- **Demuestra:** el éxito se registra después de establecer la sesión; el helper
  filtra claves y valores. Los identificadores técnicos también requieren protección.
- **Para exponer:** «INFO confirma que se completó el acceso. El log recibe códigos
  técnicos controlados, no la contraseña ni el formulario».

## Captura 04 — WARNING nuevo y advertencia anterior conservada

- **Rutas/archivos:**
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Auth\AuthenticationService.php`,
  líneas **41–50**;
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Auth\AuthMailService.php`,
  líneas **29–34**, especialmente **32**.
- **Parte a mostrar:** `auth.login.failed` y el WARNING previo del rechazo del transporte.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'app\Services\Auth\AuthenticationService.php') -Desde 41 -Hasta 50
Mostrar-Lineas -Archivo (Join-Path $app 'app\Services\Auth\AuthMailService.php') -Desde 29 -Hasta 34
```

- **Demuestra:** un rechazo controlado produce WARNING; el código muestra variables,
  no valores de contraseña u OTP. La advertencia previa conserva su mensaje.
- **Para exponer:** «Una advertencia permite investigar un rechazo o fallo recuperable
  sin anunciar un éxito que no ocurrió». No provocar intentos fallidos para la captura.

## Captura 05 — Operación funcional de la clínica y confirmación transaccional

- **Rutas/archivos:**
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Administration\AdministrationService.php`,
  líneas **284–294**, evento en **291**;
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\app\Services\Clinical\ClinicalTransaction.php`,
  líneas **50–63**.
- **Parte a mostrar:** alta de Secretaría mediante `transactions->run`, nombre
  `admin.secretary.created` y emisión después de finalizar la transacción.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'app\Services\Administration\AdministrationService.php') -Desde 284 -Hasta 294
Mostrar-Lineas -Archivo (Join-Path $app 'app\Services\Clinical\ClinicalTransaction.php') -Desde 50 -Hasta 63
```

- **Demuestra:** el evento identifica una operación administrativa real de la clínica;
  un fallo transaccional sigue la ruta de error y no emite ese éxito.
- **Para exponer:** «El nombre se declara en el servicio y el helper lo emite después
  del commit. En la siguiente captura veremos una entrada que ya existía».
  No ejecutar el SQL mostrado ni crear otra cuenta.

## Captura 06 — Rotación diaria principal y browser

- **Ruta/archivo:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\config\logging.php`.
- **Líneas:** **55–59**, **68–85**.
- **Parte a mostrar:** stack daily, `max_files`, canal browser daily y su retención.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'config\logging.php') -Desde 55 -Hasta 59
Mostrar-Lineas -Archivo (Join-Path $app 'config\logging.php') -Desde 68 -Hasta 85
```

- **Demuestra:** ambos flujos admiten archivos diarios. La configuración efectiva
  comprobada en Fase 5 está en `REVISION_BROWSER_FASE5.md`, líneas **23–30**.
- **Para exponer:** «Se conservan hasta 14 archivos diarios por flujo. No significa
  borrado exacto a los catorce días sin actividad. La rotación de browser no elimina
  su riesgo de registrar mensajes libres o URLs sensibles».

## Captura 07 — Archivo diario existente y evento real sin contexto privado

- **Ruta/archivo:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\storage\logs\laravel-2026-11-21.log`.
- **Líneas originales verificadas:** **2**, `auth.login.success`; **4**,
  `admin.secretary.created`; **29**, `auth.login.blocked`.
- **Pantalla:** terminal con metadatos del archivo y proyección de prefijos. **No
  abrir el archivo completo en el editor**: contiene otras entradas no seleccionadas.
- **Comando seguro de consulta:**

```powershell
$archivoDiario = Join-Path $app 'storage\logs\laravel-2026-11-21.log'
if (Test-Path -LiteralPath $archivoDiario) {
    Get-Item -LiteralPath $archivoDiario | Select-Object Name, Length, LastWriteTime
    $lineasLog = [IO.File]::ReadAllLines($archivoDiario)
    foreach ($numero in @(2, 4, 29)) {
        if ($numero -gt $lineasLog.Length) {
            Write-Output "Pendiente: ya no existe la linea $numero."
            continue
        }
        $prefijo = [regex]::Match($lineasLog[$numero - 1],
            '^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] local\.(INFO|WARNING): (auth\.login\.success|admin\.secretary\.created|auth\.login\.blocked)(?:\s|$)')
        if ($prefijo.Success) {
            [pscustomobject]@{
                Linea = $numero
                Fecha = $prefijo.Groups[1].Value
                Nivel = $prefijo.Groups[2].Value
                Evento = $prefijo.Groups[3].Value
            }
        } else {
            Write-Output "Pendiente: revisar en privado la linea $numero; no se muestra su contenido."
        }
    }
} else {
    Write-Output 'Pendiente: no esta disponible el archivo diario verificado.'
}
```

- **Demuestra:** hay registros reales previos; no son una línea inventada ni una
  salida fabricada por un test. El alta corresponde a la cuenta de prueba autorizada
  creada durante la revisión visual anterior. En Fase 6 solo se leyó el archivo.
- **Para exponer:** «La línea 4 confirma una operación que ya ocurrió. La salida
  conserva fecha, nivel y evento, y omite íntegramente contexto, usuarios y entidades».
- **Seguridad:** el contexto de esa entrada se validó en memoria como `action`,
  `actor_id` y `entity_id` con formatos técnicos permitidos. Sus valores no se
  publican. La proyección no demuestra ausencia de datos sensibles en todo el
  archivo. El timestamp se mantiene como está escrito en el log, sin convertirlo
  a hora clínica. Si deja de estar disponible, marcar pendiente; no generar otra entrada.

## Captura 08 — shouldReceive, once, with y llamadas prohibidas

- **Ruta/archivo:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Auth\LoginAttemptServiceMockeryTest.php`.
- **Líneas:** **14–30**, especialmente **16–21**.
- **Parte a mostrar:** creación del mock, expectativas del limitador y ejecución real.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'tests\Unit\Auth\LoginAttemptServiceMockeryTest.php') -Desde 14 -Hasta 30
```

- **Componente real:** `LoginAttemptService`, ejecutado en línea **23**.
- **Dependencia simulada:** `Illuminate\Cache\RateLimiter`.
- **Demuestra:** `shouldReceive()` define la llamada esperada; `once()` exige una;
  `with()` comprueba argumentos; `shouldNotReceive()` prohíbe `hit` y `clear`.
  `Mockery::type('string')` exige el tipo, no una clave exacta del caché.
- **Para exponer:** «Simulamos que el límite de cuenta ya se alcanzó. El servicio
  real debe devolver el rechazo y conservar Retry-After sin tocar contadores».

## Captura 09 — andThrow y ausencia de notificación tras un fallo

- **Ruta/archivo:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Paciente\AppointmentOrchestrationMockeryTest.php`.
- **Líneas:** **18–39**, especialmente **21–28** y **33–39**.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'tests\Unit\Paciente\AppointmentOrchestrationMockeryTest.php') -Desde 18 -Hasta 39
```

- **Componente real:** `AppointmentController`, construido en línea **28** e invocado en **33**.
- **Dependencias simuladas:** `PatientAppointmentManager`, `PatientAvailabilityProvider`
  y `AppointmentNotificationService`.
- **Demuestra:** `andThrow($error)` provoca el conflicto esperado; se verifica la
  misma excepción, ninguna confirmación enviada y ningún mensaje de éxito.
- **Para exponer:** «Probamos cómo coordina el controlador sus colaboradores cuando
  la reprogramación falla. Este caso no prueba una reprogramación real en PostgreSQL».

## Captura 10 — Mail::fake y verificación de correo sin envío real

- **Rutas/archivos:**
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Support\MockeryIsolationTestCase.php`,
  líneas **23–38**;
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Auth\AuthMailBoundaryTest.php`,
  líneas **20–27**.
- **Parte a mostrar:** `Mail::fake()` en **37** y aserciones sobre el mailable.
  No ampliar la captura a otras fixtures del archivo.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'tests\Support\MockeryIsolationTestCase.php') -Desde 23 -Hasta 38
Mostrar-Lineas -Archivo (Join-Path $app 'tests\Unit\Auth\AuthMailBoundaryTest.php') -Desde 20 -Hasta 27
```

- **Componente real:** `AuthMailService::sendPasswordReset`, línea **20**.
- **Dependencia simulada:** subsistema de envío de correo mediante el fake de Laravel.
- **Demuestra:** un `PasswordResetMail`, destinatario sintético, vencimiento esperado,
  nada encolado y ninguna petición HTTP. La base también impide HTTP inesperado.
- **Para exponer:** «El servicio construye el correo real, pero el fake captura el
  envío dentro del test. Esto no acredita entrega en un buzón ni conectividad SMTP».

## Captura 11 — Http::fake para un fallo controlado de Google

- **Ruta/archivo:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\tests\Unit\Auth\GoogleOidcBoundaryTest.php`.
- **Líneas a mostrar:** **21–25** y **35–39**. El proveedor real se ejecuta en
  **27–28**; no hace falta mostrar sus argumentos de fixture ni el bloque setUp.
- **Comando:**

```powershell
Mostrar-Lineas -Archivo (Join-Path $app 'tests\Unit\Auth\GoogleOidcBoundaryTest.php') -Desde 21 -Hasta 25
Mostrar-Lineas -Archivo (Join-Path $app 'tests\Unit\Auth\GoogleOidcBoundaryTest.php') -Desde 35 -Hasta 39
```

- **Componente real:** `GoogleOidcIdentityProvider::exchange`.
- **Dependencia simulada:** endpoint HTTP de canje de Google mediante `Http::fake()`.
- **Demuestra:** respuesta HTTP 400 sintética, una petición interceptada y WARNING
  con categoría acotada `unknown_error`. No se contacta Google.
- **Para exponer:** «Controlamos la respuesta externa para probar el manejo seguro
  del error, con el proveedor de identidad real y sin depender de Internet».

## Captura 12 — Versión instalada y comandos de ejecución segura

- **Rutas:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\vendor\composer\installed.json`
  (consulta filtrada en terminal), y
  `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\phpunit.xml`,
  líneas **7–14** y **21–31**.
- **Referencia de archivo con líneas:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\frontend_clinica\composer.lock`,
  líneas **7367–7368**, declara Mockery 1.6.15; la instalación efectiva se consulta aparte.
- **Pantalla:** versión, selección Unit/Feature y aislamiento de pruebas. Las líneas
  de terminal no tienen numeración estable de archivo.
- **Comando:**

```powershell
$instalados = Get-Content -LiteralPath (Join-Path $app 'vendor\composer\installed.json') -Raw | ConvertFrom-Json
$instalados.packages | Where-Object { $_.name -eq 'mockery/mockery' } | Select-Object name, version
Mostrar-Lineas -Archivo (Join-Path $app 'phpunit.xml') -Desde 7 -Hasta 14
Mostrar-Lineas -Archivo (Join-Path $app 'phpunit.xml') -Desde 21 -Hasta 31
```

- **Demuestra:** versión realmente instalada, suites seleccionadas, entorno testing,
  SQLite en memoria, correo array y sesiones array.
- **Para exponer:** «Los comandos opcionales siguientes ejecutan pruebas aisladas.
  No seleccionan tests/Postgres y no requieren modificar la base clínica».

### Comandos opcionales para Unit, Feature y suite completa

**No se ejecutaron para preparar esta guía.** Definir esta función no ejecuta
pruebas. Una invocación posterior sí constituye una nueva ejecución; capturar su
resultado real y no atribuirle automáticamente los resultados de Fase 5.

```powershell
function Invoke-PruebasDocente {
    param([ValidateSet('Unit', 'Feature', 'Completa')][string]$Suite = 'Completa')
    $canalAnterior = [Environment]::GetEnvironmentVariable('LOG_CHANNEL', 'Process')
    Push-Location -LiteralPath $app
    try {
        # Comillas literales para que Laravel lea el nombre del canal null.
        $env:LOG_CHANNEL = '"null"'
        $argumentosPhp = @('vendor/phpunit/phpunit/phpunit', '--do-not-cache-result')
        if ($Suite -ne 'Completa') { $argumentosPhp += "--testsuite=$Suite" }
        & php @argumentosPhp
        if ($LASTEXITCODE -ne 0) { throw 'PHPUnit fallo; revisar el resultado real.' }
    } finally {
        [Environment]::SetEnvironmentVariable('LOG_CHANNEL', $canalAnterior, 'Process')
        Pop-Location
    }
}
```

Ejecutar solo la selección necesaria; no hace falta ejecutar las tres para una
presentación. El destino principal de prueba se descarta y los tests de rotación
usan sus propios archivos temporales sintéticos. No usar `composer test`, que
incluye limpieza de configuración, ni herramientas de generación de datos.

```powershell
# Opciones independientes; estas lineas ejecutan pruebas al invocarlas.
Invoke-PruebasDocente -Suite Unit
Invoke-PruebasDocente -Suite Feature
Invoke-PruebasDocente -Suite Completa
```

## Captura 13 — Resultados verificados, sin repetir la suite

- **Ruta/archivo primario:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\.local\clinical-audit\phase5-20261121\regression.xml`.
- **Líneas actuales:** **3** total; **4** Unit; **56** Feature. Mostrar el resumen
  en terminal; no es necesario abrir el XML completo ni exponer su contenido bruto.
- **Archivo documental:** `C:\Users\LENOVO\Desktop\PROYECTOS\Proyecto Clinica\logs\REVISION_BROWSER_FASE5.md`,
  líneas **94–113**. El JUnit es la evidencia primaria, no una cifra copiada a Markdown.
- **Comando de solo lectura:**

```powershell
if (Test-Path -LiteralPath $informeJUnit) {
    [xml]$junit = Get-Content -LiteralPath $informeJUnit -Raw
    $total = $junit.testsuites.testsuite
    $filas = @([pscustomobject]@{
        Conjunto = 'Total Fase 5'
        Pruebas = [int]$total.tests
        Aserciones = [int]$total.assertions
        Fallos = [int]$total.failures
        Errores = [int]$total.errors
        Omitidas = [int]$total.skipped
    })
    foreach ($suite in $total.SelectNodes('./testsuite')) {
        $filas += [pscustomobject]@{
            Conjunto = $suite.name
            Pruebas = [int]$suite.tests
            Aserciones = [int]$suite.assertions
            Fallos = [int]$suite.failures
            Errores = [int]$suite.errors
            Omitidas = [int]$suite.skipped
        }
    }
    $filas | Format-Table -AutoSize
} else {
    Write-Output 'Pendiente: el informe privado de Fase 5 no esta disponible.'
}
```

- **Demuestra:** 24 / 187 Unit, 434 / 3.346 Feature y 458 / 3.533 total, cero fallos,
  errores y omisiones. El cruce de Fase 5 encontró los mismos 456 casos anteriores
  y dos nuevos browser; no hubo casos anteriores faltantes.
- **Para exponer:** «Esta es la última ejecución verificada disponible. Hoy consulté
  el informe guardado; no presento esta lectura como una ejecución nueva».

## Orden recomendado para exponer

1. **01–02:** explicar qué es un log, dónde vive y qué operaciones cubre.
2. **03–05:** pasar del INFO y WARNING a una mutación con éxito después del commit.
3. **06–07:** explicar retención y mostrar el evento real ya existente, sin contexto.
4. **08–09:** explicar Mockery con expectativas y un fallo simulado.
5. **10–11:** diferenciar los fakes de correo y HTTP de los mocks de Mockery.
6. **12–13:** mostrar cómo se ejecutan las pruebas y cerrar con resultados acreditados.

Para una exposición breve, priorizar **01, 03, 05, 06, 07, 08, 09 y 13**. Tener
02, 04, 10, 11 y 12 como apoyo para las preguntas del docente. Dentro de una
captura se pueden disponer dos rangos en paneles de editor o usar la salida de
terminal indicada; no ampliar el encuadre a archivos privados.

## Servicios y dependencias: alcance real de Unit

Las once clases PHP Unit se inspeccionaron junto con su base compartida. Los
ejemplos seleccionados usan Mockery o fakes; no se eligieron casos de PHPUnit
sin simulación como demostración de mocking.

| Componente real probado | Colaborador simulado o fake | Alcance que puede afirmarse |
|---|---|---|
| AuthenticationService | AccountStatusService, RateLimiter, AuthManager y StatefulGuard | Coordinación del acceso; repositorio Eloquent real en SQLite efímero. |
| LoginAttemptService | RateLimiter con Mockery | Límites, llamadas y espera; captura 08. |
| SessionService | AuthManager, StatefulGuard y DatabaseManager | Fallo del guard y ausencia de éxito prematuro. |
| AdministrationService y ReceptionService | Identidad, DatabaseManager y ClinicalActorProvider con Mockery | Rechazo previo al acceso clínico, no alta SQL exitosa. |
| MedicoAtencionService, MedicoDerivacionService y MedicoAnalisisService | Identidad, base y actor con Mockery | Rechazos por rol o estado antes de SQL. |
| PatientAppointmentService | Identidad, base y actor con Mockery | Rechazo de reserva/cancelación/reprogramación por rol. |
| AppointmentNotificationService | DatabaseManager y AuthManager con Mockery | Rechazo del transporte antes de consumir outbox o enviar. |
| AppointmentController y NotificationController | Contratos de citas/disponibilidad y notificaciones con Mockery | Coordinación ante rechazo; captura 09. |
| AuthMailService | Mail::fake y mock de fachada Mail | Construcción/envío interceptado o rechazo previo; captura 10. |
| GoogleOidcIdentityProvider | Http::fake y Log::spy | Manejo de respuestas y ausencia de peticiones no esperadas; captura 11. |

## Evidencias pendientes y límites

- **Capturas de pantalla:** la selección y los comandos están preparados; las
  imágenes todavía deben tomarse siguiendo los encuadres. No se afirma que ya existan.
- **Registro real seguro:** la captura 07 está disponible como proyección de
  entradas existentes verificadas. No compartir el contexto ni abrir el histórico
  bruto. Si el archivo o las líneas cambian, revisar en privado o marcar pendiente.
- **Reservas/atenciones reales:** el inventario y las pruebas acreditan su
  instrumentación. No se seleccionó una entrada real de reserva o atención ni se
  ejecutó una operación para obtenerla. El ejemplo funcional real elegido es el
  alta administrativa de Secretaría.
- **Correo y Google:** los fakes no acreditan entrega SMTP ni un acceso OAuth real.
  Unit tampoco acredita concurrencia, triggers o integración PostgreSQL completa.
- **Browser:** su riesgo de contenido libre permanece. No usar sus registros como
  evidencia pública. La configuración daily se muestra sin acceder a su contenido.
- **Documentos anteriores:** README, inventario y auditorías conservan textos de
  fases anteriores. Para retención browser y resultados más recientes, usar el
  código actual, esta guía, el JUnit y `REVISION_BROWSER_FASE5.md`; no presentar
  456 / 3.514 ni browser single como el estado posterior a Fase 5.
- **Informe privado:** el JUnit está ignorado por Git. Si se presenta desde otro
  equipo, su disponibilidad debe resolverse mediante una revisión privada del
  artefacto o una nueva ejecución autorizada y claramente fechada. No copiar logs
  privados a `logs/`, `public/` ni a una carpeta de difusión.

## Verificación documental y preservación de Fase 6

Se contrastaron rutas y rangos con los archivos actuales, los nombres de eventos
con los emisores, la versión con `vendor/composer/installed.json` y los resultados
con el JUnit de Fase 5. Los cuatro métodos Unit seleccionados aparecen en ese
JUnit sin fallo, error u omisión. Los bloques PowerShell se analizaron
sintácticamente; solo se ejecutaron las consultas de lectura necesarias, sin
invocar `Invoke-PruebasDocente`.

Validación final: **13 capturas**, **18 archivos con rangos comprobados**, **16
bloques PowerShell sin errores de sintaxis** y **13 bloques de consulta ejecutados
sin errores**. La comparación SHA-256 de **607 archivos iniciales** confirmó
**cero cambios**, incluidos código, pruebas, recursos y registros históricos.

Se creó únicamente `logs/PRESENTACION_DOCENTE.md`. No se modificaron código de
producción, pruebas, configuración, recursos visuales, `public/hot`, SQL, usuarios,
permisos ni registros históricos. No se consultó ni alteró PostgreSQL. No se
instalaron dependencias, cambió de rama ni realizó commit o push.
