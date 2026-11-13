# Ejecución e integración del sistema

La comunicación clínica es navegador → Laravel Blade → Quarkus → PostgreSQL. Laravel conserva el Bearer en su sesión del servidor. No ejecutar migraciones clínicas desde Laravel ni apuntar las pruebas a una base habitual.

## Requisitos

- JDK **25**, compatible con `maven.compiler.release=25` del backend. Utilizar Maven Wrapper; su distribución está fijada en `.mvn/wrapper/maven-wrapper.properties`.
- PHP **8.5** para reproducir esta ejecución. Las dependencias instaladas deben satisfacer `composer check-platform-reqs`; no basta con interpretar el requisito raíz de composer.json.
- Composer 2; Node **22** y npm para Vite y el driver de navegador sin dependencias adicionales.
- PostgreSQL **15** con las extensiones utilizadas por las migraciones. Docker Desktop/Linux Engine permite reproducir la base efímera.
- Chrome o Edge instalado para `scripts/integration/browser-e2e.mjs`; definir `CHROME_PATH` si no se detecta. No se descarga un navegador automáticamente.

Desde un checkout con dependencias pendientes:

```powershell
cd frontend_clinica
composer install
composer check-platform-reqs
npm.cmd ci
npm.cmd run build
```

No usar `composer setup` para esta verificación: incluye generación de clave y migraciones locales. No ejecutar `boost:install`, reinstalar skills o cambiar versiones para realizar pruebas.

## Configuración de desarrollo

Configurar los archivos `.env` locales a partir de sus ejemplos únicamente si todavía no existen. No sobrescribirlos ni versionarlos. Las variables relevantes son:

| Aplicación | Variables |
| --- | --- |
| Compose habitual | `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `POSTGRES_HOST_PORT`; pgAdmin utiliza `PGADMIN_DEFAULT_EMAIL`, `PGADMIN_DEFAULT_PASSWORD`, `PGADMIN_HOST_PORT`. |
| Quarkus | `QUARKUS_DATASOURCE_JDBC_URL`, `QUARKUS_DATASOURCE_USERNAME`, `QUARKUS_DATASOURCE_PASSWORD`; `QUARKUS_HTTP_HOST`, `QUARKUS_HTTP_PORT` cuando se cambie el puerto. |
| Laravel | `APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG`, `QUARKUS_API_URL`, `QUARKUS_API_TIMEOUT`, `QUARKUS_API_CONNECT_TIMEOUT`, `QUARKUS_REGISTRATION_TIMEOUT`. |
| Sesión Laravel | `SESSION_DRIVER`, `SESSION_ENCRYPT`, `SESSION_COOKIE`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`. Usar archivo/Redis/base propia de sesiones; nunca consultas clínicas desde Laravel. |
| Entrega OTP/recuperación | `OTP_DELIVERY_PROVIDER` y variables SMTP descritas en el ejemplo. El proveedor demo exige ejecución de desarrollo/test y una clave efímera privada. No habilitarlo en producción. |
| Google | Variables OIDC del ejemplo, solo con credenciales y callback autorizados. Las pruebas locales no acreditan consentimiento externo. |

Antes de iniciar Quarkus, comprobar que la URL JDBC pertenece a la base autorizada: Flyway migra al arrancar. No descubrir el estado de una base real iniciando la aplicación sobre ella. Revisar historial/checksums y respaldo antes de cualquier despliegue.

Arranque de desarrollo, en terminales separadas:

```powershell
cd backend_clinica
.\mvnw.cmd quarkus:dev
```

```powershell
cd frontend_clinica
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
cd frontend_clinica
npm.cmd run dev
```

Comprobar `/hello` en Quarkus y `/login` en Laravel. Entrar con cuentas autorizadas; las redirecciones son `/paciente/inicio`, `/secretaria/inicio`, `/medico/inicio` y `/administracion/inicio`. No usar una cuenta administrativa para simular Secretaría.

`docker-compose.yml` mantiene el stack habitual y su perfil `fullstack`. Sus runtimes declaran Java 25 y PHP 8.5. Ese perfil necesita dependencias Composer y assets del proyecto montado; la imagen PHP CLI no incluye Composer o Node. La Fase 12 utiliza PostgreSQL en Compose y los runtimes nativos instalados; no acredita la ejecución completa de ese perfil.

## Integración aislada reproducible

Ejecutar estos comandos **desde la raíz**, en PowerShell. Los scripts no cambian `.env`, roles, migraciones ni credenciales habituales.

```powershell
powershell -NoProfile -File scripts/integration/Start-Database.ps1
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Backend
powershell -NoProfile -File scripts/integration/Write-Fixtures.ps1
powershell -NoProfile -File scripts/integration/Start-Applications.ps1 -Dataset legacy
```

La base es un proyecto Compose **clinica-fase12** separado. PostgreSQL se publica solo en `127.0.0.1:35441`, sin volumen persistente compartido y con datos en tmpfs. La contraseña se genera aleatoriamente y se conserva únicamente en `backend_clinica/target/integration-fase12/runtime.json`, ignorado por Git. No imprimir ese archivo.

La suite backend usa `clinica_fase12_tests`; las pruebas heredadas usan una base nueva `clinica_fase9_live_f12` por compatibilidad de sus guardas. El navegador usa `clinica_fase12_e2e_<sufijo>` con una marca de propiedad que verifica el fixture antes de migrar. Se rechazan esquemas existentes. Las pruebas de actualización crean bases auxiliares exclusivamente en este contenedor.

Quarkus ocupa 18090; Laravel sano 18091; otro Laravel con transporte deliberadamente caído ocupa 18092 y comparte solo la sesión temporal. Esperar HTTP 200 en `http://127.0.0.1:18090/hello` y `http://127.0.0.1:18091/login` antes de continuar. El arranque del JVM/Maven en frío puede tardar varios minutos. Los procesos se ejecutan con ventana oculta y quedan registrados para detener exclusivamente esos PID.

```powershell
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Legacy
powershell -NoProfile -File scripts/integration/Stop-Applications.ps1
powershell -NoProfile -File scripts/integration/Start-Applications.ps1 -Dataset browser
# Esperar nuevamente /hello y /login.
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Browser
powershell -NoProfile -File scripts/integration/Audit-Database.ps1
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Contract
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Fault
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Expiry
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Visual
```

`Backend` ejecuta `mvnw.cmd -DskipITs=false verify`; `-Offline` opcional usa la caché Maven ya instalada. `Legacy` activa las suites reales de Fase 9 además de la regresión Laravel completa. `Browser` usa formularios normales, sesiones, CSRF, Quarkus y persistencia reales. `Fault` utiliza un proxy de errores **simulados**, conservando autorización contra Quarkus real; sus resultados se clasifican separadamente. Requiere el puerto 18093 libre y no modifica la configuración de producción.

`Contract` verifica los límites corregidos y errores reales mediante HTTP. `Expiry` arranca otra aplicación Quarkus empaquetada en 18094 y un Laravel en 18095; reduce solo el TTL de ese proceso a un minuto y espera su vencimiento natural con HTTP/cookies/CSRF. No modifica el reloj ni atribuye esa prueba a Chrome. `Visual` utiliza Laravel en modo producción en 18096 y la base real aislada para capturas/teclado/responsive; sus procesos secundarios se cierran al terminar.

Los usuarios sintéticos de cuatro roles, observadores y dos secretarios están en el fixture privado `target/integration-fase12/browser-fixture.json`; el test los lee en memoria. Para una demostración autorizada, consultar ese fixture local en privado y entrar por `/login`, sin copiar credenciales a este documento, capturas o mensajes. Un nuevo login revoca la sesión anterior de la misma cuenta: utilizar cuentas distintas para observación y operación. No existe acceso demo añadido por estos scripts.

El QR del fixture es emitido por la función SQL real y solo se introduce en el POST de check-in. No está en URLs, reportes o listados. El escenario de inasistencia parte de una reserva creada válidamente y envejecida **solo dentro del bootstrap aislado** para representar un antecedente vencido. No se modifica el reloj, ni se desactivan triggers o restricciones; no acredita haber esperado el vencimiento natural durante el recorrido.

Los recorridos mutan datos y no son idempotentes. Para repetir el navegador, detener las aplicaciones y ejecutar `New-BrowserDataset.ps1`, que crea **otra** base vacía con marca de propiedad sin eliminar las anteriores; luego iniciar Dataset browser. Conservar los reportes anteriores si se necesita comparar corridas.

Para repetir toda la regresión con integración real, `New-LegacyDataset.ps1` prepara otra base compatible con las guardas de Fase 9. No ejecutarlo mientras está activo Dataset legacy. Para empezar una ejecución completa después del cierre del contenedor, archivar antes el directorio privado de esa corrida: Start-Database se niega a sobrescribir `runtime.json`. No publicar ese archivo ni reutilizar credenciales de una corrida cerrada.

Resultados, XML, progreso, auditoría y capturas quedan en `backend_clinica/target/integration-fase12`, ignorado por Git. El progreso del navegador se guarda en JSON para evitar depender del buffering de consola de Windows.

## Cierre seguro

```powershell
powershell -NoProfile -File scripts/integration/Stop-Applications.ps1
powershell -NoProfile -File scripts/integration/Stop-Database.ps1
```

Después de conservar la evidencia no sensible, detener exclusivamente el contenedor del proyecto `clinica-fase12` usando su archivo `compose.integration.yml` y el entorno temporal. **Al detener el contenedor se pierde el contenido de tmpfs.** No usar `down -v` sobre el Compose habitual ni borrar bases compartidas. No subir fixtures, cookies, perfiles Chrome, claves o tokens a Git. La guía no requiere borrar o restaurar documentos del usuario.

## Regresión y diagnóstico

```powershell
cd frontend_clinica
php artisan route:list
php artisan test
php artisan view:cache
npm.cmd run build
.\vendor\bin\pint.bat --test
```

- **503 de autorización:** revisar Quarkus y su URL. La sesión se conserva ante una caída; no se permite operar hasta validar remotamente. Un 401 por revocación sí exige login.
- **403 entre paneles:** es el resultado esperado para otro rol; no ampliar permisos.
- **409 al reservar o editar agenda:** actualizar disponibilidad o corregir el conflicto real. No reintentar escrituras automáticamente.
- **419:** volver a cargar el formulario y comprobar cookie/CSRF. No excluir rutas del middleware.
- **Fechas:** PostgreSQL decide America/La_Paz y D..D+14. Mantener sincronizados los relojes de despliegue; no cambiar el del sistema para una demo.
- **Puerto ocupado:** no detener procesos ajenos. La preparación aislada rechaza puertos en uso.
- **Maven sin caché en modo offline:** repetir sin `-Offline` con acceso autorizado a Maven Central; no cambiar versiones.
- **Chrome/CDP no disponible:** registrar la parte de navegador como NO EJECUTADO; PHPUnit y capturas de HTML por sí solos no equivalen a ese recorrido.
- **Vite:** el aviso opcional de fontaine no impide compilar; comprobar carga real de Instrument Sans antes de añadir dependencias.

El ensayo local no certifica producción, capacidad, proveedores externos, HTTPS real o accesibilidad con lector de pantalla.
