# Migraciones locales de desarrollo

Esta carpeta se agrega a Flyway solamente con el perfil Quarkus `dev`. Los archivos
`*.sql` estan ignorados por Git y no se empaquetan dentro del classpath de produccion.

Para preparar las credenciales demo:

```powershell
./scripts/dev/prepare-demo-credentials.ps1
```

El script solicita una contrasena enmascarada, genera BCrypt costo 12 y crea localmente:

`V3__corregir_credenciales_usuarios_demo.sql`

No se debe copiar esa V3 a `src/main/resources/db/migration`. La contrasena comun se
comparte fuera de Git mediante el gestor de contrasenas del equipo.
