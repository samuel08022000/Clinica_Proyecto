# Migración a Laravel — Fase 1: PostgreSQL 16 y acceso SQL

Informe de implementación y guía local. Comprobaciones realizadas el **14 de noviembre de 2026**.

La infraestructura está preparada: PostgreSQL 16.15 funciona en un contenedor nuevo, Laravel accede con un usuario limitado y las pruebas reales de transacciones/auditoría pasan. **El esquema completo hasta V10 está conservado.** No se han migrado login, controladores de dominio ni paneles. Las rutas actuales que utilizan Quarkus siguen utilizando su API; esta fase habilita la conexión SQL independiente para las siguientes fases.

## A. Docker y protección del entorno anterior

| Recurso | Configuración nueva |
|---|---|
| Compose | `compose.clinical.yml` |
| Proyecto Docker | `clinica-laravel-fase1` |
| Servicio / contenedor | `postgres_clinical` / `clinica-laravel-fase1-pg16` |
| Imagen local | `postgres:16-alpine`, versión ejecutada **16.15** |
| Base principal | `clinica_laravel` |
| Base exclusiva de pruebas | `clinica_laravel_phase1_tests` |
| Volumen persistente | `clinica_laravel_fase1_pg16_data` |
| Puerto | **127.0.0.1:35443 → 5432** |
| Red | `clinica-laravel-fase1_default` |
| Zona horaria | `America/La_Paz` |
| Reinicio | `restart: "no"`; inicio/parada manuales y controlados |
| Healthcheck | `pg_isready`, intervalo 2 s, 30 intentos |

Se añadió un solo Compose dedicado porque el Compose antiguo contiene interpolaciones de credenciales y dependencias del fullstack Quarkus. El nuevo no lo incluye, no comparte sus volúmenes y tiene `pull_policy: never`: **no descarga imágenes**. Los Compose existentes no se modificaron. No se creó un contenedor PHP innecesario: se utilizó PHP local, que ya tiene `pdo_pgsql`.

Inventario previo relevante:

- `clinica_postgres_nuevo`: PostgreSQL 15, `db_clinica`, puerto 5433, volumen `clinica_postgres_nuevo_data`; estaba detenido y sigue detenido.
- `clinica_db`: PostgreSQL 15, base `clinica_db`, puerto 5432, volumen `proyectoclinica_pg_data`. Docker Desktop lo inició automáticamente al arrancar el motor, por su configuración existente. No se enviaron escrituras ni órdenes de reinicio/parada a ese servicio.
- `clinica-fase12-postgres_test-1`: PostgreSQL 15, puerto 35441, almacenamiento tmpfs; se conservó detenido.
- `clinica-fase3-pg16`: PostgreSQL 16, puerto 35442, volumen anónimo; se conservó detenido.
- Los contenedores MySQL y PlantUML existentes no se utilizaron.

Docker Desktop inicialmente no estaba en ejecución. Se inició desde su instalación local, sin instalar software. No se eliminaron contenedores anteriores ni volúmenes. Los auxiliares temporales de respaldo, sin red, se retiraron automáticamente al finalizar; nunca iniciaron PostgreSQL.

### Respaldo habitual

Se creó un **respaldo físico en frío** del volumen `clinica_postgres_nuevo_data`, montado en solo lectura:

` .local/clinical-phase1/backups/db_clinica_pg15_cold_20261114_130331_7ad06d43.tar.gz `

- 1462 entradas; `gzip -t` correcto y presencia de `PG_VERSION` y `global/pg_control` comprobada.
- SHA-256: `D9A0591597D5B304EF36ED59965FA0E857BBB1E54DB2E8EF8D89465E736F796F`.
- Se comparó después el hash del TAR descomprimido con un nuevo flujo TAR del volumen original en solo lectura: **coinciden contenido y metadatos**.
- No se necesitó conocer ni cambiar credenciales habituales.
- No se importó ese respaldo ni ningún dato personal a las bases nuevas.
- No se ensayó una restauración arrancando un servidor. Es un respaldo físico de **PostgreSQL 15**: no debe montarse como datos de PostgreSQL 16. Una futura transferencia de datos necesitará un procedimiento lógico autorizado.

El directorio privado está ignorado por Git y su ACL permite acceso al usuario Windows actual y SYSTEM. El respaldo contiene información sensible: conservarlo privado, no adjuntarlo a informes ni subirlo al repositorio.

## B. Esquema completo y estrategia de migraciones

### Ruta aplicada y comprobada

1. `B9__instalacion_sin_datos_demo.sql`, tipo Flyway `SQL_BASELINE`, versión 9.
2. `V10__derivaciones_recepcion_enlazadas.sql`, tipo `SQL`, versión 10.
3. `R__verificacion_email_otp.sql`, tipo `SQL`, repetible.

Ambas bases nuevas tienen exactamente esas tres entradas exitosas en `public.flyway_schema_history`. Flyway informa de 11 archivos validados porque resuelve también las migraciones históricas; **solo aplicó tres**. No ejecutó V1–V9 sobre B9, ni insertó V2/V3 demo. El directorio privado de V3 tampoco forma parte de las ubicaciones del instalador nuevo.

B9 incorpora V1 y V4–V9, sin los datos demo V2/V3. V10 reemplaza las funciones de reserva/reprogramación con la integración de derivaciones; R incorpora la verificación por correo. El README histórico describe aún B9 → R: para esta fase prevalece la secuencia B9 → V10 → R, también prevista por `FlywayCompatibilityTest` local.

El instalador usa **Flyway Community 12.0.0 como biblioteca independiente**, PostgreSQL JDBC 42.7.13 y las bibliotecas auxiliares ya disponibles en `backend_clinica/target/quarkus-app/lib/main`. Solo selecciona los JAR de Flyway, PostgreSQL, Jackson y auxiliares necesarios; no incluye bibliotecas Quarkus en su classpath ni arranca el backend. Utiliza el Java local 25.0.4.1. No descargó dependencias, no compiló el proyecto Java y no requiere un servidor Quarkus activo.

`ClinicalSchema.java` es exclusivamente una herramienta de infraestructura. `Install-Schema.ps1`:

- comprueba nombre, puerto, contenedor, volumen y marcador de propiedad de la base;
- exige `public` vacío cuando no hay historial;
- mantiene `baselineOnMigrate=false`, `cleanDisabled=true` y `outOfOrder=false`;
- deja que Flyway seleccione B9 de forma nativa;
- en una instalación existente **solo valida**, no reproduce SQL ni regenera QR;
- rechaza archivos fuera del alcance hasta V10 y un historial diferente del esperado;
- no ejecuta `repair`, `clean`, `baseline` ni migraciones Laravel.

La dependencia temporal es **Java + Flyway para gestionar el esquema**, no Java como framework de aplicación. Laravel en ejecución no necesita Java. Los JAR están hoy en un directorio de compilación local: si se elimina ese directorio, el instalador falla de forma explícita. Para distribuir el procedimiento deberá conservarse una distribución local y versionada de esas herramientas, o adoptar posteriormente un único ejecutor Laravel de SQL con reconciliación explícita del historial. No reconstruir las 21 tablas en migraciones PHP ni crear un historial Flyway ficticio. Los upgrades futuros deben diseñarse como una fase aparte, con un solo responsable del esquema y SQL versionado posterior a V10.

### Inventario comprobado en PostgreSQL 16

| Objeto | Resultado |
|---|---:|
| Tablas clínicas y de soporte | **21** |
| Tabla adicional de historial Flyway | 1 |
| Vistas | **2** |
| Funciones clínicas, excluyendo funciones de extensiones | **42** |
| Triggers propios, excluyendo triggers internos de FK | **32** |
| Claves foráneas | **40** |
| Índices del dominio | **78** |
| Índices totales en public, incluido Flyway | 80 |
| Restricciones de exclusión por solapamiento | **2** |
| Restricciones no validadas / triggers deshabilitados | **0 / 0** |
| Usuarios en la base principal | **0** |

Tablas conservadas:

```text
roles                          usuarios
usuarios_identidades_externas   pacientes
secretarias                    medicos
especialidades                 medico_especialidades
horarios                       bloqueos_agenda
slots_agenda                   bloqueos_citas_afectadas
citas                          derivaciones
analisis                       incidencias_sanciones
verificaciones_otp             tokens_recuperacion
configuracion_seguridad        notificaciones
bitacora
```

Vistas: `v_agenda_disponibilidad` y `v_historial_citas`.

Funciones conservadas, cuyas definiciones se compararon con la última versión de los SQL originales:

```text
actor_actual                       ahora_clinica
aplicar_sancion_paciente            auditar_fila
bloquear_agenda_medico              cancelar_cita
cita_admite_checkin                 crear_derivacion
crear_otp                          crear_otp_email
crear_token_qr                     crear_token_recuperacion
desbloquear_sanciones_vencidas       estado_reserva_paciente
fecha_clinica                      finalizar_notificacion
generar_slots_agenda               impedir_borrado_citas
impedir_edicion_historial           marcar_cita_atendida
marcar_inasistencias_vencidas       procesar_bloqueo_agenda
procesar_evento_cita                procesar_inasistencia_vencida
regenerar_slots_automaticamente     registrar_checkin
registrar_inasistencia              registrar_paciente
registrar_sancion                  reprogramar_cita
reservar_cita                      restablecer_contrasena
serializar_bloqueo_agenda           serializar_reserva_agenda
tomar_notificaciones               validar_analisis
validar_cita                       validar_duracion_agenda
validar_horario_semanal             validar_otp
validar_otp_email                  verificar_token_qr
```

Triggers conservados:

```text
trg_00_serializar_reserva              trg_aplicar_sancion_paciente
trg_auditar_analisis                   trg_auditar_bloqueos
trg_auditar_bloqueos_citas              trg_auditar_citas
trg_auditar_derivaciones               trg_auditar_especialidades
trg_auditar_horarios                   trg_auditar_incidencias
trg_auditar_medico_especialidades       trg_auditar_medicos
trg_auditar_pacientes                  trg_auditar_secretarias
trg_auditar_usuarios                   trg_bitacora_inmutable
trg_bloqueos_citas_inmutables           trg_citas_no_delete
trg_incidencias_inmutables             trg_procesar_bloqueo_agenda
trg_procesar_evento_cita                trg_serializar_bloqueo
trg_slots_despues_especialidad          trg_slots_despues_horario
trg_slots_despues_medico                trg_slots_despues_medico_especialidad
trg_validar_analisis                    trg_validar_cita
trg_validar_duracion_especialidad       trg_validar_duracion_medico
trg_validar_duracion_vinculo            trg_validar_horario_semanal
```

Se conservan `pgcrypto`, `btree_gist`, PK/FK, CHECK, índices únicos/parciales, secuencias, identidades y códigos generados, auditoría inmutable, exclusiones GiST para médico/paciente, protección de citas, sanciones, OTP, análisis y trazabilidad de bloqueos. **No se eliminaron tablas nuevas ni se redujo el modelo a 15 tablas.**

### Clave QR

B9 inicializó `QR_HMAC` mediante `gen_random_bytes(32)` únicamente en las bases nuevas vacías. Se verificaron existencia y longitud sin mostrar el secreto. El volumen conserva la clave después de stop/start. Reejecutar el instalador valida el historial y no reproduce B9. Para una futura base con QR existentes se debe preservar su clave original junto con sus datos; jamás inicializar una nueva sobre esos códigos.

## C. Laravel: conexión, transacciones y convenciones

Conexión nombrada **`clinical`**, driver `pgsql`, usuario `clinica_app`, puerto 35443, esquema `public`, charset UTF-8, zona `America/La_Paz`, consultas preparadas nativas (`ATTR_EMULATE_PREPARES=false`).

Las variables `CLINICAL_DB_*` son independientes de `DB_*`. `Invoke-Laravel.ps1` lee el estado privado e inyecta las variables **solo en el proceso hijo PHP**, restaurando después el entorno previo. No sobrescribe `.env`, APP_KEY, OAuth, SMTP ni credenciales anteriores. Rechaza configuración Laravel cacheada para evitar usar una conexión inesperada. No ejecutar `config:cache` con credenciales temporales de prueba.

PHP local comprobado: **8.5.10 con PDO y pdo_pgsql**. También existen pdo_sqlite y pdo_mysql, pero la conexión clínica utiliza exclusivamente PostgreSQL. La imagen PHP del Compose fullstack antiguo no se utilizó ni se modificó. Si en una fase futura se empaqueta Laravel en Docker, esa imagen debe incluir pdo_pgsql y usar el hostname del servicio Docker; no debe copiarse una DLL Windows al contenedor Linux.

Sesiones, caché y colas mantienen su configuración y conexión actuales. SQLite continúa como infraestructura previa y como base de las pruebas existentes que lo fuerzan; **no contiene ni reemplaza el dominio clínico**. No se ejecutaron las migraciones `users/cache/jobs` sobre `clinical`. Sus tablas no se mezclaron con las 21 tablas clínicas.

### Servicio de auditoría

`ClinicalTransaction::run(Closure)`:

1. utiliza exclusivamente la conexión `clinical`;
2. rechaza una transacción externa ya abierta o un actor residual en la conexión;
3. inicia una transacción propia;
4. obtiene el código desde `ClinicalActorProvider`, no desde un argumento de formulario;
5. comprueba formato y existencia de usuario/rol activos;
6. ejecuta `SELECT set_config('app.cod_usu', ?, true)` en esa misma conexión;
7. pasa esa conexión a la operación; confirma o revierte datos y auditoría conjuntamente;
8. no reintenta automáticamente escrituras; el contexto LOCAL desaparece al terminar la transacción.

El binding normal es `UnavailableClinicalActorProvider`: rechaza toda identidad hasta implementar autenticación confiable en una fase posterior. No se cambió la autenticación HTTP existente. Las pruebas usan un proveedor definido exclusivamente dentro del test y una base expresamente autorizada. No existe endpoint de establecimiento de actor.

`ClinicalSqlException` traduce SQLSTATE a mensajes controlados, sin conservar SQL, bindings ni la excepción original como causa. No se cambió el manejo de errores de los módulos actuales. `ClinicalCatalog` demuestra consultas parametrizadas y llamada a `fecha_clinica()` sin introducir una API genérica para ejecutar funciones por nombre recibido del cliente.

Este servicio establece **contexto de auditoría, no autorización de negocio**. En fases siguientes los servicios/policies deben comprobar rol, perfil activo, propiedad y reglas de cada operación. La validación de usuario/rol actual no implementa todavía sesiones, revocación ni bloqueo concurrente de cuentas.

### Convenciones para modelos/repositorios siguientes

- Usar explícitamente la conexión `clinical`.
- PK textuales: `usuarios.cod_usu`, `pacientes.cod_pac`, `medicos.cod_med`, `citas.cod_cit`, y equivalentes. En Eloquent: `$keyType='string'`, `$incrementing=false` y `$primaryKey` específico.
- Deshabilitar timestamps automáticos por defecto (`$timestamps=false`) hasta mapear conscientemente `creado_en`, `actualizado_en`, `fecha_registro`, etc. No escribir `created_at/updated_at` inexistentes.
- No enviar `secuencial`, códigos generados ni columnas calculadas. Para altas complejas utilizar `INSERT ... RETURNING cod_...` con parámetros; una PK textual generada no se recupera automáticamente por configurar solamente `$incrementing=false`.
- Las relaciones compuestas, como `medico_especialidades` y `bloqueos_citas_afectadas`, requieren claves explícitas o Query Builder; no presumir una columna `id`.
- Mantener `date/time` de agenda como hora clínica; instantes `timestamptz` como instantes con zona. Las funciones `fecha_clinica/ahora_clinica` conservan la autoridad temporal SQL.
- Mantener en PostgreSQL exclusiones, triggers, secuencias y auditoría. No duplicar sus escrituras desde observadores Eloquent.
- Los repositorios de negocio futuros llamarán funciones conocidas con bindings; no concatenarán entradas del cliente en identificadores o SQL.

No se generaron 21 modelos Eloquent ni se reutilizó indebidamente el modelo scaffold `User` para `usuarios`.

### Archivos de esta fase

Modificados:

- `.gitignore`: exclusión del estado privado y respaldos nuevos.
- `frontend_clinica/.env.example`: documentación de variables clínicas sin contraseña.
- `frontend_clinica/config/database.php`: conexión `clinical`.
- `frontend_clinica/app/Providers/AppServiceProvider.php`: binding de identidad deshabilitada por defecto.

Añadidos:

- `compose.clinical.yml`.
- `scripts/clinical/Common.ps1`, `Initialize-Environment.ps1`, `Start-Database.ps1`, `Stop-Database.ps1`.
- `scripts/clinical/ClinicalSchema.java`, `Install-Schema.ps1`, `runtime-permissions.sql`.
- `scripts/clinical/Invoke-Laravel.ps1`, `Verify-Database.ps1`, `Test-Persistence.ps1`.
- `scripts/clinical/Backup-Habitual.ps1`, `Test-HabitualBackup.ps1`.
- `frontend_clinica/app/Contracts/ClinicalActorProvider.php`.
- `frontend_clinica/app/Services/Clinical/{ClinicalTransaction,ClinicalSqlException,UnavailableClinicalActorProvider}.php`.
- `frontend_clinica/app/Repositories/Clinical/ClinicalCatalog.php`.
- `frontend_clinica/app/Console/Commands/ClinicalCheck.php`.
- `frontend_clinica/tests/Postgres/ClinicalInfrastructureTest.php`.
- Este informe.

Se conservaron todos los SQL históricos, V3 privado, Quarkus, las vistas Blade, controladores de dominio, autenticación y Compose anteriores. Ya había cambios locales y eliminaciones de documentos antes de esta fase; no se restauraron ni sobrescribieron. No hubo instalaciones, ramas, commit ni push.

## D. Pruebas ejecutadas y resultados

| Comprobación / comando | Resultado real |
|---|---|
| `php -v`, `php -m`, `composer -V` | PHP 8.5.10, pdo_pgsql disponible; Composer existente, sin instalaciones |
| `Initialize-Environment.ps1`, `Start-Database.ps1` | Contenedor nuevo saludable, imagen local 16.15 |
| `Backup-Habitual.ps1` | Respaldo frío, gzip/TAR/SHA-256 verificados |
| `Install-Schema.ps1` | B9 + V10 + R en dos bases nuevas; sin Quarkus |
| `Invoke-Laravel.ps1` | Conexión real como clinica_app, fecha clínica y conteos correctos |
| `Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres --testdox` | **5 pruebas, 128 aserciones, todas correctas** |
| `Verify-Database.ps1` | 21 tablas, 2 vistas, 42 funciones, 32 triggers; cero usuarios principales |
| `Test-Persistence.ps1` | stop/start exclusivo; clúster, QR, roles, historial, usuarios y auditoría persistieron |
| `Test-HabitualBackup.ps1` | Volumen habitual idéntico al respaldo inicial y aún detenido |
| `Install-Schema.ps1 -ValidateOnly`, después del reinicio | Checksums/historial válidos en ambas bases, sin reaplicar SQL |
| `php vendor/bin/phpunit` en frontend | **264 casos: 252 correctos, 12 omitidos; 1653 aserciones** |
| `php -l` en los nueve PHP nuevos/modificados; Pint dirigido | Sintaxis y formato correctos |
| Parser PowerShell y `git diff --check` | Sin errores de sintaxis/whitespace |
| Inventario final de procesos | **0 procesos Quarkus**; ningún contenedor Quarkus iniciado |

Las cinco pruebas PostgreSQL verifican definiciones efectivas de las 42 funciones, V10 e historial, conjuntos de tablas/vistas/triggers, extensiones, códigos generados, parámetros resistentes a inyección, FK/CHECK/UNIQUE, lectura de vistas, privilegios efectivos, commit y rollback de datos/auditoría, actor correcto, segundo actor en la misma conexión, ausencia de actor residual, errores SQL saneados y rechazo de identidades no habilitadas/transacciones anidadas.

Las pruebas reales PostgreSQL se ejecutan explícitamente desde `tests/Postgres`; no están ocultas dentro del suite SQLite. Sin opt-in se omiten. La prueba de identidades guarda únicamente datos sintéticos `example.invalid` en la base de pruebas, sin contraseñas de acceso ni datos históricos. No limpia ni borra esa base; ejecuciones sucesivas añaden fixtures identificables y auditoría. La base principal permanece vacía de usuarios.

Fallos corregidos durante el trabajo:

- El primer acceso a Docker estaba bloqueado/no disponible hasta iniciar Docker Desktop desde su ruta real.
- El primer instalador consultaba comentarios de base con `obj_description`; se corrigió a `shobj_description`. Falló antes de instalar el esquema.
- Se eliminó del classpath independiente un proveedor de logging propio del entorno Quarkus que generaba advertencias.
- La primera ejecución PostgreSQL detectó fixtures sin duración obligatoria de especialidad y un valor que provocaba longitud máxima antes del CHECK previsto. Se corrigieron los fixtures y se repitió el suite completo, con resultado 5/5.
- Pint detectó estilo en dos archivos nuevos; se corrigió y volvió a verificar.

Limitaciones expresas: los 12 casos omitidos de la regresión corresponden a suites opt-in del stack histórico y no se consideran aprobados. No se ejecutaron las suites Java, E2E con Quarkus, OAuth real, envío SMTP ni flujos clínicos completos. No se probó restaurar y arrancar el respaldo habitual. Esta fase comprueba infraestructura, no la migración de módulos.

## E. Secretos, permisos y separación

Tres identidades PostgreSQL separadas:

- `clinica_bootstrap`: inicializa exclusivamente el clúster nuevo; contraseña aleatoria mediante archivo Docker secret, no incorporada al Compose.
- `clinica_owner`: propietario de las bases y objetos, **sin SUPERUSER/CREATEDB/CREATEROLE**; usado solo por instalación y fixtures controladas.
- `clinica_app`: usuario Laravel, **sin SUPERUSER/CREATEDB/CREATEROLE**, sin creación de objetos y sin pertenecer al propietario.

La política `runtime-permissions.sql` se aplica únicamente a las bases nuevas, fuera de los SQL históricos. Conserva funciones y triggers pero restringe EXECUTE público sobre funciones clínicas, manteniendo las funciones de extensiones intactas. Permite lecturas clínicas y llamadas explícitas a `fecha_clinica`, `ahora_clinica`, `actor_actual` y `estado_reserva_paciente`. La única escritura directa habilitada ahora es `UPDATE(descripcion)` sobre especialidades, para verificar el trigger de auditoría real. No permite escribir directamente en bitácora.

Laravel no puede leer directamente `configuracion_seguridad`, `verificaciones_otp` o `tokens_recuperacion`; tampoco ejecutar todavía las funciones de autenticación/OTP. Los siguientes módulos recibirán grants específicos junto con su autorización PHP. Que una función exista no implica que todos los roles deban poder ejecutarla. Los privilegios de tablas/columnas, esquemas y funciones se probaron con la conexión real `clinica_app`.

Las contraseñas nuevas son aleatorias, permanecen en `.local/clinical-phase1/` y no se imprimen. Los scripts no ponen secretos en argumentos Java/PHP ni generan SQL versionado con contraseñas. El helper SQL oculta stderr en fallos para no revelar sentencias de creación de roles. No se sobrescribió ningún archivo `.env` privado. No publicar `runtime.json`, el archivo bootstrap, respaldos ni salidas de herramientas que impriman entornos completos.

La conexión usa loopback y `sslmode=prefer` en este entorno local Docker. **No es una configuración TLS de producción**. Una implantación remota necesitará certificados y `verify-full`, secretos administrados y políticas de acceso apropiadas.

## F. Preparación de la siguiente fase

**Sí está lista la infraestructura SQL para comenzar la migración de autenticación**, con estas tareas aún pendientes y explícitas:

| Módulo | Base disponible | Trabajo de aplicación pendiente |
|---|---|---|
| Login | usuarios, roles, perfiles, hashes existentes compatibles por verificar con fixtures | Servicio PHP, verificación bcrypt, rate limits, estados efectivos y revocación |
| Registro | `registrar_paciente`, perfiles y restricciones | Requests, servicio transaccional, política de claves y entrega de mensajes |
| OTP | tablas y funciones de correo/teléfono | Proveedor de entrega, grants mínimos, desafíos y pruebas de persistencia de intentos fallidos |
| Google OAuth | identidades externas, hash local nullable | Validación criptográfica OIDC, state/nonce/PKCE, vinculación segura y continuaciones |
| Recuperación | tokens hash y funciones SQL | Respuestas no enumerables, entrega, vencimiento y revocación tras commit |
| Sesiones | infraestructura Laravel existente sin cambios | Almacenamiento/TTL/revocación y decisión de conexión; códigos USU no son IDs numéricos |
| Roles | catálogo, estados y datos de perfil | Middleware/policies, perfil activo, propiedad y proveedor confiable de ClinicalActorProvider |

No activar el proveedor de actor a partir de `request('cod_usu')` ni confiar únicamente en un código guardado en sesión sin revalidación. No habilitar todos los grants antes de implementar autorización. No sustituir ahora los paneles existentes. Mantener la coordinación del cambio de autenticación y contraseña para evitar identidades divergentes entre Quarkus y Laravel durante la transición.

## G. Guía PowerShell

Ejecutar desde la raíz del proyecto. Los scripts no requieren copiar ni imprimir las contraseñas.

### 1. Iniciar Docker Desktop

```powershell
Start-Process -FilePath "$env:LOCALAPPDATA\Programs\DockerDesktop\Docker Desktop.exe" -WindowStyle Hidden
docker info --format '{{.ServerVersion}}'
```

Esperar a que el motor responda. La ruta anterior corresponde a este equipo; también puede iniciarse Docker Desktop desde su acceso directo.

### 2. Iniciar y verificar el PostgreSQL nuevo

La inicialización privada y la instalación **ya se ejecutaron en este equipo**; no regenerarlas.

```powershell
.\scripts\clinical\Start-Database.ps1
docker compose -f .\compose.clinical.yml ps
.\scripts\clinical\Verify-Database.ps1
```

Solo para reproducir en otro entorno limpio que ya tenga la imagen y herramientas locales, respetando los nombres/puerto exclusivos:

```powershell
.\scripts\clinical\Initialize-Environment.ps1
.\scripts\clinical\Start-Database.ps1
.\scripts\clinical\Install-Schema.ps1
```

El inicializador rechaza secretos o recursos existentes. No borrar esos recursos para forzar su ejecución. Si la imagen o Flyway no están disponibles, documentar y preparar las herramientas por separado; estos scripts no acceden a internet.

### 3. Consultar tablas y vistas

```powershell
docker exec clinica-laravel-fase1-pg16 psql -X -U clinica_bootstrap -d clinica_laravel -c "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"
docker exec clinica-laravel-fase1-pg16 psql -X -U clinica_bootstrap -d clinica_laravel -c "SELECT viewname FROM pg_views WHERE schemaname='public' ORDER BY viewname"
```

Se ven 22 tablas al incluir `flyway_schema_history`; **21 pertenecen al modelo clínico**. Los comandos administrativos por `docker exec` se limitan a inspección; Laravel no utiliza `clinica_bootstrap`.

### 4. Confirmar conexión Laravel y ejecutar las pruebas PostgreSQL

```powershell
.\scripts\clinical\Invoke-Laravel.ps1
.\scripts\clinical\Invoke-Laravel.ps1 -TestDatabase vendor/bin/phpunit tests/Postgres --testdox
```

Para iniciar Laravel con esta conexión disponible, usando el PHP local:

```powershell
.\scripts\clinical\Invoke-Laravel.ps1 artisan serve --host=127.0.0.1 --port=8000
```

Si 8000 está ocupado, elegir otro puerto libre. El comando de conexión funciona sin Quarkus; los módulos HTTP todavía no migrados seguirán requiriendo su API. No usar `--profile fullstack` para probar esta infraestructura independiente.

### 5. Verificar V10 y checksums

```powershell
.\scripts\clinical\Install-Schema.ps1 -ValidateOnly
docker exec clinica-laravel-fase1-pg16 psql -X -U clinica_bootstrap -d clinica_laravel -c "SELECT installed_rank,version,type,script,success FROM public.flyway_schema_history ORDER BY installed_rank"
```

Deben aparecer B9, V10 y R, todas exitosas. `ValidateOnly` verifica los checksums reales; mirar la tabla por sí solo no los valida. No ejecutar manualmente todos los V ni editar sus checksums.

### 6. Comprobar persistencia y detener sin pérdida

```powershell
.\scripts\clinical\Test-Persistence.ps1
.\scripts\clinical\Stop-Database.ps1
```

`Test-Persistence` realiza stop/start y deja el contenedor saludable. `Stop-Database` lo detiene conservando el volumen. Para volver a trabajar, ejecutar `Start-Database`. Si se abrió `artisan serve`, detener ese proceso con Ctrl+C. No usar `down -v`, `volume rm`, `migrate:fresh`, `flyway clean` ni operaciones de limpieza para este procedimiento.

Para repetir la comprobación de protección del volumen habitual, mientras continúa detenido:

```powershell
.\scripts\clinical\Test-HabitualBackup.ps1
```

Si PowerShell impide ejecutar scripts locales por política del equipo, puede invocarse un script concreto en un proceso autorizado con `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\clinical\Verify-Database.ps1`, sin cambiar la política global del sistema.
