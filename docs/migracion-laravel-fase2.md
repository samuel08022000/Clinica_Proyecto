# Migración a Laravel — Fase 2: autenticación y seguridad

Informe de implementación y guía local. Comprobaciones realizadas el **15 de noviembre de 2026**.

## Estado ejecutivo

Laravel es ahora la autoridad de autenticación local contra `public.usuarios`. Funcionan sin llamadas HTTP a Quarkus: login bcrypt, registro de pacientes, OTP, recuperación y restablecimiento de contraseña, sesiones Laravel, logout, revalidación de roles/perfiles y auditoría SQL. Los nombres de rutas y las vistas Blade existentes se conservaron.

El flujo Google quedó **preparado y probado mediante un proveedor simulado**, pero permanece cerrado en producción. `composer.json` y `composer.lock` no contienen una biblioteca OIDC capaz de validar criptográficamente firma/JWK, issuer, audience, `azp`, expiración y nonce. No se improvisó esa validación ni se instaló software. Antes de habilitarlo debe incorporarse y fijarse una versión compatible de `google/apiclient` —o una biblioteca OIDC equivalente que cubra todo ese contrato— y sustituirse `UnavailableGoogleIdentityProvider`. La versión concreta debe resolverse en una operación autorizada con acceso al repositorio de paquetes.

Los paneles de Administración, Secretaría y Médico conservan sus controladores de dominio Quarkus para Fase 3. Una sesión emitida por Laravel no puede enviarse como token Quarkus: `QuarkusApiClient` rechaza expresamente esa mezcla. Por ello sus pantallas no deben considerarse migradas aunque el middleware Laravel ya proteja y revalide sus rutas.

## A. Funcionalidades

### Login, roles y logout

- `Usuario` usa conexión `clinical`, tabla `usuarios`, PK textual `cod_usu`, sin autoincremento ni timestamps inventados.
- Se comprueban hashes bcrypt existentes sin rehash forzado ni doble hash. Las cuentas Google con `password_hash IS NULL` no admiten login local.
- Tras las credenciales se revalidan usuario, rol y perfil en PostgreSQL. Paciente exige perfil, correo verificado y ausencia de bloqueo efectivo; Secretaría y Médico exigen perfil activo; Administrador exige cuenta y rol activos.
- Roles: `ROL001` → Paciente, `ROL002` → Secretaría, `ROL003` → Médico y `ROL004` → Administración. El middleware consulta PostgreSQL en cada petición protegida y no confía solo en la sesión.
- Login regenera el identificador, revoca sesiones anteriores del mismo usuario, registra `auth_authority=laravel` y aplica expiración absoluta equivalente a 120 minutos. Logout invalida sesión y rota CSRF.
- Cinco fallos por combinación correo/IP dentro de 15 minutos producen bloqueo temporal; el mensaje no distingue cuenta inexistente de contraseña incorrecta.
- Tokens opacos y sesiones fabricadas para Quarkus no son transferibles ni aceptados.

### Registro y OTP

- El alta pública conserva documento, correo, datos personales, contraseña segura y rol `ROL001`.
- Se reutiliza `registrar_paciente(...)`; usuario y perfil se crean en una transacción PostgreSQL. No se creó una segunda tabla de usuarios.
- Se reutilizan `crear_otp_email(text)` y `validar_otp_email(text,text)`: expiración de 5 minutos, reenvío después de 60 segundos, límite horario SQL, tres intentos y consumo único.
- El desafío web es opaco, se almacena por hash SHA-256 en `auth_challenges` y su payload queda cifrado con `APP_KEY`. No hay estado estático PHP ni código OTP en respuesta.
- El rechazo de un OTP incorrecto se lanza **después** de confirmar la transacción que ejecuta `validar_otp_email`; así el incremento de `intentos` no se revierte. La prueba PostgreSQL real verificó valores 1 y 3 y el consumo al tercer fallo.
- El servicio de correo rechaza el transport `log` para operaciones reales. “Aceptado por el transporte” no se presenta como entrega efectiva confirmada. Las pruebas usan `Mail::fake()`.

### Recuperación

- La solicitud siempre devuelve una respuesta neutral.
- Se reutilizan `crear_token_recuperacion(text)` y `restablecer_contrasena(text,text,text)`; no se añadió otra tabla clínica.
- El token expira, es de un solo uso y nunca se registra. La nueva contraseña se guarda como bcrypt.
- El actor de auditoría del cambio se resuelve por la cuenta que corresponde al correo/token, no por un campo enviado como actor.
- Un restablecimiento exitoso revoca todas las sesiones Laravel persistentes del usuario y exige un nuevo login.

### Google OAuth/OIDC

- La orquestación genera `state`, nonce, verifier PKCE y `S256`, fija redirect URI, limita la continuación a 10 minutos y la consume una sola vez.
- No se enlaza por mera coincidencia de correo. Si el correo local existe, el usuario debe iniciar sesión local y la vinculación exige mismo correo normalizado, rol Paciente y sesión autorizada.
- La identidad estable es `(proveedor='google', identificador_externo)` y una cuenta Google puede quedar sin contraseña local.
- Los casos state, nonce, expiración, firma, audience, continuación reutilizada y vinculación no autorizada están cubiertos con un proveedor simulado.
- **Bloqueo externo:** no hay validación OIDC criptográfica real disponible en las dependencias locales. El proveedor de producción falla cerrado y el botón queda deshabilitado.

## B. Archivos

### Creados

- `app/Models/{Usuario,Rol,Paciente,Secretaria,Medico}.php`: mapeo explícito del esquema clínico.
- `app/Exceptions/AuthDomainException.php`: errores de dominio controlados.
- `app/Contracts/GoogleIdentityProvider.php`: frontera del proveedor OIDC validado.
- `app/Repositories/{AuthAccountRepository,GoogleIdentityRepository}.php`: estado de cuenta y persistencia de identidad externa.
- `app/Services/Auth/`: `AuthenticationService`, `AccountStatusService`, `SessionService`, `LoginAttemptService`, `PatientRegistrationService`, `OtpService`, `PasswordRecoveryService`, `GoogleAuthService`, `ChallengeStore`, `AuthMailService`, `PasswordPolicy` y `UnavailableGoogleIdentityProvider`.
- `app/Services/Clinical/LaravelClinicalActorProvider.php`: actor SQL derivado exclusivamente del guard Laravel.
- `app/Mail/{VerificationCodeMail,PasswordResetMail}.php` y `resources/views/mail/*`: mensajes sin loguear secretos.
- `database/migrations/2026_11_14_140000_create_auth_challenges_table.php`: continuaciones temporales persistentes fuera del esquema clínico.
- `database/migrations/2026_11_14_140100_make_session_user_id_clinical.php`: permite `cod_usu` textual en sesiones Laravel.
- `scripts/clinical/phase2-permissions.sql` y `Apply-Phase2-Permissions.ps1`: privilegios mínimos y reproducibles en las dos bases aisladas.
- `tests/Postgres/AuthenticationMigrationTest.php`: integración real PostgreSQL.

### Modificados

- `app/Http/Controllers/AuthController.php`: sustituye las llamadas Quarkus de autenticación por servicios Laravel conservando rutas, vistas y redirecciones.
- `app/Http/Middleware/CheckRolSession.php`: guard Laravel más revalidación PostgreSQL.
- `app/Providers/AppServiceProvider.php`: actor Laravel y proveedor Google cerrado por defecto.
- `app/Services/QuarkusApiClient.php`: impide usar credenciales Laravel contra endpoints Java pendientes.
- `config/auth.php`: provider `Usuario`.
- `config/clinic.php` y `.env.example`: flags/variables Google, conexión de sesiones/caché y secretos vacíos.
- `tests/Feature/{AuthFlowTest,RegistrationOtpFlowTest,GoogleAndPasswordRecoveryFlowTest}.php`: reemplazan contratos HTTP Quarkus por contratos Laravel, CSRF y fail-closed.
- `tests/Postgres/ClinicalInfrastructureTest.php`: reconoce el permiso mínimo nuevo de ejecutar OTP sin conceder lectura de su tabla.

No se modificaron vistas Blade de autenticación, rutas existentes, migraciones SQL históricas, `config/mail.php`, `config/session.php`, el esquema B9/V10/R ni Quarkus. Las migraciones PHP son solo para la base de infraestructura Laravel; nunca deben ejecutarse con `--database=clinical`.

## C. PostgreSQL y auditoría

Tablas clínicas usadas: `usuarios`, `roles`, `pacientes`, `secretarias`, `medicos`, `usuarios_identidades_externas`, `verificaciones_otp`, `tokens_recuperacion` y `bitacora`. Se reutilizan:

```text
registrar_paciente(text,text,text,text,date,text,text)
crear_otp_email(text)
validar_otp_email(text,text)
crear_token_recuperacion(text)
restablecer_contrasena(text,text,text)
estado_reserva_paciente(text)
```

Los triggers clínicos permanecen activos, incluida auditoría de `usuarios`, `pacientes` e identidades relacionadas, y la inmutabilidad de `bitacora`. `LaravelClinicalActorProvider` entrega el `cod_usu` autenticado a `ClinicalTransaction`; recuperación y vinculación Google fijan `set_config('app.cod_usu', ..., true)` en la misma conexión/transacción. El registro público utiliza la semántica existente de `registrar_paciente` sin aceptar un actor arbitrario.

`clinica_app` recibió EXECUTE solo sobre las cinco funciones de escritura de esta fase y permisos de columnas/secuencias mínimos para alta/vinculación Google. Conserva ausencia de superusuario, CREATE de esquema, lectura de secretos QR, lectura directa de OTP e INSERT directo en bitácora.

La base principal sigue con **21 tablas, 2 vistas, 42 funciones, 32 triggers, 40 FK, 80 índices, 2 exclusiones, 0 restricciones inválidas, 0 triggers deshabilitados y 0 usuarios**. Historial: B9, V10 y R, todos correctos. Las pruebas crean únicamente identidades sintéticas `example.invalid` en `clinica_laravel_phase1_tests`; la bitácora es inmutable por diseño, por lo que no se falsea una limpieza destructiva de ese historial.

## D. Seguridad

- Bcrypt compatible, límite máximo de 72 bytes y política de al menos 10 caracteres con mayúscula, minúscula y número.
- Comparación bcrypt ficticia para reducir diferencias temporales cuando no existe cuenta o hash local.
- Cookie HttpOnly, sesión cifrada, SameSite Lax y Secure configurable; en HTTPS productivo debe establecerse `SESSION_SECURE_COOKIE=true`.
- CSRF se conserva en todos los formularios; sesiones y desafíos usan almacenamiento persistente de Laravel, separado de `clinical`.
- Revocación por nuevo login, restablecimiento, inactivación, cambio de rol/perfil y expiración absoluta.
- Consultas parametrizadas; no se acepta `cod_usu`, rol ni actor enviados por formularios.
- OTP, tokens de recuperación, verifier, nonce y continuaciones no se escriben en logs. Las continuaciones se almacenan hasheadas y cifradas.
- Google falla cerrado mientras no haya verificación criptográfica completa.

## E. Pruebas reales

| Comando / grupo | Resultado |
|---|---|
| `tests/Postgres/AuthenticationMigrationTest.php` | **12 pruebas, 133 aserciones, aprobadas** sobre PostgreSQL 16 aislado |
| `tests/Postgres/ClinicalInfrastructureTest.php` | **5 pruebas, 128 aserciones, aprobadas** |
| Tres suites Feature de autenticación | **15 pruebas, 99 aserciones, aprobadas** |
| Subtotal específico Fase 1 + Fase 2 | **32 pruebas, 360 aserciones, 0 fallos** |
| Suite Laravel completa | **246 pruebas: 40 aprobadas, 194 fallidas y 12 omitidas** en la ejecución final |
| `composer validate --no-check-publish` | correcto |
| `php artisan route:list` | 83 rutas cargadas |
| `npm.cmd run build` | compilación Vite correcta; aviso no bloqueante por paquete opcional `fontaine` ausente |
| `php -l` sobre aplicación, migraciones y pruebas PostgreSQL | sin errores de sintaxis |
| Pint sobre archivos de esta fase | correcto después de formatear los dos Mailables |

Los 194 fallos de la suite global no se ocultaron: pertenecen principalmente a pruebas de paneles de Fase 3 que inyectan directamente `auth_token/cod_rol` de Quarkus en la sesión. El middleware nuevo los rechaza deliberadamente porque esas credenciales no son compatibles. No se relajó el control de seguridad ni se modificaron cientos de aserciones de dominio para simular una migración aún no realizada. Deben reemplazarse al migrar cada panel en Fase 3.

Google real no fue probado ni se declara operativo. SMTP automatizado se simuló; no se declara entrega efectiva. Las rutas de correo reales requieren un transporte SMTP privado válido.

## F. Integración y siguiente fase

Listo sin Quarkus: login local, registro de Paciente, OTP, recuperación/restablecimiento, sesiones, logout y autorización/revalidación de roles.

Parcial: Google OAuth/OIDC —orquestación y persistencia listas; proveedor criptográfico real bloqueado por dependencia ausente—.

Pendiente Fase 3: sustituir llamadas de dominio de Administración y Secretaría y las operaciones Médico restantes; cambiar sus pruebas para autenticar `Usuario` Laravel real; retirar entonces el corte temporal de `QuarkusApiClient`. Ningún panel debe recibir un token Quarkus derivado de una sesión Laravel.

## G. Guía PowerShell

Desde la raíz del proyecto:

```powershell
# 1. Iniciar únicamente PostgreSQL clínico aislado
.\scripts\clinical\Start-Database.ps1

# 2. Verificar esquema principal sin mostrar secretos
.\scripts\clinical\Verify-Database.ps1

# 3. Aplicar/verificar los permisos idempotentes de Fase 2 (solo bases aisladas)
.\scripts\clinical\Apply-Phase2-Permissions.ps1

# 4. Migrar solo la infraestructura Laravel local; nunca usar --database=clinical
Set-Location .\frontend_clinica
php artisan migrate
Set-Location ..

# 5. Iniciar Laravel con la conexión clinical inyectada en el proceso
.\scripts\clinical\Invoke-Laravel.ps1 artisan serve --host=127.0.0.1 --port=8000
```

Con Laravel activo:

1. Abrir `http://127.0.0.1:8000/registro` y usar únicamente datos sintéticos autorizados. No hay contraseña predeterminada.
2. Con SMTP privado configurado en `.env`, comprobar el correo y capturar el OTP por el buzón; el sistema no lo imprime en pantalla ni en logs. Completar `/verificar-correo`.
3. Iniciar sesión en `/login`; Paciente debe llegar a `/paciente/inicio`.
4. Para comprobar roles se requieren cuentas sintéticas activas creadas de forma controlada en la base de pruebas. No alterar roles de cuentas reales ni usar datos demo en la principal.
5. Cerrar mediante el formulario POST `/logout` y confirmar que la ruta protegida redirige a login.
6. En `/recuperar-contrasena`, solicitar el enlace; la respuesta será neutral exista o no la cuenta. Usar una contraseña nueva y confirmar que la sesión anterior queda revocada.
7. Confirmar que Quarkus no está activo:

```powershell
docker ps --format "table {{.Names}}`t{{.Image}}`t{{.Ports}}"
Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue
```

La ausencia de salida en el segundo comando confirma que no hay proceso escuchando en el puerto habitual 8080. PostgreSQL y Laravel no necesitan Quarkus para los flujos migrados.

Para ejecutar las verificaciones:

```powershell
.\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres/AuthenticationMigrationTest.php
.\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres/ClinicalInfrastructureTest.php
Set-Location .\frontend_clinica
vendor\bin\phpunit tests\Feature\AuthFlowTest.php tests\Feature\RegistrationOtpFlowTest.php tests\Feature\GoogleAndPasswordRecoveryFlowTest.php
Set-Location ..
```

Detener sin perder datos:

```powershell
.\scripts\clinical\Stop-Database.ps1
```

El volumen `clinica_laravel_fase1_pg16_data` no se elimina.

## H. Estado final

- **Completamente implementado:** login local, registro Paciente, OTP, recuperación/restablecimiento, sesiones, logout, roles y auditoría de esta fase.
- **Parcialmente implementado:** Google OAuth/OIDC; flujo interno completo y pruebas simuladas, sin proveedor criptográfico real.
- **Pendiente:** operaciones de dominio de los paneles y reemplazo de sus pruebas Quarkus en Fase 3.
- **Bloqueado por dependencia externa:** validación OIDC real y prueba con Google; SMTP real depende de credenciales/transporte privado y no se declaró entregado.

No se creó rama, commit ni push; no se instaló dependencia; no se ejecutó `reset`, `clean`, `migrate:fresh`, ni se modificó/eliminó Quarkus, volumen, base habitual o SQL histórico.
