# Configurar Gmail SMTP para OTP y recuperación

El mismo proveedor entrega los OTP de registro y los enlaces de recuperación. El sistema
inicia con `OTP_DELIVERY_PROVIDER=demo`. No cambie a SMTP hasta completar
todos los pasos y probar la cuenta remitente. Las credenciales se guardan únicamente
en `backend_clinica/.env`, archivo ignorado por Git.

## Preparar la cuenta de Google

1. Elija la cuenta Gmail que enviará los mensajes de la clínica. Conviene utilizar una
   cuenta institucional dedicada y no una cuenta personal compartida.
2. Abra la sección **Seguridad** de la cuenta de Google y active la **Verificación en
   dos pasos**. Google exige esta protección para crear contraseñas de aplicación.
3. Abra <https://myaccount.google.com/apppasswords>, vuelva a autenticarse y cree una
   contraseña para una aplicación identificable, por ejemplo `Clinica Quarkus local`.
4. Google mostrará una contraseña de aplicación de 16 caracteres una sola vez. No es
   la contraseña normal de Gmail. Cópiela directamente al `.env` local y no la envíe
   por chat, correo, capturas ni Git.

La opción de contraseñas de aplicación puede no aparecer en cuentas de trabajo o
estudio administradas, cuentas con Protección Avanzada o configuraciones que usan
exclusivamente llaves de seguridad. En esos casos el administrador debe autorizar SMTP
o se debe utilizar otro proveedor aprobado.

## Configurar `backend_clinica/.env`

```dotenv
OTP_DELIVERY_PROVIDER=smtp

QUARKUS_MAILER_HOST=smtp.gmail.com
QUARKUS_MAILER_PORT=587
QUARKUS_MAILER_START_TLS=REQUIRED
QUARKUS_MAILER_TLS=false
QUARKUS_MAILER_MOCK=false
QUARKUS_MAILER_LOGIN=REQUIRED
QUARKUS_MAILER_AUTH_METHODS=DIGEST-MD5 CRAM-SHA256 CRAM-SHA1 CRAM-MD5 PLAIN LOGIN

QUARKUS_MAILER_USERNAME=remitente@gmail.com
QUARKUS_MAILER_FROM=remitente@gmail.com
QUARKUS_MAILER_PASSWORD=<contraseña-de-aplicación>
```

`USERNAME` y `FROM` deben corresponder a la cuenta remitente autorizada. No agregue
comillas ni espacios a la contraseña de aplicación. Mantenga `TLS=false` junto con
`START_TLS=REQUIRED`: la conexión comienza en el puerto 587 y se eleva obligatoriamente
a TLS antes de autenticar.

## Reiniciar y comprobar

1. Detenga Quarkus si está ejecutándose.
2. Desde `backend_clinica`, inicie nuevamente `./mvnw quarkus:dev`. La contraseña se
   evalúa al arrancar; modificar `.env` sin reiniciar no actualiza la conexión.
3. Registre un paciente ficticio con una dirección que pueda consultar.
4. Confirme que Laravel muestra que el correo fue entregado al proveedor SMTP.
5. Revise entrada, promociones y correo no deseado. Use el OTP recibido una sola vez.
6. Confirme que el login se rechaza antes de validar y funciona después.
7. Solicite una recuperación desde `/recuperar-contrasena` y compruebe que el enlace
   recibido permite un único cambio, revoca las sesiones anteriores y exige volver a iniciar sesión.

## Si Gmail rechaza la autenticación

- Compruebe que se usó la contraseña de aplicación, no la contraseña normal.
- Verifique que la autenticación en dos pasos continúa activa.
- Si cambió la contraseña principal de Google, genere una nueva contraseña de
  aplicación: Google revoca las anteriores después de ese cambio.
- Confirme que `QUARKUS_MAILER_MOCK=false`, el puerto es `587` y STARTTLS es `REQUIRED`.
- Revise si la cuenta pertenece a una organización que bloquea contraseñas de aplicación.
- No habilite `trust-all` ni desactive la validación TLS para sortear errores de certificado.

Si el envío falla, Quarkus responde con un error controlado y Laravel no presenta el
mensaje como entregado. El contenido del correo y el OTP no deben registrarse en logs.
