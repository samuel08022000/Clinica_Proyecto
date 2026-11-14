# PostgreSQL y DBeaver para uso local

La base clínica pertenece a Quarkus. Laravel consume su API y no necesita una conexión PostgreSQL clínica propia. Esta guía contiene consultas de solo lectura; no crea cuentas ni cambia roles.

## Iniciar y reconocer la base autorizada

Abrir Docker Desktop y ejecutar desde la raíz:

```powershell
docker version
docker ps --format 'table {{.Names}}\t{{.Ports}}'
docker compose up -d postgres_db
docker compose ps postgres_db
docker compose port postgres_db 5432
```

Compose obtiene `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` y `POSTGRES_HOST_PORT` del entorno local o `.env` raíz. No sobrescribir archivos existentes. El ejemplo usa `db_clinica` y puerto de host `5433`; verificar los valores propios. El puerto interno es 5432. Usar la misma base, puerto y usuario que `QUARKUS_DATASOURCE_JDBC_URL` y `QUARKUS_DATASOURCE_USERNAME` en el entorno local de Quarkus. No imprimir `docker compose config`, `.env` o variables de contraseña al compartir evidencia.

Si ya existe la instancia autorizada ocupando ese puerto, reutilizarla y omitir `compose up`: no crear un segundo PostgreSQL ni detener contenedores ajenos. En esta auditoría la base habitual ya estaba en `clinica_postgres_nuevo`, `127.0.0.1:5433`, base `db_clinica`; se consultó exclusivamente en modo lectura. `compose ps/port` solo reconoce los contenedores de su propio proyecto: para una instancia creada de otra forma usar el listado Docker y su configuración privada autorizada.

En DBeaver: **Nueva conexión → PostgreSQL**, host `127.0.0.1`, puerto publicado verificado, nombre de base y usuario locales. Introducir la contraseña únicamente en el diálogo privado de conexión. Descargar el controlador PostgreSQL si se solicita y usar **Probar conexión**. Marcar la conexión con un nombre que distinga habitual y pruebas. La integración usa otro servidor, puerto 35441, datos efímeros y credenciales temporales: no confundirlo con el habitual.

Antes de arrancar Quarkus sobre una instalación existente, revisar su historial y respaldo. En una base adicional vacía, Quarkus aplica B9 y R sin cuentas demo. Una base que ya tenga migraciones conserva V1–V9 y la V3 local si la aplicó. No intentar “arreglar” el historial desde el editor.

## Consultas de solo lectura

Activar el modo de conexión de solo lectura de DBeaver para explorar. Si es posible, usar un usuario de consulta autorizado con permisos SELECT. Limitar resultados y abrir un editor SQL asociado a la conexión correcta.

```sql
SELECT current_database(), current_user, version();

-- Identidad y estado; se excluye password_hash.
SELECT cod_usu, nombre_completo, email, cod_rol, estado, creado_en
FROM public.usuarios ORDER BY cod_usu LIMIT 100;

SELECT cod_rol, nombre_rol, descripcion, estado
FROM public.roles ORDER BY cod_rol;

SELECT cod_pac, cod_usu, estado_cuenta, email_verificado, bloqueado_hasta
FROM public.pacientes ORDER BY cod_pac LIMIT 100;
SELECT cod_sec, cod_usu, estado FROM public.secretarias ORDER BY cod_sec LIMIT 100;
SELECT cod_med, cod_usu, matricula_profesional, estado
FROM public.medicos ORDER BY cod_med LIMIT 100;

SELECT cod_esp, nombre_especialidad, duracion_consulta_min, estado
FROM public.especialidades ORDER BY nombre_especialidad;
SELECT cod_med, cod_esp, duracion_consulta_min, estado
FROM public.medico_especialidades ORDER BY cod_med, cod_esp;

SELECT cod_hor, cod_med, dia_semana, hora_inicio, hora_fin, pausa_inicio, pausa_fin, estado
FROM public.horarios ORDER BY cod_med, dia_semana LIMIT 100;
SELECT cod_slot, cod_med, cod_esp, fecha, hora_inicio, hora_fin
FROM public.slots_agenda WHERE fecha >= CURRENT_DATE ORDER BY inicio_at LIMIT 100;

-- No seleccionar token_qr ni campos clínicos que no sean necesarios.
SELECT cod_cit, cod_pac, cod_med, cod_esp, fecha, hora_inicio, hora_fin, estado
FROM public.citas ORDER BY fecha DESC, hora_inicio DESC LIMIT 100;

SELECT installed_rank, version, description, type, script, checksum, installed_on, success
FROM public.flyway_schema_history ORDER BY installed_rank;
```

## Detectar perfiles inconsistentes

```sql
SELECT u.cod_usu, u.cod_rol, u.estado, r.estado AS estado_rol,
       p.cod_pac, p.estado_cuenta, p.email_verificado,
       s.cod_sec, s.estado AS estado_secretaria,
       m.cod_med, m.estado AS estado_medico
FROM public.usuarios u
LEFT JOIN public.roles r ON r.cod_rol = u.cod_rol
LEFT JOIN public.pacientes p ON p.cod_usu = u.cod_usu
LEFT JOIN public.secretarias s ON s.cod_usu = u.cod_usu
LEFT JOIN public.medicos m ON m.cod_usu = u.cod_usu
WHERE r.cod_rol IS NULL OR r.estado <> 'Activo' OR u.estado <> 'Activo'
   OR (u.cod_rol = 'ROL001' AND (p.cod_pac IS NULL OR p.estado_cuenta <> 'Activo' OR NOT p.email_verificado))
   OR (u.cod_rol = 'ROL002' AND (s.cod_sec IS NULL OR s.estado <> 'Activo'))
   OR (u.cod_rol = 'ROL003' AND (m.cod_med IS NULL OR m.estado <> 'Activo'))
   OR u.cod_rol NOT IN ('ROL001','ROL002','ROL003','ROL004')
ORDER BY u.cod_usu;
```

La consulta identifica situaciones para revisar, no todas son errores: una cuenta inactiva, sancionada o pendiente de verificar puede ser válida. `ROL001` requiere Paciente, `ROL002` Secretario y `ROL003` Médico activos; `ROL004` no necesita una tabla de perfil adicional. AuthAccountGateway y AuthService validan estos estados en el servidor. No cambiar `cod_rol` libremente: también deben existir el perfil correcto, sus estados y relaciones. Las FK y triggers refuerzan la coherencia. No editar hashes, QR, OTP, tokens de recuperación ni la configuración criptográfica desde la grilla. Las sesiones son opacas en memoria del backend; una edición SQL no ejecuta por sí sola la revocación del servicio. Preferir los paneles y API autorizados para cambios de personal y contraseña.

## Primera cuenta administrativa: propuesta controlada

Una instalación nueva no ofrece una contraseña inicial ni un administrador oculto. El registro público crea únicamente pacientes. Si aún falta personal, el login lo explica y requiere al responsable del despliegue.

Propuesta para aprobación del propietario: verificar que no existe ya un administrador; acordar la identidad y correo reales del responsable; generar una contraseña individual aleatoria de un solo destinatario y BCrypt costo 12 mediante una herramienta local confiable; efectuar una única transacción controlada de alta ROL004 con auditoría y respaldo, sin publicar la contraseña ni el hash en SQL compartido, Git o logs; comprobar acceso y cerrar sesión. Este procedimiento **no se ejecutó** en Fase 13 y no se proporciona un INSERT con credenciales universales. Una vez disponible esa cuenta, crear secretarios y médicos desde Administración, que compone sus perfiles y aplica las reglas del servidor.

Para modificaciones excepcionales con DBeaver: autorización concreta, respaldo, conexión identificada, transacción explícita y revisión previa de filas afectadas. No guardar cambios accidentales de la grilla, no deshabilitar triggers, no borrar perfiles referenciados ni reutilizar las cuentas históricas de V2 por comodidad.

Guías relacionadas: [migraciones Flyway](../backend_clinica/src/main/resources/db/migration/README.md) y [arranque e integración](ejecucion-integracion.md).
