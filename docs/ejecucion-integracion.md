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

Para esta verificación ejecutar los comandos explícitos: `composer setup` todavía genera clave local y no debe ejecutarse sobre una configuración existente. Fase 13 retiró de Composer la ejecución automática de migraciones Laravel. No ejecutar `boost:install`, reinstalar skills o cambiar versiones para realizar pruebas.

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

Iniciar PostgreSQL desde la raíz y comprobar su puerto, sin eliminar volúmenes:

```powershell
docker version
docker compose up -d postgres_db
docker compose ps postgres_db
docker compose port postgres_db 5432
```

El valor habitual es 5433; la URL JDBC debe coincidir con el valor real. [Guía DBeaver](configuracion-dbeaver.md). Las bases vacías usan B9 + R sin cuentas; las históricas conservan sus V y, si corresponde, V3 local original. No ejecutar scripts de credenciales demo: fueron retirados. [Inventario Flyway](../backend_clinica/src/main/resources/db/migration/README.md).

Si la instancia autorizada ya está activa en ese puerto, reutilizarla y omitir `compose up`; no iniciar otro contenedor sobre 5433 ni detener uno ajeno. En esta fase la base habitual existente se mantuvo intacta y solo se arrancó el proyecto aislado de integración.

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

Comprobar `/api/v1/auth/me` en Quarkus: sin Bearer debe responder **401**, y `/login` en Laravel debe responder **200**. Entrar con cuentas autorizadas; las redirecciones son `/paciente/inicio`, `/secretaria/inicio`, `/medico/inicio` y `/administracion/inicio`. Para uso habitual: entrar con una cuenta autorizada ROL004, abrir Administración y comprobar catálogos reales; cerrar sesión; entrar con una cuenta ROL002 con perfil Secretario activo y abrir Secretaría para consultar citas reales. Si no existe personal, el login informa que se requiere su alta por el responsable; no hay accesos predeterminados. La propuesta para el primer administrador está en la guía DBeaver y no se ejecuta automáticamente.

`docker-compose.yml` mantiene el stack habitual y su perfil `fullstack`. pgAdmin queda como herramienta opcional bajo el perfil `pgadmin`, fuera del arranque normal: DBeaver no requiere sus credenciales. Para habilitarlo, definir correo y contraseña individuales en el entorno privado; los valores vacíos hacen que pgAdmin rechace su arranque, sin contraseña predeterminada. Los runtimes fullstack declaran Java 25 y PHP 8.5. Ese perfil necesita dependencias Composer y assets del proyecto montado; la imagen PHP CLI no incluye Composer o Node. Esta fase utiliza PostgreSQL en Compose y los runtimes nativos instalados; no acredita la ejecución completa de fullstack.

## Integración aislada reproducible

Ejecutar estos comandos **desde la raíz**, en PowerShell. Los scripts no cambian `.env`, roles, migraciones ni credenciales habituales.

```powershell
powershell -NoProfile -File scripts/integration/Start-Database.ps1
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Backend
powershell -NoProfile -File scripts/integration/Write-Fixtures.ps1
powershell -NoProfile -File scripts/integration/Start-Applications.ps1 -Dataset legacy
```

La base es un proyecto Compose **clinica-fase12** separado. PostgreSQL se publica solo en `127.0.0.1:35441`, sin volumen persistente compartido y con datos en tmpfs. La contraseña se genera aleatoriamente y se conserva únicamente en `backend_clinica/target/integration-fase12/runtime.json`, ignorado por Git. No imprimir ese archivo.

Ejecutar las etapas en orden: terminar `Backend` antes de iniciar Quarkus en dev, porque ambos procesos comparten clases y cachés en `target`. `Start-Applications.ps1` espera hasta tres minutos el 401 real de Quarkus y el 200 del login, y detiene sus propios procesos si no quedan listos. El verificador comprueba el código de salida de Maven/Node/PHP, sin confundir sus diagnósticos en stderr con un fallo de ejecución. PHPUnit fija sus variables de entorno de prueba aunque la terminal herede `APP_ENV=production`; los servidores HTTP separados conservan sesiones y CSRF reales.

Las pruebas internas fijan tanto `ENV` como `SERVER` y usan `http://quarkus.test/api/v1` con `Http::fake`, sin heredar el backend habitual ni el servidor de fallos. Las suites Live configuran explícitamente la URL del fixture aislado y comprueban sus guardas antes de usar HTTP real. No cambiar la seguridad de los servidores para hacer pasar tests internos.

La suite backend usa `clinica_fase12_tests`; las pruebas heredadas usan una base nueva `clinica_fase9_live_f12` por compatibilidad de sus guardas. El navegador usa `clinica_fase12_e2e_<sufijo>` con una marca de propiedad que verifica el fixture antes de migrar. Se rechazan esquemas existentes. Las pruebas de actualización crean bases auxiliares exclusivamente en este contenedor.

Quarkus ocupa 18090; Laravel sano 18091; otro Laravel con transporte deliberadamente caído ocupa 18092 y comparte solo la sesión temporal. Esperar HTTP 401 sin Bearer en `http://127.0.0.1:18090/api/v1/auth/me` y HTTP 200 en `http://127.0.0.1:18091/login` antes de continuar. El arranque del JVM/Maven en frío puede tardar varios minutos. Los procesos se ejecutan con ventana oculta y quedan registrados para detener exclusivamente esos PID.

```powershell
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Legacy
powershell -NoProfile -File scripts/integration/Stop-Applications.ps1
powershell -NoProfile -File scripts/integration/Start-Applications.ps1 -Dataset browser
# Esperar nuevamente 401 en /api/v1/auth/me y 200 en /login.
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Browser
powershell -NoProfile -File scripts/integration/Audit-Database.ps1
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Contract
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Fault
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Expiry
powershell -NoProfile -File scripts/integration/Invoke-Verification.ps1 -Mode Visual
```

`Backend` ejecuta `mvnw.cmd -DskipITs=false verify`; `-Offline` opcional usa la caché Maven ya instalada. `Legacy` activa las suites reales de Fase 9 además de la regresión Laravel completa. `Browser` usa formularios normales, sesiones, CSRF, Quarkus y persistencia reales. `Fault` utiliza un proxy de errores **simulados**, conservando autorización contra Quarkus real; sus resultados se clasifican separadamente. Requiere el puerto 18093 libre y no modifica la configuración de producción.

`Contract` verifica los límites corregidos y errores reales mediante HTTP. `Expiry` arranca otra aplicación Quarkus empaquetada en 18094 y un Laravel en 18095; reduce solo el TTL de ese proceso a un minuto y espera su vencimiento natural con HTTP/cookies/CSRF. No modifica el reloj ni atribuye esa prueba a Chrome. `Visual` utiliza Laravel en modo producción en 18096 y la base real aislada para capturas/teclado/responsive; sus procesos secundarios se cierran al terminar.

Los usuarios sintéticos de cuatro roles, observadores y dos secretarios están en el fixture privado `target/integration-fase12/browser-fixture.json`; el test los lee en memoria. Exclusivamente para QA aislado, consultar ese fixture local en privado y entrar por `/login`, sin copiar credenciales a este documento, capturas o mensajes. Un nuevo login revoca la sesión anterior de la misma cuenta: utilizar cuentas distintas para observación y operación. No existe acceso demo añadido por estos scripts.

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
