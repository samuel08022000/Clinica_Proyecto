# Fase 1: agenda y atención médica (RF-20)

## A. Funcionalidades implementadas

- Agenda diaria del médico autenticado, ordenada por hora de inicio e identificador de cita. Devuelve todos los estados existentes, incluidos pacientes pendientes de llegada y consultas atendidas. Si no hay citas devuelve `[]`.
- Detalle administrativo de una cita propia, sin entidades completas, QR, documentos de identidad, correo, teléfono ni información clínica.
- Identificación del paciente por `codPac` y nombre; especialidad, horario, canal de reserva, estado, check-in, responsable y fecha/hora de atención.
- Cierre exclusivo `En Espera -> Atendida`, con check-in completo y sin atención anterior. No recibe el médico, el responsable ni la hora desde el cliente.
- Historial de citas propias en estado `Atendida`, paginado y con rango opcional por fecha de cita. Orden descendente por fecha, hora e identificador para mantener una paginación estable.
- Autorización en Quarkus mediante el sistema actual: Bearer opaco, sesión vigente, rol exclusivo `ROL003`, usuario/rol/perfil activos y resolución de `medicos.cod_usu` desde el usuario de la sesión.
- Las consultas incluyen obligatoriamente `codMed` obtenido del perfil autenticado. Un parámetro extra `codMed` o cabeceras `X-Actor-*` no cambian la identidad.
- Las respuestas exitosas incluyen `Cache-Control: no-store` y `Pragma: no-cache`.

El cierre abre una transacción, consulta únicamente la cita propia con bloqueo `PESSIMISTIC_WRITE` (`FOR UPDATE`), valida estado/check-in/sellos previos y llama a `CitaFunctionRepository.marcarCitaAtendida(codCit, codUsuDoctor)`. Esa función ya existía: verifica al médico propietario, vuelve a bloquear la fila y actualiza estado, fecha y responsable. Java no duplica esa escritura. Una proyección nueva recupera el resultado actualizado sin devolver la entidad anterior a la función SQL.

Dos cierres concurrentes se serializan sobre la misma cita: el ganador responde 200; el segundo observa `Atendida` y responde 409 sin modificar responsable, hora ni auditoría. Una cita ajena e inexistente tienen la misma respuesta 404 para evitar revelar su existencia.

## B. Archivos de esta fase

Creado:

- `src/main/java/org/clinica/controller/MedicoController.java`
- `src/main/java/org/clinica/service/MedicoAtencionService.java`
- `src/main/java/org/clinica/dto/medico/MedicoDtos.java`
- `src/test/java/org/clinica/service/MedicoAtencionTest.java`
- `docs/medico-agenda-atencion-fase1.md`

Modificado:

- `src/main/java/org/clinica/repository/CitaRepository.java`: proyecciones médicas, agenda, detalle propio, bloqueo de cita propia, fecha de clínica e historial paginado. Conserva los métodos existentes de Secretaría.

Las rutas anteriores son relativas a `backend_clinica`. Los registros de ejecución quedan en `target/` (ignorado por Git).

Se conservaron los cambios locales que ya existían al comenzar: `.env.example` y `.gitignore` del backend; `composer.json`, `config/clinic.php` y `database/seeders/DatabaseSeeder.php` del frontend; documentos eliminados y archivos sin seguimiento en la raíz. Ninguno fue editado por esta fase. No se crearon ramas, commits ni operaciones remotas.

## C. Endpoints y contrato

Todos exigen `Authorization: Bearer <sesión de médico activo>`. Paciente, Secretaría y Administrador reciben 403. Sesión ausente, inválida o revocada: 401. La infraestructura existente puede devolver 403 y revocar una sesión cuyo usuario/perfil fue deshabilitado; una petición posterior con esa sesión revocada devuelve 401.

| Método | Ruta | Parámetros | Respuesta 200 |
|---|---|---|---|
| GET | `/api/v1/medico/agenda` | `fecha=YYYY-MM-DD`, opcional; por defecto `public.fecha_clinica()` (America/La_Paz) | Array de `CitaMedica`, ascendente por horario |
| GET | `/api/v1/medico/citas/{codCit}` | Identificador alfanumérico, máximo 64 caracteres | `CitaMedica` propia |
| POST | `/api/v1/medico/citas/{codCit}/atender` | Sin cuerpo o `{}`; no acepta campos | `CitaMedica` actualizada |
| GET | `/api/v1/medico/historial` | `desde`, `hasta` opcionales en `YYYY-MM-DD`, inclusivos por fecha de cita; `page=0..10000`, `size=1..50` (por defecto 0 y 20) | `{ "citas": [...], "page": 0, "size": 20, "hayMas": false }` |

Fecha imposible, formato incorrecto, año cero, fecha explícitamente vacía, rango invertido, paginación inválida, identificador inválido o cuerpo con campos: 400. Los parámetros de selección de otro médico no forman parte del contrato y no se utilizan. No se exige que la fecha de consulta sea la fecha de hoy: los filtros también permiten revisar citas anteriores o futuras existentes.

Ejemplo de `CitaMedica` después del cierre (valores ficticios):

```json
{
  "codCit": "CIT00000123",
  "codPac": "PAC000010",
  "nombrePaciente": "Paciente de ejemplo",
  "codEsp": "ESP000001",
  "nombreEspecialidad": "Especialidad de ejemplo",
  "fecha": "2026-11-13",
  "horaInicio": "08:00:00",
  "horaFin": "08:30:00",
  "estado": "Atendida",
  "canalReserva": "Ventanilla",
  "horaLlegadaCheckin": "2026-11-13T07:55:00-04:00",
  "checkinPorUsu": "USU000002",
  "fechaAtencion": "2026-11-13T08:29:00-04:00",
  "atendidaPorUsu": "USU000003",
  "checkinRegistrado": true
}
```

Los sellos ausentes se devuelven como `null`; `checkinRegistrado` requiere tanto hora de llegada como usuario de check-in. Los timestamps usan `OffsetDateTime` y pueden serializarse con otro offset equivalente al almacenado.

Error de negocio, conforme al formato administrativo existente:

```json
{
  "ok": false,
  "error": "ATENCION_NO_PERMITIDA",
  "mensaje": "Solo puede atender una cita en espera con check-in registrado y sin atención previa."
}
```

404 utiliza `NO_ENCONTRADO`; validaciones utilizan `VALIDACION`. Los errores de autenticación conservan el formato de `AuthExceptionMapper`, por ejemplo:

```json
{ "error": { "code": "AUTHENTICATION_REQUIRED", "message": "Debes iniciar sesion para continuar." } }
```

## D. Base de datos e integración

**No fue necesario modificar el esquema ni crear migraciones, tablas, estados, funciones o triggers.** No se ejecutaron cambios en la base habitual de pacientes. Las pruebas emplean exclusivamente PostgreSQL aislado en `127.0.0.1:35441/clinica_fase12_tests`.

Elementos reutilizados:

- `usuarios`, `roles`, `medicos`: autenticación, rol y estado del perfil.
- `citas`, `pacientes`, `usuarios`, `especialidades`: proyección administrativa de la agenda/detalle/historial.
- `horarios`, `medico_especialidades`, `slots_agenda`: se conserva el flujo administrativo existente; el módulo médico consume las citas resultantes sin duplicar agendas ni slots.
- `public.marcar_cita_atendida(text,text)`: cierre transaccional, propiedad, estado, `fecha_atencion=now()`, `atendida_por_usu` y actor PostgreSQL.
- `DbActorHelper`, `public.actor_actual()`, `public.auditar_fila()` y `trg_auditar_citas`: trazabilidad del cierre en `bitacora`, con estado anterior y posterior, autor y fecha de atención.
- `trg_validar_cita` / `public.validar_cita()`: conserva la validación existente, los datos derivados del slot y la actualización del sello de modificación.
- `trg_procesar_evento_cita` / `public.procesar_evento_cita()`: conserva los eventos existentes y el respaldo de sellado de llegada/atención. La función de cierre ya proporciona la fecha, por lo que no se genera un segundo cambio para sellarla.
- Restricciones existentes: `En Espera` requiere `hora_llegada_checkin` y `checkin_por_usu`; `Atendida` requiere `fecha_atencion` y `atendida_por_usu`. Se conservan índices y exclusiones de solapamiento.
- `public.registrar_checkin`, `public.verificar_token_qr`, `public.cita_admite_checkin`, `AtencionCheckinService` y las rutas de Secretaría: sin modificaciones.

La escritura del cierre se ve inmediatamente desde las consultas autorizadas existentes de Secretaría. No se modificaron las rutas ni permisos de Administración o Secretaría. La función SQL histórica no comprueba por separado la presencia de check-in: ya está garantizada por las restricciones del estado `En Espera`; el servicio médico la valida además bajo bloqueo.

## E. Pruebas

Resultados reales (ejecución local del 13 al 14 de noviembre de 2026, Java 25 y PostgreSQL 15.19):

| Verificación | Resultado |
|---|---|
| `mvnw.cmd -o -DskipTests compile` | `BUILD SUCCESS` |
| Pruebas nuevas aisladas: `mvnw.cmd -o -Dtest=MedicoAtencionTest test` | 32 aprobadas, 0 fallos, 0 errores, 0 omitidas |
| Verificación completa: `scripts/integration/Invoke-Verification.ps1 -Mode Backend -Offline` | `BUILD SUCCESS`; Maven `verify` con `skipITs=false` |
| Surefire en esa verificación completa | 293 aprobadas, 0 fallos, 0 errores, 0 omitidas; incluye de nuevo las 32 pruebas médicas |
| Failsafe / `RuntimeSecurityIT`, sobre el JAR arrancado con perfil `prod` | 1 aprobada, 0 fallos, 0 errores, 0 omitidas |
| Total de la verificación final | **294 pruebas aprobadas** (261 existentes de Surefire, 32 nuevas y 1 de Failsafe) |
| Paquete | Generado en `target/quarkus-app/`; arranque del JAR verificado por Failsafe |

Entre las regresiones aprobadas están `AgendaAdministrationTest` (30), `AgendaMigrationUpgradeTest` (5), `CheckinTest` (20), `GestionCitaTest` (47), `RecepcionTest` (24), `SancionTest` (33), `SecretariaAdministrationTest` (21) y `FlywayCompatibilityTest` (2), además de autenticación, autorizaciones y las demás pruebas existentes.

La primera ejecución de las 32 pruebas médicas tuvo 1 fallo y 3 errores de preparación, corregidos como se explica en F. Las dos ejecuciones posteriores del módulo aprobaron las 32 pruebas. **No quedan pruebas fallidas u omitidas en la verificación final.**

No ejecutado: pruebas Laravel, navegador, validación manual con cuentas habituales, batería integral de Fase 3, compilación nativa y despliegue. El frontend no se modificó. Los endpoints y el flujo Secretaría -> Médico sí fueron ejercitados por HTTP con Quarkus y PostgreSQL reales en las pruebas automatizadas.

Evidencia local (archivos ignorados por Git): `target/medico-tests.log`, `target/medico-verify.log`, `target/surefire-reports/` y `target/failsafe-reports/`. Duración del `verify` final: 7 min 1 s.

Cobertura nueva en `MedicoAtencionTest`:

- Agenda propia, orden cronológico, colección vacía, aislamiento por médico, fechas y lecturas sin nuevos registros de auditoría.
- Fecha por defecto de la clínica, fechas inválidas/vacías, detalle propio y minimización exacta de campos JSON.
- Recursos ajenos/inexistentes; cabeceras de actor y parámetros de médico falsificados.
- Denegación de Paciente, Secretaría y Administrador en las cuatro operaciones; tokens ausentes, inválidos y revocados; usuario/perfil médico inactivos.
- Check-in real por HTTP de Secretaría y visualización desde agenda; cierre real, persistencia de autor/hora, auditoría única y consulta del estado desde Secretaría.
- Estados inválidos, cita sin check-in, campos arbitrarios en el cuerpo, repetición del cierre y protección de sellos originales.
- Dos cierres simultáneos con respuestas 200/409 y una sola transición auditada.
- Historial propio, exclusión de pendientes/consultas ajenas, orden, paginación, filtros y rangos inválidos.

## F. Problemas encontrados y límites

- El wrapper Maven y Docker no eran accesibles dentro del sandbox. Se utilizaron los permisos del entorno para acceder al Maven y Docker locales; Maven se ejecutó con `-o`, sin descargas.
- El contenedor aislado existente estaba detenido. Se inició el mismo contenedor de pruebas, separado de la base habitual en el puerto 5433.
- En la primera ejecución hubo un fallo de validación: REST normalizaba `fecha=` a `null`. El controlador ahora distingue entre parámetro omitido y explícitamente vacío, y devuelve 400 en el segundo caso (también para `desde` y `hasta`).
- Tres errores iniciales estaban en la preparación de pruebas: las restricciones SQL rechazaban estados incompletos y la inasistencia de una cita futura. Se corrigieron las fixtures para usar cancelación/inasistencia existentes y comprobar expresamente que PostgreSQL rechaza `En Espera` sin check-in. No se desactivaron pruebas ni restricciones.
- La configuración existente `quarkus.hibernate-orm.database.generation` emite una advertencia de deprecación; queda fuera del alcance de esta fase.
- La prueba del paquete también imprime una advertencia de clave de logging no reconocida y `Process exited with non-zero status: 1` al terminar el proceso hijo. Ambos mensajes ya aparecen en el registro local anterior `target/integration-fase12/backend-verify.log` del 12 de noviembre. Se conserva el harness existente: Failsafe registra 1 prueba aprobada, 0 errores/fallos y `verify` termina con código 0 y `BUILD SUCCESS`.
- Las sesiones siguen siendo locales al proceso, conforme a `AuthSessionStore`: reiniciar Quarkus las revoca. No se cambió ese comportamiento.
- No se incorporaron diagnósticos, recetas, derivaciones, análisis ni frontend. La verificación manual integral de usuario y la batería completa de Fase 3 siguen siendo una fase posterior.

## G. Estado final

**Fase 1 funcional y verificada a nivel de backend.** Compilación, empaquetado, las 32 pruebas nuevas y la batería existente aprobaron. No quedan tareas de implementación pendientes para agenda, detalle, cierre e historial médicos dentro de esta fase. El módulo utiliza los registros y reglas actuales de Administración y Secretaría, y conserva autenticación y auditoría existentes. La verificación manual integral de Fase 3 y el frontend quedan para las fases posteriores indicadas por el usuario.

## H. Verificación manual con PowerShell

Usar un backend arrancado con la configuración externa habitual y cuentas habilitadas ya disponibles. Los ejemplos no crean pacientes ni modifican datos de conexión. Para ejecutar el cierre, elegir una cita de prueba propia cuya llegada haya sido registrada hoy por Secretaría. El cambio a `Atendida` es una operación real: seleccionar exclusivamente una consulta de prueba destinada a ese flujo.

No pegar credenciales en comandos ni imprimir las variables de sesión. El siguiente helper solicita la contraseña de forma oculta y conserva el Bearer solamente en memoria:

```powershell
$api = 'http://127.0.0.1:8080/api/v1'

function New-ClinicaSesion {
    param([string]$Correo)
    $secreto = Read-Host 'Contraseña' -AsSecureString
    $credencial = [PSCredential]::new($Correo, $secreto)
    $cuerpo = @{ email = $Correo; password = $credencial.GetNetworkCredential().Password } | ConvertTo-Json
    try {
        $respuesta = Invoke-RestMethod -Method Post -Uri "$api/auth/login" `
            -ContentType 'application/json' -Body $cuerpo
        return @{ Authorization = 'Bearer ' + $respuesta.data.session.accessToken }
    } finally {
        Remove-Variable secreto, credencial, cuerpo, respuesta -ErrorAction SilentlyContinue
    }
}

$medico = New-ClinicaSesion -Correo (Read-Host 'Correo del médico activo')
$fecha = Read-Host 'Fecha de agenda (YYYY-MM-DD)'
$agenda = Invoke-RestMethod -Uri "$api/medico/agenda?fecha=$fecha" -Headers $medico
$agenda | Select-Object codCit,codPac,nombrePaciente,horaInicio,estado,checkinRegistrado

$codCit = Read-Host 'Identificador de una cita de prueba de ese médico'
Invoke-RestMethod -Uri "$api/medico/citas/$codCit" -Headers $medico
```

Si la cita aún está `Confirmada`, registrar su llegada por el mecanismo existente de Secretaría. El token QR debe obtenerse por el flujo autorizado de acreditación ya existente; el módulo médico no lo expone. No imprimirlo ni escribirlo en el historial de comandos:

```powershell
$secretaria = New-ClinicaSesion -Correo (Read-Host 'Correo de Secretaría activa')
$qrOculto = Read-Host 'Token QR de la cita de prueba de hoy' -AsSecureString
$qrCredencial = [PSCredential]::new('qr', $qrOculto)
$checkin = @{ tokenQr = $qrCredencial.GetNetworkCredential().Password } | ConvertTo-Json
try {
    Invoke-RestMethod -Method Post -Uri "$api/secretaria/checkin" -Headers $secretaria `
        -ContentType 'application/json' -Body $checkin
} finally {
    Remove-Variable qrOculto,qrCredencial,checkin -ErrorAction SilentlyContinue
}

# Debe mostrar En Espera, checkinRegistrado=true y horaLlegadaCheckin.
Invoke-RestMethod -Uri "$api/medico/citas/$codCit" -Headers $medico

# Cerrar sin enviar responsable, fecha ni estado.
$cierre = Invoke-RestMethod -Method Post -Uri "$api/medico/citas/$codCit/atender" `
    -Headers $medico -ContentType 'application/json' -Body '{}'
$cierre | Select-Object codCit,estado,atendidaPorUsu,fechaAtencion

# Debe aparecer Atendida en ambos módulos y en el historial propio.
Invoke-RestMethod -Uri "$api/secretaria/citas/$codCit" -Headers $secretaria
Invoke-RestMethod -Uri "$api/medico/historial?page=0&size=20&desde=$fecha&hasta=$fecha" -Headers $medico

# Repetir el cierre: debe devolver 409 y conservar el primer sello.
try {
    Invoke-RestMethod -Method Post -Uri "$api/medico/citas/$codCit/atender" `
        -Headers $medico -ContentType 'application/json' -Body '{}'
} catch { Write-Host ('HTTP ' + [int]$_.Exception.Response.StatusCode) }

# Fecha imposible: 400. Sin Bearer: 401. Secretaría en módulo médico: 403.
try { Invoke-RestMethod -Uri "$api/medico/agenda?fecha=2026-02-30" -Headers $medico }
catch { Write-Host ('HTTP ' + [int]$_.Exception.Response.StatusCode) }
try { Invoke-RestMethod -Uri "$api/medico/agenda" }
catch { Write-Host ('HTTP ' + [int]$_.Exception.Response.StatusCode) }
try { Invoke-RestMethod -Uri "$api/medico/agenda" -Headers $secretaria }
catch { Write-Host ('HTTP ' + [int]$_.Exception.Response.StatusCode) }

# Logout sin imprimir sesiones.
$null = Invoke-RestMethod -Method Post -Uri "$api/auth/logout" -Headers $medico
$null = Invoke-RestMethod -Method Post -Uri "$api/auth/logout" -Headers $secretaria
Remove-Variable medico,secretaria -ErrorAction SilentlyContinue
```

Con otra cuenta médica y una cita de prueba propia de ella, comprobar que GET y POST sobre ese identificador con la sesión del primer médico devuelven 404. Cambiar `codMed` en el query no debe ampliar la agenda. Con Paciente y Administrador las mismas rutas deben responder 403. Una cita confirmada sin llegada o cancelada debe responder 409 al intentar atenderla.

Para repetir la verificación automatizada completa usando el entorno aislado existente, desde la raíz:

```powershell
# Requiere runtime.json del entorno aislado y su contenedor PostgreSQL iniciado.
& .\scripts\integration\Invoke-Verification.ps1 -Mode Backend -Offline
```

No apuntar las pruebas a la base habitual ni recrearla. No se necesita ninguna migración adicional para este módulo.
