# backend_clinica

This project uses Quarkus, the Supersonic Subatomic Java Framework.

If you want to learn more about Quarkus, please visit its website: <https://quarkus.io/>.

## Running the application in dev mode

You can run your application in dev mode that enables live coding using:

```shell script
./mvnw quarkus:dev
```

## PostgreSQL y credenciales demo locales

Las credenciales de PostgreSQL y la contrasena comun de los usuarios demo no se
guardan en Git. Cada integrante prepara su entorno local de esta forma:

1. Copiar `.env.docker.example` como `.env` en la raiz y definir credenciales
   PostgreSQL y pgAdmin propias.
2. Iniciar PostgreSQL:

```powershell
docker compose up -d postgres_db
```

Compose no fija `container_name`, así que distintos clones no comparten nombres
globales. Antes de levantarlo, compruebe que `5433` esté libre. Si ya hay una
instancia autorizada en ese puerto, reutilícela; en caso contrario defina otro
`POSTGRES_HOST_PORT` en el `.env` raíz y use el mismo puerto en la URL JDBC.

3. Configurar Quarkus localmente. Puede copiarse `.env.example` como `.env` dentro
   de `backend_clinica` y completar la clave PostgreSQL local. El JDBC predeterminado
   apunta a `127.0.0.1:5433/db_clinica`.
4. Preparar la V3 exclusiva de desarrollo:

```powershell
./scripts/dev/prepare-demo-credentials.ps1
```

El comando solicita dos veces una contrasena enmascarada, genera un BCrypt costo 12
y crea `db/dev-migration/V3__corregir_credenciales_usuarios_demo.sql`. El archivo se
ignora en Git. Los integrantes deben introducir la misma contrasena, compartida por
un gestor de secretos del equipo; cada BCrypt sera diferente por su sal aleatoria.

5. Arrancar Quarkus en perfil `dev`:

```powershell
./mvnw quarkus:dev
```

Flyway aplica V1 y V2 desde el classpath y la V3 local desde `db/dev-migration`.
La V3 comprueba los cuatro correos, nombres, roles y perfiles antes de actualizar
unicamente `usuarios.password_hash`. Si falta un registro, toda la migracion falla.

La ubicacion de desarrollo no se carga con perfiles de produccion. Nunca copie la
V3 generada a `src/main/resources/db/migration`.

Con Quarkus en el puerto normal `8080`, la verificación integral se ejecuta desde
la raíz del repositorio con:

```powershell
./backend_clinica/scripts/dev/verify-demo-logins.ps1
```

La contraseña se lee como `SecureString`, se entrega al proceso de prueba mediante
una variable efímera y se elimina al finalizar. No la escriba en argumentos, `.env`,
HTML, JavaScript ni registros.

## Registro de pacientes y verificación OTP por correo

La migración repetible `R__verificacion_email_otp.sql` evita colisionar con la V3 local:

- `public.registrar_paciente`: crea `usuarios` y `pacientes` atómicamente con `ROL001`.
- `public.crear_otp_email`: genera seis dígitos con `gen_random_bytes`, guarda SHA-256,
  invalida el código anterior y aplica límites de 60 segundos y 5 solicitudes/hora.
- `public.validar_otp_email`: bloquea la fila con `FOR UPDATE`, permite 3 intentos,
  consume el código y marca `email_verificado=true`.

`telefono_verificado` no se reutiliza ni se elimina. La migración marca como verificados
los pacientes existentes para conservar su acceso y deja `false` como valor por defecto
para registros posteriores.

Endpoints públicos, siempre con `Cache-Control: no-store`:

| Método | Ruta | Resultado |
| --- | --- | --- |
| `POST` | `/api/v1/auth/register` | `201`, paciente pendiente y desafío opaco |
| `POST` | `/api/v1/auth/otp/request` | `202`, retoma el flujo con correo y contraseña |
| `POST` | `/api/v1/auth/otp/verify` | `200`, consume el OTP correcto |
| `POST` | `/api/v1/auth/otp/resend` | `202`, invalida el OTP anterior |
| `POST` | `/api/v1/auth/otp/status` | Estado asociado al desafío opaco; token en JSON, nunca en URL |
| `POST` | `/api/v1/auth/otp/demo-code` | Solo DEMO local y con clave externa |

Ejemplo del contrato de registro, sin contraseñas ni códigos en la respuesta:

```json
{
  "data": {
    "patientId": "PAC000000",
    "roleCode": "ROL001",
    "emailVerificationRequired": true,
    "verification": {
      "verificationToken": "<token-opaco>",
      "maskedDestination": "p***@example.test",
      "otpExpiresAt": "<fecha-ISO-8601>",
      "resendAvailableAt": "<fecha-ISO-8601>",
      "verificationExpiresAt": "<fecha-ISO-8601>",
      "deliveryMode": "DEMO",
      "deliveryConfirmed": false
    }
  }
}
```

### Proveedor DEMO local

En `.env` local, nunca en Git:

```env
OTP_DELIVERY_PROVIDER=demo
OTP_DEMO_ACCESS_KEY=<clave-aleatoria-de-al-menos-24-caracteres>
```

Arranque Quarkus con el perfil `dev`. Después de registrar o reenviar un código,
consúltelo desde la raíz del repositorio:

```powershell
./backend_clinica/scripts/dev/read-demo-otp.ps1
```

El endpoint DEMO se niega en perfil de producción, fuera de loopback o con una clave
incorrecta. No registra códigos. El modo `smtp` usa Quarkus Mailer y solo confirma la
entrega si la llamada síncrona al proveedor termina correctamente. La configuración
paso a paso para Gmail está en [docs/gmail-smtp.md](docs/gmail-smtp.md).

Los desafíos opacos son locales al proceso, igual que las sesiones actuales. Reiniciar
Quarkus los revoca; el paciente puede retomar el proceso con correo y contraseña. Antes
de desplegar varias instancias se debe mover sesiones y desafíos a un almacén compartido.

## Google OIDC y recuperación de contraseña

La migración `V4__identidades_google.sql` crea la relación externa por `sub` y permite
cuentas Google sin contraseña local. No almacena access tokens ni refresh tokens. Endpoints:

| Método | Ruta | Uso |
| --- | --- | --- |
| `POST` | `/api/v1/auth/google/authorization-url` | URL oficial con `state`, `nonce` y PKCE |
| `POST` | `/api/v1/auth/google/exchange` | Intercambia el código y valida el ID token |
| `POST` | `/api/v1/auth/google/register` | Completa un paciente nuevo con `ROL001` |
| `POST` | `/api/v1/auth/google/link` | Vincula tras sesión local autenticada |
| `POST` | `/api/v1/auth/password/forgot` | Respuesta pública genérica y correo temporal |
| `POST` | `/api/v1/auth/password/reset` | Cambia BCrypt, consume token y revoca sesiones |
| `POST` | `/api/v1/auth/password/demo-link` | Solo DEMO, loopback y clave local |

Google se activa siguiendo [docs/google-oauth.md](docs/google-oauth.md). Para recuperación
DEMO use `scripts/dev/read-demo-password-reset.ps1`. SMTP reutiliza Quarkus Mailer y la
misma configuración descrita en [docs/gmail-smtp.md](docs/gmail-smtp.md).

> **_NOTE:_**  Quarkus now ships with a Dev UI, which is available in dev mode only at <http://localhost:8080/q/dev/>.

## Packaging and running the application

The application can be packaged using:

```shell script
./mvnw package
```

It produces the `quarkus-run.jar` file in the `target/quarkus-app/` directory.
Be aware that it’s not an _über-jar_ as the dependencies are copied into the `target/quarkus-app/lib/` directory.

The application is now runnable using `java -jar target/quarkus-app/quarkus-run.jar`.

If you want to build an _über-jar_, execute the following command:

```shell script
./mvnw package -Dquarkus.package.jar.type=uber-jar
```

The application, packaged as an _über-jar_, is now runnable using `java -jar target/*-runner.jar`.

## Creating a native executable

You can create a native executable using:

```shell script
./mvnw package -Dnative
```

Or, if you don't have GraalVM installed, you can run the native executable build in a container using:

```shell script
./mvnw package -Dnative -Dquarkus.native.container-build=true
```

You can then execute your native executable with: `./target/backend_clinica-1.0.0-SNAPSHOT-runner`

If you want to learn more about building native executables, please consult <https://quarkus.io/guides/maven-tooling>.

## Related Guides

- REST ([guide](https://quarkus.io/guides/rest)): Build RESTful web services and APIs using Jakarta REST (formerly JAX-RS)
- REST Jackson ([guide](https://quarkus.io/guides/rest#json-serialisation)): Jackson serialization support for Quarkus REST. This extension is not compatible with the quarkus-resteasy extension, or any of the extensions that depend on it
- Hibernate ORM with Panache ([guide](https://quarkus.io/guides/hibernate-orm-panache)): Simplified JPA/Hibernate data access layer with active record and repository patterns
- JDBC Driver - PostgreSQL ([guide](https://quarkus.io/guides/datasource)): Connect to the PostgreSQL database via JDBC

## Provided Code

### Hibernate ORM

Create your first JPA entity

[Related guide section...](https://quarkus.io/guides/hibernate-orm)


[Related Hibernate with Panache section...](https://quarkus.io/guides/hibernate-orm-panache)


### REST

Easily start your REST Web Services

[Related guide section...](https://quarkus.io/guides/getting-started-reactive#reactive-jax-rs-resources)
