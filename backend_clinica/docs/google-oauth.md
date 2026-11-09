# Configurar Google Sign-In (OAuth 2.0 / OpenID Connect)

Google Sign-In está deshabilitado por defecto. El login tradicional y el registro con
OTP siguen funcionando sin credenciales de Google. No confunda estas credenciales OAuth
con la contraseña de aplicación de Gmail SMTP: son mecanismos independientes.

## Arquitectura y garantías

Laravel inicia el flujo, genera `state`, `nonce` y el verificador PKCE, y los conserva
en su sesión cifrada. Quarkus construye la URL autorizada, intercambia el código y valida
criptográficamente el ID token con la biblioteca oficial de Google: firma, emisor,
audiencia, expiración y `nonce`. La identidad se busca por `sub`, nunca solo por correo.
Los tokens de Google no se guardan en PostgreSQL ni se entregan al navegador después del
callback; Quarkus emite el token opaco interno existente.

Referencias oficiales:

- <https://developers.google.com/identity/openid-connect/openid-connect>
- <https://developers.google.com/identity/protocols/oauth2/web-server>
- <https://developers.google.com/identity/gsi/web/guides/verify-google-id-token>

## Crear el cliente en Google Cloud

1. Entre a <https://console.cloud.google.com/> con la cuenta administradora correcta.
2. Cree o seleccione un proyecto exclusivo para la clínica.
3. Abra **Google Auth Platform** y configure la pantalla de consentimiento: nombre de
   la aplicación, correo de soporte y datos de contacto. Seleccione audiencia interna
   solo si todas las cuentas pertenecen a una organización Google Workspace; para una
   clínica con pacientes externos use audiencia externa.
4. Mientras la aplicación esté en modo de prueba, agregue explícitamente las cuentas
   autorizadas como usuarios de prueba. No publique la aplicación solo para probar local.
5. En **Clients**, cree un cliente OAuth de tipo **Web application**.
6. Registre exactamente este URI de redirección local:

   ```text
   http://127.0.0.1:8000/auth/google/callback
   ```

   `localhost` y `127.0.0.1` son distintos para Google. El valor registrado debe coincidir
   byte por byte con ambos archivos `.env`.
7. Copie el Client ID y Client Secret únicamente al archivo local
   `backend_clinica/.env`. No los coloque en Blade, JavaScript, README ni Git.

## Activar localmente

En `backend_clinica/.env`:

```dotenv
GOOGLE_OAUTH_ENABLED=true
GOOGLE_CLIENT_ID=<client-id-local>
GOOGLE_CLIENT_SECRET=<client-secret-local>
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

En `frontend_clinica/.env`:

```dotenv
CLINIC_GOOGLE_OAUTH_ENABLED=true
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

Después reinicie Quarkus y Laravel (o ejecute `php artisan config:clear`). Abra
`http://127.0.0.1:8000/login`, seleccione **Continuar con Google** y use una cuenta de
prueba autorizada.

## Comportamiento esperado

- Identidad ya vinculada a un paciente: inicia una sesión interna de paciente.
- Correo ya existente sin vínculo: exige primero el login tradicional; nunca vincula
  automáticamente por coincidencia de correo.
- Identidad nueva: solicita CI, fecha de nacimiento, teléfono y ciudad; siempre crea
  `ROL001`.
- Personal clínico o administración: Google no puede registrar ni elevar esos roles.
- `email_verified=true`: confirma únicamente el correo; no confirma el teléfono.

Si aparece `redirect_uri_mismatch`, revise protocolo, host, puerto, ruta y barra final.
Si Google muestra acceso bloqueado en modo de prueba, agregue esa cuenta como usuario de
prueba. No desactive la validación TLS, `state`, `nonce`, PKCE ni la firma para resolver
problemas de configuración.

El login con Google real permanece pendiente de validación hasta configurar este cliente
y completar una prueba interactiva. Las pruebas automatizadas usan identidades validadas
simuladas y no contactan Google.
