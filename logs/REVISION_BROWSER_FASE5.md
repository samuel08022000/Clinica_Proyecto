# Fase 5 — Revisión segura del canal browser

Fecha: 21 de noviembre de 2026. Versiones verificadas: Laravel 13.33.0,
Laravel Boost v2.10.4, PHP 8.5.10. La auditoría inicial se completó antes de
modificar la configuración. No se abrieron paneles ni se verificó visualmente
la interfaz durante esta fase.

## A. Estado del canal browser

Antes del cambio, el canal no estaba declarado en `config/logging.php`.
`Laravel\Boost\BoostServiceProvider::registerBrowserLogger()` lo agregaba al
arrancar: driver `single`, destino `storage/logs/browser.log`, nivel `debug` y
opción `days=14`. El handler efectivo era `Monolog\Handler\StreamHandler`.
Laravel no usa `days` para el driver `single`: **no existía rotación de browser**.

El proveedor registra `POST /_boost/browser-logs`. El script de Boost intercepta
mensajes de consola y errores JavaScript; envía datos, URL completa, user-agent y
timestamp. El servidor convierte los datos en texto y los escribe en `browser`.
La herramienta MCP `Laravel\Boost\Mcp\Tools\BrowserLogs` lee ese canal mediante
`Laravel\Boost\Concerns\ReadsLogs`.

El entorno observado es `local`, con debug, Boost y su watcher activos. El
proveedor omite Boost durante las pruebas ordinarias y, fuera de local, cuando
debug no está activo. No se cambió ninguna de esas condiciones.

Después del cambio, el canal usa `daily` y el handler efectivo es
`Monolog\Handler\RotatingFileHandler`, con destino
`storage/logs/browser-2026-11-21.log` y retención de **14 archivos diarios**.
La ruta y el watcher de Boost siguen registrados. Resolver el handler para esta
comprobación no emitió mensajes ni creó un archivo de ejecución nuevo.

## B. Riesgo real identificado

1. El archivo único crecía sin una política efectiva de rotación. El histórico
   examinado tenía 549.539 bytes y 1.931 entradas repartidas en ocho fechas.
2. Boost acepta mensajes libres, objetos, errores y trazas. Registra la URL con
   parámetros y no utiliza `ClinicalLog`. Puede persistir credenciales, tokens,
   identificadores o información médica si aparecen en consola, errores o URL.
   Esto no significa que capture automáticamente todos los campos de formularios.
3. Una búsqueda agregada en memoria encontró 186 URLs con parámetros, cero
   coincidencias de correo y cero coincidencias del patrón acotado de claves de
   credenciales. No se publicaron líneas, valores ni parámetros del histórico.
   Estos resultados no certifican ausencia de información sensible.
4. La ruta de recepción no tiene middleware de autenticación ni throttle en su
   definición y excluye `VerifyCsrfToken`. Si el entorno de desarrollo quedara
   expuesto, podría recibir escrituras no confiables. No es una ruta HTTP de
   lectura de logs; el acceso MCP es otro límite de confianza.

Los registros están fuera de `public`. `storage/logs/.gitignore` ignora todo
salvo su propio `.gitignore`; `git ls-files -- storage/logs` solo enumera ese
archivo. `git check-ignore` confirmó el histórico y el nombre diario. Los
informes privados bajo `.local/clinical-audit/` también están ignorados.

## C. Corrección aplicada y compatibilidad

Se añadió únicamente una definición del canal `browser` en
`frontend_clinica/config/logging.php`: `daily`, `max_files=14`, mismo destino
base y mismo `LOG_LEVEL`, `locking=true` y permiso de creación `0600`.
Este permiso es una protección POSIX; no certifica ni sustituye las ACL de Windows.

La extensión es compatible con la versión instalada por dos razones comprobadas
en su código y con pruebas de integración:

- Boost conserva un canal previamente definido por la aplicación.
- Su lector resuelve los archivos de un canal `daily` y puede leer las nuevas
  entradas sin cambiar el formato de mensaje ni contexto.

No se editó `vendor`, no se publicaron configuraciones de Boost, no se instalaron
dependencias y no se alteraron autenticación, rutas de negocio o PostgreSQL.
El histórico `browser.log` permanece intacto y no se mezcló con los archivos
diarios. La retención se aplica al nuevo flujo diario; no se limpió el histórico
anterior. Es una retención por número de archivos y actividad, no una tarea
programada que borre al cumplirse exactamente catorce días naturales.

La rotación **no anonimiza** los mensajes libres. Se conservó el diagnóstico
funcional de Boost y se evitó introducir un filtro heurístico cuya cobertura de
datos médicos y secretos no puede garantizarse. Para entornos con datos sensibles,
la opción segura documentada por la dependencia es
`BOOST_BROWSER_LOGS_WATCHER=false`; en producción, mantener debug desactivado y
considerar `BOOST_ENABLED=false`. No se cambiaron estas opciones en esta fase.

## D. Archivos modificados en esta fase

| Archivo | Cambio |
|---|---|
| [config/logging.php](../frontend_clinica/config/logging.php) | Definición compatible de browser con rotación diaria. |
| [BrowserLoggingTest.php](../frontend_clinica/tests/Feature/BrowserLoggingTest.php) | Dos casos nuevos de integración con archivos sintéticos temporales. |
| [REVISION_BROWSER_FASE5.md](REVISION_BROWSER_FASE5.md) | Este informe. |

Los demás cambios que ya existían en el árbol de trabajo pertenecen a fases
anteriores y se conservaron. Se generaron evidencias locales ignoradas bajo
`.local/clinical-audit/phase5-20261121/`. No hubo commit ni push.

## E. Resultados reales de las pruebas

Las dos pruebas nuevas verifican retención efectiva de 14 archivos, conservación
del archivo base histórico, lectura MCP del archivo diario y recepción/lectura
mediante el proveedor y endpoint reales de Boost. Su activación ocurre solamente
en la aplicación aislada del test. No contactan un navegador, PostgreSQL ni un
servidor externo; usan `example.invalid` y datos sintéticos. La limpieza queda
acotada al directorio aleatorio creado por cada caso.

| Conjunto identificado dentro de la ejecución completa | Casos | Aserciones | Fallos / errores / omitidos |
|---|---:|---:|---|
| Los mismos casos anteriores, cruzados por clase y nombre JUnit | 456 | 3.514 | 0 / 0 / 0 |
| Unit / Mockery existente, subconjunto de los anteriores | 24 | 187 | 0 / 0 / 0 |
| Logging anterior, subconjunto de los anteriores | 74 | 1.075 | 0 / 0 / 0 |
| Dos casos nuevos de browser | 2 | 19 | 0 / 0 / 0 |
| **Total único Unit + Feature** | **458** | **3.533** | **0 / 0 / 0** |

No sumar los subconjuntos. La ejecución enfocada previa de browser también
aprobó 2 casos y 19 aserciones. Sintaxis PHP y Pint `--test` aprobaron los dos
archivos PHP de esta fase.

Los procesos PHPUnit usaron el nombre literal del canal `null` como destino
principal, restaurando después la variable del proceso. Las pruebas de rotación
seleccionaron explícitamente sus destinos sintéticos. La comprobación CLI normal,
sin emitir mensajes, confirmó que el stack principal sigue en `daily`, con 14
archivos y `RotatingFileHandler`.

La extracción actual confirmó **31 nombres explícitos en 40 puntos de emisión**,
sin diferencias respecto a Fase 4, y **siete llamadas `Log::warning` anteriores**.
Los emisores y las pruebas previas no cambiaron. No se afirma una nueva ejecución
de esos eventos contra operaciones clínicas reales ni de `tests/Postgres`.

Evidencias privadas:

- [JUnit completo](../.local/clinical-audit/phase5-20261121/regression.xml).
- [JUnit browser](../.local/clinical-audit/phase5-20261121/browser.xml).
- [Cruce de casos y conteos](../.local/clinical-audit/phase5-20261121/test-summary.json).
- [Eventos](../.local/clinical-audit/phase5-20261121/events.json).
- [Handlers efectivos](../.local/clinical-audit/phase5-20261121/runtime-after.json).

## F. Preservación del sistema visual y de históricos

La comparación SHA-256 de 598 archivos iniciales detectó un único cambio en un
archivo existente: `config/logging.php`. La prueba y este informe son nuevos.

| Grupo inicial | Archivos | Modificados |
|---|---:|---:|
| Recursos visuales, archivos de public, Vite y manifiestos de paquetes | 143 | 0 |
| Históricos de storage/logs | 43 | 0 |
| Dependencia Laravel Boost | 192 | 0 |
| Archivos SQL revisados | 18 | 0 |

`public/hot` estaba ausente antes y continúa ausente; no se escribió, movió ni
recreó. No se compilaron activos, limpiaron cachés/sesiones ni modificaron Vite,
CSS, JavaScript o Blade. No aparecieron archivos nuevos en `storage/logs` durante
esta fase. No se eliminaron ni reescribieron históricos.

Esta es una comprobación técnica de preservación. **No se abrieron paneles ni se
realizó una nueva verificación visual.**

- [Instantánea inicial](../.local/clinical-audit/phase5-20261121/before.json).
- [Preservación](../.local/clinical-audit/phase5-20261121/preservation.json).

## G. Limitaciones pendientes

- La confidencialidad del contenido libre sigue dependiendo de lo que se envíe
  a consola/errores/URL y del uso del watcher en entornos apropiados. La rotación
  no elimina ese riesgo; no se declara el canal completamente anonimizado.
- No se verificaron ACL efectivas, publicación de puertos, proxy, DocumentRoot
  o despliegue de producción. Ignorar archivos en Git no controla esos accesos.
- Boost consultará los nuevos archivos diarios. El histórico base conservado no
  se incorpora automáticamente a esa lectura; antes de la primera entrada diaria,
  el lector puede indicar que todavía no hay un archivo diario disponible.
- No se inyectaron mensajes sintéticos en los logs reales para fabricar evidencia.
  La escritura y lectura se demostraron con la aplicación de prueba y el destino
  temporal; en el arranque normal se verificó la resolución del handler.
- Procesos PHP persistentes que ya hubieran resuelto el canal necesitan un reinicio
  operativo para tomar una configuración nueva. No se reiniciaron servicios.
- No se certifican todas las versiones futuras de Boost; una actualización debe
  volver a ejecutar las pruebas de compatibilidad añadidas.

La fase termina con rotación corregida, históricos preservados y regresiones
aprobadas. Queda pendiente la decisión operativa de desactivar el watcher donde
se maneje información sensible.
