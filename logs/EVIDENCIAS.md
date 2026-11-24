# Evidencias y verificación — Fase 2

Actualizado el **21 de noviembre de 2026 (America/La_Paz)**. Este informe distingue código implementado, pruebas aisladas y comprobaciones pendientes. No contiene copias de registros reales, secretos, capturas privadas ni resultados inventados.

## Revalidación final — Fase 4

Se volvieron a ejecutar Unit (24 pruebas, 187 aserciones), Feature (432, 3.327), logging (74, 1.075, subconjunto de Feature) y el conjunto Unit,Feature en orden aleatorio con semilla 20261121 (456, 3.514). Todas aprobaron sin fallos, errores u omisiones. El total único es 456, no la suma de las cuatro ejecuciones. Informes nuevos: `.local/clinical-audit/phase4-20261121/*.xml`.

Los resultados de 3.308 aserciones de Feature que se conservan más abajo son históricos de Fase 2. El valor actual es 3.327 tras las expectativas adicionales de Fase 3; no se reescribe el resultado histórico como si se hubiera ejecutado de nuevo en esa fase.

Se comprobó el handler activo RotatingFileHandler y existía antes de esta auditoría `storage/logs/laravel-2026-11-21.log`, con dos INFO de autenticación y 214 bytes al inspeccionarlo. Solo se extrajeron prefijos y nombres de claves; no se copiaron registros/contextos ni se generaron eventos artificiales. Las capturas reproducibles y el alcance de la correlación están en [GUIA_CAPTURAS.md](GUIA_CAPTURAS.md).

El arranque completo reveló el canal adicional browser de Boost: single, sin filtro ClinicalLog ni rotación diaria efectiva, y ruta de escritura de desarrollo. No es una ruta de descarga del archivo, pero requiere revisar exposición/retención del entorno. El diagnóstico completo y la matriz de cumplimiento están en [AUDITORIA_FINAL.md](AUDITORIA_FINAL.md). Esta aclaración amplía el alcance de la revisión anterior, que describía los canales declarados y el código propio.

## Ubicaciones

~~~text
Proyecto Clinica/
├── frontend_clinica/
│   ├── app/Support/ClinicalLog.php
│   ├── app/Services/...
│   ├── bootstrap/app.php
│   ├── config/logging.php
│   ├── storage/logs/
│   │   ├── laravel.log                 (histórico anterior)
│   │   └── laravel-AAAA-MM-DD.log      (nuevo destino; se crea con el primer evento)
│   └── tests/Feature/
│       ├── ClinicalLoggingTest.php
│       └── LoggingRotationTest.php
└── logs/
    ├── README.md
    ├── INVENTARIO_LOGS.md
    └── EVIDENCIAS.md
~~~

`storage/logs/` también contiene artefactos anteriores de pruebas/navegador. Su existencia no acredita que correspondan al código actual. No se trasladaron al directorio documental ni se usaron como prueba de operaciones clínicas realizadas en esta fase.

## Configuración comprobada sin publicar el entorno privado

La carga de `LoadEnvironmentVariables` y `LoadConfiguration`, sin arrancar operaciones clínicas, resolvió:

| Parámetro | Resultado |
|---|---|
| `logging.default` | `stack` |
| `logging.channels.stack.channels` | `daily` |
| `logging.channels.daily.level` | `debug` |
| `logging.channels.daily.max_files` | `14`, cargado desde `LOG_DAILY_DAYS` y convertido por el driver |
| Destino diario | `frontend_clinica/storage/logs/laravel-AAAA-MM-DD.log` |
| Caché de configuración | No activa en esta copia CLI |
| Zona de aplicación | UTC; considerar UTC-04:00 al relacionar la hora local de La Paz |

Se modificaron solo las variables de logging necesarias en `.env` y `.env.example`. No se publicó el archivo privado ni se consultaron sus credenciales para la documentación. Un proceso persistente o servidor con variables distintas debe recargar/verificar su propia configuración; no se reiniciaron servicios.

## Consulta práctica en Windows y PowerShell

Abrir `logs/` en el Explorador para la documentación. Los registros privados se encuentran en `frontend_clinica\storage\logs`, fuera de `public`.

Desde la raíz del repositorio:

~~~powershell
# Listar sin mostrar mensajes o contextos.
Get-ChildItem .\frontend_clinica\storage\logs -Filter 'laravel-????-??-??.log' |
    Sort-Object Name -Descending |
    Select-Object Name, Length, LastWriteTime

# Obtener el último archivo diario, si ya hubo eventos.
$latestLog = Get-ChildItem .\frontend_clinica\storage\logs -Filter 'laravel-????-??-??.log' |
    Sort-Object Name -Descending | Select-Object -First 1

# Solo para una terminal privada: puede mostrar contexto de otros eventos históricos.
if ($latestLog) {
    Get-Content -LiteralPath $latestLog.FullName -Tail 30
    Get-Content -LiteralPath $latestLog.FullName -Tail 0 -Wait
}
~~~

`Ctrl+C` detiene la observación. Si todavía no existe un archivo diario, no fabricar entradas: primero verificar el canal/umbral y realizar una operación ficticia en un entorno de demostración aislado.

Para compartir una captura limitada a fecha, entorno, nivel y nombre de los eventos nuevos, sin sus contextos:

~~~powershell
if ($latestLog) {
    Get-Content -LiteralPath $latestLog.FullName | ForEach-Object {
        if ($_ -match '^\[(?<date>[^\]]+)\] (?<environment>[A-Za-z0-9_-]+)\.(?<level>INFO|WARNING|ERROR): (?<event>(auth|admin|reception|doctor|patient|notification|clinical|app)\.[a-z_.]+)') {
            [pscustomobject]@{
                Fecha = $Matches['date']
                Entorno = $Matches['environment']
                Nivel = $Matches['level']
                Evento = $Matches['event']
            }
        }
    } | Select-Object -Last 20
}
~~~

Para identificar también niveles de mensajes originales sin mostrar sus cuerpos:

~~~powershell
if ($latestLog) {
    Get-Content -LiteralPath $latestLog.FullName | ForEach-Object {
        if ($_ -match '^\[[^\]]+\] [A-Za-z0-9_-]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):') {
            $Matches[0]
        }
    } | Select-Object -Last 20
}
~~~

INFO confirma la operación descrita; WARNING indica rechazo o fallo recuperable; ERROR indica fallo técnico. Un INFO de aceptación por transporte no acredita entrega al buzón. Un umbral `warning` o superior filtra los INFO.

## Cómo relacionar una operación con su evento

1. Elegir una fila del [inventario](INVENTARIO_LOGS.md), localizar archivo/clase/método y su condición exacta.
2. Preparar datos ficticios en un entorno aislado; no ejecutar una demostración de cambios sobre usuarios o citas reales.
3. Anotar hora y zona y observar solo entradas nuevas. Identificar nombre, resultado y códigos técnicos permitidos; nunca mostrar pase QR, correo, contraseña, OTP o formulario completo.
4. Para éxito de mutación, comprobar que la operación fue confirmada. Para rechazo o rollback, comprobar que no aparece el INFO base y sí la variante WARNING/ERROR correspondiente.
5. Usar el identificador técnico ficticio para correlacionar cuando exista. No se agregó un identificador de petición global; la deduplicación es por instancia de excepción.

La Fase 2 no ejecutó estas operaciones sobre PostgreSQL ni creó cuentas/citas de demostración en la base principal. La prueba automatizada recorre servicios reales con respuestas de base ficticias. Es evidencia de instrumentación y límites transaccionales, no certificación del SQL, triggers o permisos instalados.

## Capturas recomendadas

| Captura por realizar | Fuente actual | Qué demostrar |
|---|---|---|
| Rotación | `frontend_clinica/config/logging.php`, stack y daily; `.env.example`, solo variables LOG | daily, umbral configurable y retención 14. No mostrar `.env`. |
| Filtro de contexto | `frontend_clinica/app/Support/ClinicalLog.php` | Lista cerrada, ausencia de mensajes/objetos completos, deduplicación y diferimiento. |
| Confirmación | `frontend_clinica/app/Services/Clinical/ClinicalTransaction.php` | El INFO ocurre después de transaction, y los catch no emiten éxito. |
| Login | `frontend_clinica/app/Services/Auth/AuthenticationService.php` y `LoginAttemptService.php` | Eventos success/failed/blocked y un solo punto por condición. |
| Administración / Secretaría | `AdministrationService.php` y `ReceptionService.php`, rutas exactas en inventario | Eventos en mutaciones implementadas; no se inventó actualización de bloqueo. |
| Médico / Paciente | Servicios actuales de ambos módulos, indicados en inventario | Estado operativo y códigos, sin información sanitaria. |
| Correo / QR | `frontend_clinica/app/Services/Notifications/AppointmentNotificationService.php` y WARNING originales | Aceptación por transporte tras commit, fallo recuperable y conservación de advertencias anteriores. |
| Reporte de Laravel | `frontend_clinica/bootstrap/app.php` | Sustitución del volcado automático por categoría segura, manteniendo ShouldntReport. |
| Resultado de pruebas | Terminal privada con el comando y conteos finales siguientes | Pruebas reales, sin capturar mensajes privados del archivo histórico. |

Las capturas no se generaron ni se adjuntaron en esta fase. No mostrar un resultado de pruebas como si fuera una entrega real de correo o una operación sobre PostgreSQL.

## Pruebas ejecutadas y resultados reales

Directorio de ejecución: `frontend_clinica/`.

| Ejecución | Resultado real |
|---|---|
| Suite Feature existente, antes de incorporar los nuevos archivos de pruebas | 358 pruebas pasadas, 2.233 aserciones. Es una ejecución inicial de la suite, no una comparación contra un checkout limpio: había cambios locales previos. |
| Pruebas específicas finales, después de agregar commits anidados y rollback exterior | **74 pruebas pasadas, 1.075 aserciones**, salida 0. |
| Suite Feature completa final, después del formato de PHP | **432 pruebas pasadas, 3.308 aserciones**, salida 0. Incluye las 358 existentes y las 74 nuevas. |

Comandos ejecutados:

~~~powershell
# Ejecución inicial de la suite existente.
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature

# Comprobaciones finales: descartar logs generales en este proceso.
$env:LOG_CHANNEL='"null"'
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature/ClinicalLoggingTest.php tests/Feature/LoggingRotationTest.php
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Feature
~~~

Las comillas dobles dentro del valor de `LOG_CHANNEL` hacen que el lector de entorno conserve el nombre literal del canal `null`, en lugar del valor PHP null. Esta variable se usó solo en los procesos de prueba; no se guardó en `.env`. En una terminal reutilizada, guardar y restaurar su valor anterior al terminar. Los tests de eventos sustituyen Log por un recolector en memoria y el test de rotación crea un LogManager propio con destino temporal.

En la primera iteración de las nuevas pruebas hubo 1 error y 1 fallo entre 71 casos: el doble de base devolvía una especialidad con estado inadecuado en el alta médica. Se corrigió el fixture, sin cambiar la regla de negocio. Después pasaron los 72 casos de eventos/rotación y, al añadir los dos casos de commit exterior, pasaron los 74 finales indicados arriba.

### Cobertura nueva

- **28 mutaciones de servicios reales**: 16 de Administración, 5 de Secretaría, 3 de Paciente y 4 de Médico. Cada caso verifica un INFO, actor técnico, ausencia de valores privados ficticios y emisión después del commit.
- Los mismos **28 casos con fallo de commit** verifican ausencia de éxito, propagación del error y un único ERROR aunque se reporte después a Laravel.
- Conflicto SQL traducido a WARNING con SQLSTATE seguro, sin SQL, parámetros, mensaje ni excepción anterior.
- Vista previa sin INFO y rechazo QR con un único WARNING.
- Reutilización de derivación etiquetada como procesamiento, sin afirmar una creación nueva.
- Login correcto, credenciales inválidas, límite, identidad verificada, logout idempotente y fallo de sesión sin éxito falso.
- Contexto sensible rechazado y fallo del destino de logging que no cambia una operación ya confirmada.
- Manejador global sin volcado de QueryException y conservación de ShouldntReport.
- Fallo de recuperación por correo, aceptación/fallo de notificación y reintento solicitado/rechazado/ya enviado.
- Diferimiento de INFO hasta commit exterior y descarte en rollback exterior usando **SQLite en memoria**, sin tablas clínicas.
- Driver nativo **RotatingFileHandler**: crea un archivo fechado, mantiene 14 archivos diarios sintéticos, filtra DEBUG con umbral INFO y conserva nombres históricos ajenos a esa familia. Solo borra sus propios archivos temporales al terminar.

`tests/Support/LoggingFixtures.php` contiene una conexión ficticia que prohíbe abrir PDO. No valida la semántica del SQL. No se agregaron dependencias ni pruebas nuevas con Mockery; se utilizaron dobles simples, PHPUnit, Laravel y un SQLite en memoria para el límite de commit exterior. Las pruebas previas con Mockery se conservaron.

PHPUnit/Pint necesitaron ejecución fuera del sandbox de Windows: sus comprobaciones de lectura/escritura daban falsos negativos, como ya se había observado en Fase 1. No se cambiaron ACL, dependencias ni configuración clínica para resolverlo.

## Seguridad, Git e históricos

| Comprobación | Resultado y límite |
|---|---|
| Campos sensibles | Las nuevas pruebas usan valores centinela ficticios de nombres, correo, documentos, teléfonos, claves, hash, QR y contenido clínico; verifican que no aparecen en los eventos capturados. También se rechazan estructuras anidadas y cabeceras. |
| Reporte automático | El callback propio devuelve false para evitar el reporte estándar con SQL/mensaje/stacktrace. Registra solo clase/categoría/estado; ShouldntReport sigue intacto. No cambia el renderizado de errores ni APP_DEBUG. |
| Siete WARNING previos | Continúan las siete llamadas y sus mensajes/contextos; ubicaciones actualizadas en el inventario. No se alteraron GoogleOidcIdentityProvider, OtpService ni AppointmentController. |
| Exclusiones Git | `git check-ignore -v` confirmó `storage/logs/.gitignore` para el archivo diario y `frontend_clinica/.gitignore` para `.env`. `git ls-files '*.log' '*storage/logs/*'` solo listó el `.gitignore` de storage. |
| Acceso web | No se agregó ninguna ruta ni enlace público a logs; permanecen fuera de public. No se certificaron configuración de servidor, aliases, proxy ni respuestas HTTP de producción. |
| Permisos | No se cambiaron ACL ni permisos de aplicación. Las ACL heredadas y su revisión pendiente de mínimo privilegio son las identificadas en Fase 1. |
| Histórico single | No se ejecutaron borrados, truncados, reescrituras ni rotación sobre `storage/logs/laravel.log`. La rotación física se probó en un directorio temporal independiente. |
| Comparación del histórico durante la validación final | `laravel.log` midió 11.726.602 bytes; su SHA-256 antes/después de la suite final fue `46249be89a2c6fa41aae13a50772d5eb1478b627553293e8a1fdbf866341da51`. Es evidencia de estabilidad en ese intervalo, no de toda la fase: no se tomó hash antes del primer comando de pruebas y no se puede certificar que el canal anterior no recibió anexos durante la ejecución inicial. No se eliminó contenido para restaurar un tamaño anterior. |
| Documentación | Solo nombres de variables, código, resultados y metadatos agregados. No se copiaron logs, credenciales, correos ni datos médicos a `logs/`. |

Comprobaciones Git reproducibles desde la raíz:

~~~powershell
git check-ignore -v frontend_clinica/storage/logs/laravel-2026-11-21.log frontend_clinica/.env
git ls-files '*.log' '*storage/logs/*'
git status --short
git diff --check
~~~

No realizar `git add -f` de logs ni de `.env`. La carpeta raíz `logs/` es exclusivamente documental y no está protegida por las reglas de `frontend_clinica/` para futuros archivos de ejecución fuera de esa subcarpeta.

## Verificaciones adicionales y límites pendientes

- PHP 8.5.10 y Composer 2.10.3 disponibles; se usaron dependencias instaladas. No se ejecutó instalación de Boost ni de otros paquetes.
- Se aplicó Pint únicamente a los 18 PHP de esta fase, sin formatear los cambios previos ajenos. La comprobación posterior `pint --test` pasó. Se comprobó sintaxis de esos 18 archivos y `git diff --check` en los archivos modificados de esta fase.
- La validación documental comprobó tres Markdown UTF-8, bloques de código equilibrados, enlaces/rutas existentes y 55 referencias de clase/método válidas, sin errores. La búsqueda acotada de firmas de secretos de proveedor/claves privadas no encontró coincidencias en estos tres documentos.
- Se compararon por tokens los 121 literales SQL de los 12 servicios modificados contra su versión Git: no hubo diferencias en esos literales. Esto verifica conservación de sentencias; no sustituye una prueba de integración PostgreSQL.
- No se cambió ningún SQL de negocio: los argumentos nuevos son nombres/contexto de logging. No se modificaron esquema, tabla bitacora, funciones, triggers, roles PostgreSQL ni datos.
- No se ejecutaron suites `tests/Postgres`, migraciones, tareas de mantenimiento, consumo de la cola real ni pruebas de carga/concurrencia sobre la base principal.
- Falta verificar la integración en una base desechable autorizada y el comportamiento de infraestructura real. Las pruebas Feature no certifican SMTP, entrega al destinatario, permisos SQL ni constraints instalados.
- `admin.block.updated` no se implementó porque esa operación no existe. No se agregaron rutas o reglas para hacerla existir.
- El riesgo previo de `auditar_fila` permanece: su definición excluye secretos y resultados, pero puede conservar `motivo_derivacion` y otros motivos libres. El filtro del visor no elimina lo almacenado en PostgreSQL. Se reporta, sin modificar la base.
- Los registros históricos pueden contener mensajes/SQL de versiones previas; la nueva redacción no sanea retroactivamente esos archivos. Tampoco controla logs de Apache/PHP/proxy ni futuros emisores que omitan ClinicalLog.
- El transporte de correo log/failover sigue declarado en configuración; los servicios sensibles revisados lo rechazan y no se activó. No debe habilitarse para correos OTP, recuperación o pases reales.
- `APP_DEBUG=true` sigue siendo configuración local. La seguridad de respuestas de un despliegue público, la supervisión de disco/ACL y el acceso efectivo a archivos requieren revisión de despliegue; no se alteraron en esta fase.
- Si falla el logger o no se puede diferir un evento a commit, se evita alterar la operación y puede perderse el registro. Hace falta supervisión externa para detectar un destino no disponible.

Los archivos y motivos de cambio completos se encuentran en [INVENTARIO_LOGS.md](INVENTARIO_LOGS.md). No se hizo commit, push, merge ni cambio de rama. Fase 2 terminada; no se avanza a la Fase 3.
