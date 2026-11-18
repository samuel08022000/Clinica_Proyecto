# Google OAuth en Laravel

El inicio de sesión, el registro de pacientes y la vinculación de identidades se ejecutan
completamente en `frontend_clinica`. No necesitan Quarkus ni un backend externo.

## Configuración local

Reutilice el mismo cliente OAuth de Google que se usaba antes de la migración.
Guarde las credenciales originales únicamente en `frontend_clinica/.env`, que está
excluido de Git:

```dotenv
CLINIC_GOOGLE_OAUTH_ENABLED=true
GOOGLE_CLIENT_ID=<client-id-original>
GOOGLE_CLIENT_SECRET=<client-secret-original>
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

No son la contraseña de aplicación de Gmail ni las credenciales SMTP. No cree otro
cliente OAuth para restaurar esta integración. Si no conserva el secreto original,
recupérelo de su configuración privada o de la configuración del mismo cliente en
Google Cloud. No lo coloque en documentación, código, commits ni mensajes.

Sincronice las dependencias con `composer install`; `firebase/php-jwt` ya está fijada
en `composer.lock`. Después de configurar el entorno, ejecute `php artisan config:clear`
y reinicie el proceso Laravel si mantiene configuración en memoria.

En el cliente existente de Google Cloud, el URI autorizado debe coincidir exactamente
con `GOOGLE_REDIRECT_URI`. El callback de Laravel es `/auth/google/callback`.
`localhost` y `127.0.0.1`, los puertos, el protocolo y la barra final son distintos.
Si la aplicación usa otro dominio, conserve ese dominio tanto en Google Cloud como en
Laravel. En modo de prueba, la cuenta utilizada debe estar autorizada como usuario de prueba.

## Bases de datos

Los usuarios, pacientes e identidades externas utilizan la conexión Laravel `clinical`,
configurada con `CLINICAL_DB_HOST`, `CLINICAL_DB_PORT`, `CLINICAL_DB_DATABASE`,
`CLINICAL_DB_USERNAME`, `CLINICAL_DB_PASSWORD` y `CLINICAL_DB_SSLMODE`. Estas variables deben
apuntar a la misma base clínica ya instalada; configurar solo `DB_*` no configura `clinical`.

Las continuaciones de registro y vinculación se almacenan cifradas en `auth_challenges`,
en la conexión predeterminada de Laravel. Esa tabla se crea con la migración específica:

```sh
php artisan migrate --path=database/migrations/2026_11_14_140000_create_auth_challenges_table.php
```

Las migraciones requieren la cuenta de mantenimiento de la base. La cuenta habitual de
la aplicación solo necesita SELECT, INSERT, UPDATE y DELETE sobre `auth_challenges`;
no necesita permisos para crear tablas. No cambie las credenciales habituales por las
de mantenimiento. No ejecute `migrate:fresh` sobre una base con usuarios.

## Flujo

- `/auth/google` inicia el acceso y `/registro/google` inicia el registro.
- Laravel conserva `state`, `nonce` y el verificador PKCE en su sesión.
- Cada intento conserva su URI de retorno y su propio estado durante diez minutos;
  se mantienen como máximo cinco intentos pendientes. Una respuesta con estado ajeno
  no consume los intentos válidos. Un callback válido se consume antes del canje y no
  puede reutilizarse, incluso si Google rechaza el código.
- Si el inicio usa `localhost` y el callback configurado usa `127.0.0.1` (o viceversa),
  Laravel lleva primero el navegador al origen configurado antes de crear el estado.
- El callback intercambia el código directamente con Google y valida firma, emisor,
  audiencia, expiración, `nonce` y, cuando corresponde, `azp`.
- Una identidad vinculada a un paciente activo inicia una sesión Laravel y abre su panel.
- Una identidad nueva solicita CI, nacimiento, teléfono y ciudad. Crea únicamente `ROL001`.
- Un correo que ya tiene cuenta exige iniciar sesión con contraseña antes de vincular Google.
  La coincidencia de correo por sí sola nunca vincula una cuenta.
- Una identidad ya vinculada no se reemplaza si se intenta vincular otra cuenta Google.
- Un CI duplicado revierte la creación incompleta y permite corregir el formulario.
- Las continuaciones expiran y se consumen una sola vez al completar la operación.
- Los roles de personal conservan su acceso tradicional y sus paneles existentes.
  Google sigue limitado a pacientes, conforme al comportamiento anterior.

Los botones están visibles en ambos formularios y se habilitan al activar la integración,
instalar la dependencia y configurar el Client ID y el Client Secret. No se habilitan con
credenciales vacías. Google confirma el correo; no confirma el teléfono.

## Validación

### Conexión HTTPS de PHP en Windows

El 17/11/2026 se reprodujo un fallo del entorno XAMPP: `curl.cainfo` y
`openssl.cafile` estaban vacíos y el acceso a Google fallaba con cURL 60
(`unable to get local issuer certificate`). Esto impedía el canje antes de validar
el ID token. El controlador agrupaba ese fallo de conexión con los errores de autorización.

Se configuró `C:/xampp/php/php.ini` para que ambas opciones apunten a
`C:/xampp/php/extras/ssl/cacert.pem`, usando una copia del bundle de autoridades
confiables instalado por Git. Se conservó `php.ini.before-clinica-oauth.bak`.
El cliente HTTP real de Laravel pudo después consultar las claves de Google con
HTTP 200 y acceder al endpoint de tokens (GET devuelve 404; el canje requiere POST).
La verificación TLS permanece activa. Mantenga actualizado el bundle de autoridades
de confianza y reinicie PHP después de cambiar su configuración.

Los fallos de conexión ahora se identifican separadamente y registran únicamente la
clase de excepción y el número cURL, sin código OAuth, tokens ni credenciales.
No use `withoutVerifying()` ni `verify=false` para resolverlos.

Los botones ya estaban presentes en las vistas al iniciar este diagnóstico. Tras
limpiar configuración, rutas y vistas compiladas se verificaron habilitados en
Chrome y mediante solicitudes HTTP reales a `/login` y `/registro`. No se pudo
reproducir su ausencia anterior ni atribuirla con certeza a una caché concreta.

### Alcance de las pruebas

Resultados del diagnóstico del 17/11/2026:

- OAuth y recuperación (Feature): 26 pruebas aprobadas, 145 comprobaciones.
- Autenticación en PostgreSQL aislado: 15 pruebas aprobadas, 179 comprobaciones;
  prueba adicional de identidad vinculada a otro paciente: 1 aprobada, 3 comprobaciones.
- Suite Feature completa: 266 pruebas ejecutadas, 262 aprobadas, 4 fallos HTTP 403
  en `PatientPanelTest` y 3 pruebas marcadas como riesgosas. La suite completa no está verde.
  El controlador de citas recibe `PatientAppointmentService` directamente mientras las
  pruebas simulan `PatientAppointmentManager`. Se conservó ese módulo sin cambios.
- Solicitudes HTTP reales locales: ambos formularios devuelven 200 y botones habilitados;
  inicio OAuth redirige a Google; callback de cancelación devuelve 302 y conserva el
  aviso en la sesión al volver al login.
- Chrome: botones de acceso y registro comprobados; inicio de registro llega al selector
  de cuentas de Google. Falta completar la autorización con una cuenta elegida por el usuario.

Para la comprobación manual, inicie siempre un intento nuevo desde
`http://127.0.0.1:8000/login` o `/registro`; no reutilice una pestaña antigua de Google.
Una identidad nueva debe abrir el formulario de completar perfil y, tras guardar,
el panel del paciente. Una cuenta existente sin vínculo debe pedir su contraseña antes
de vincularse. Una identidad ya vinculada debe abrir directamente el panel. Cierre sesión
entre escenarios. Estos retornos reales aún no se han confirmado en este diagnóstico.

Las pruebas `GoogleOidcIdentityProviderTest` usan claves RSA temporales y respuestas HTTP
simuladas. `GoogleAndPasswordRecoveryFlowTest` comprueba rutas, botones, PKCE y rechazo de
solicitudes inválidas. `AuthenticationMigrationTest` comprueba creación de pacientes,
vinculación, sesiones, conflictos y acceso en PostgreSQL aislado.

Estas pruebas no equivalen a una autenticación real. Para completar la validación, abra
el formulario de acceso en el host configurado, continúe con una cuenta Google autorizada
y compruebe el retorno al panel. Pruebe el registro con un paciente de prueba nuevo y la
vinculación con una cuenta de prueba existente. Hasta completar estas comprobaciones
interactivas con las credenciales originales, el flujo real permanece pendiente.

Referencias oficiales:
- https://developers.google.com/identity/openid-connect/openid-connect
- https://developers.google.com/identity/protocols/oauth2/web-server
