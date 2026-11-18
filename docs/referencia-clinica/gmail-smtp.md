# Gmail SMTP para OTP en Laravel

El registro, el envío y la verificación OTP se ejecutan en `frontend_clinica`.
El correo se envía directamente con Laravel Mail y Symfony Mailer. No se necesita
el backend anterior ni sus variables `QUARKUS_MAILER_*` o `OTP_DELIVERY_PROVIDER`.

## Causa del problema

El entorno Laravel conservaba `MAIL_MAILER=log`, host `127.0.0.1`, puerto `2525`
y credenciales SMTP vacías. `AuthMailService::ensureTransport()` lo rechazaba antes
de crear la cuenta. `AuthController::registrationError()` convertía ese rechazo en
"La entrega OTP no está configurada. Intenta nuevamente más tarde."
No era un rechazo de Gmail: no se estaba intentando una conexión SMTP.

## Configurar el remitente

En `frontend_clinica/.env`, ignorado por Git:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_REQUIRE_TLS=true
MAIL_TIMEOUT=15
MAIL_USERNAME=""
MAIL_PASSWORD=""
MAIL_FROM_ADDRESS=""
MAIL_FROM_NAME="${APP_NAME}"
```

Complete `MAIL_USERNAME` con la cuenta Gmail remitente y `MAIL_FROM_ADDRESS` con
esa misma dirección, salvo que tenga un alias de envío autorizado por Gmail.
Complete `MAIL_PASSWORD` directamente en el archivo con una **nueva contraseña de
aplicación**, sin los espacios de presentación de Google. No use la contraseña
normal de Gmail, el secreto de Google OAuth ni credenciales SMTP expuestas.
No copie secretos en documentación, mensajes, capturas ni commits.

Active la verificación en dos pasos de la cuenta y genere la contraseña en
https://myaccount.google.com/apppasswords con un nombre como `Clinica Laravel`.
Algunas cuentas administradas requieren autorización de su administrador.

Laravel 13 usa `MAIL_SCHEME`; este proyecto no lee `MAIL_ENCRYPTION`.
Para el puerto 587, `smtp` junto con `MAIL_REQUIRE_TLS=true` exige STARTTLS antes
de autenticar. `smtps` corresponde a TLS implícito, normalmente en el puerto 465.
No desactive la comprobación de certificados ni permita envío sin TLS.
Si configura `MAIL_URL`, sus componentes pueden sobrescribir `MAIL_*`; deje esa
variable sin definir para esta configuración individual.

Después de editar el entorno, ejecute desde `frontend_clinica`:

```sh
php artisan config:clear
```

Reinicie el servidor Laravel si mantiene configuración en memoria. No cambie las
variables PostgreSQL ni Google OAuth para configurar SMTP.

## Flujo y controles conservados

- El registro valida datos y comprueba que exista un transporte de entrega.
- PostgreSQL genera seis dígitos con aleatoriedad criptográfica y guarda su hash SHA-256.
- Laravel envía `VerificationCodeMail` a la dirección del paciente registrado.
- El código vence a los cinco minutos, admite tres intentos y se consume al acertar
  o al agotar los intentos. El login permanece bloqueado hasta verificar el correo.
- El reenvío espera 60 segundos y permite como máximo cinco solicitudes por hora.
  Un código nuevo invalida los anteriores.
- La continuación de verificación dura 30 minutos y se conserva cifrada.
- Los fallos SMTP devuelven un mensaje controlado. El registro nuevo se revierte
  si el envío falla. Los fallos de reenvío conservan el OTP anterior mediante rollback.
- Los códigos y contraseñas no se registran. El diagnóstico de envío registra
  únicamente la clase de excepción, sin destinatarios ni conversaciones SMTP.
- Los transportes `log`, los fallbacks a `log` y `array` fuera de pruebas se rechazan.

## Verificación real pendiente

Se comprobó conexión real a `smtp.gmail.com:587`: saludo 220, STARTTLS anunciado,
certificado TLS validado y respuesta 250 después de negociar TLS. Esto no comprueba
autenticación ni entrega: faltan el remitente y una contraseña de aplicación nueva.

Después de configurarlos, registre un paciente de prueba con una dirección que
pueda consultar. Revise entrada y spam, introduzca el código en `/verificar-correo`
y compruebe el acceso mediante contraseña después de la verificación. Pruebe otra
dirección, un reenvío tras 60 segundos y el rechazo de un código ya utilizado.
La aceptación SMTP no garantiza llegada a la bandeja: confirme la recepción.

## Referencias

- https://laravel.com/framework/docs/13.x/mail
- https://symfony.com/doc/current/mailer.html
- https://developers.google.com/workspace/gmail/imap/imap-smtp
- https://support.google.com/accounts/answer/185833
