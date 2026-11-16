# Backend médico, Fase 2: derivaciones y análisis

## A. Funcionalidades implementadas

Se conservan los cuatro endpoints y las reglas de atención de Fase 1. La identificación del médico se reutiliza desde `MedicoAtencionService.medicoAutenticado`: Bearer opaco vigente, rol exclusivo ROL003, cuenta/rol/perfil activos y perfil profesional obtenido desde el usuario autenticado.

### Derivaciones e interconsultas

El médico emite una derivación desde una cita propia `Atendida`, con especialidad activa, motivo obligatorio y médico destino opcional. Cuando se indica médico, se comprueban su cuenta, perfil, rol y asociación activa con la especialidad usando el catálogo existente de recepción. La creación utiliza `CitaFunctionRepository.crearDerivacion` y `public.crear_derivacion`, que ya existían. No se crea automáticamente ninguna cita.

La consulta paginada y el detalle muestran exclusivamente derivaciones emitidas por el médico autenticado. Los DTO administrativos identifican paciente, cita origen, emisor, destino, fecha, estado y cita hija. El motivo se almacena en la columna existente, pero no se publica en los DTO compartidos con Secretaría. Un destinatario no obtiene acceso general a la agenda ni a las derivaciones del emisor.

La repetición del mismo origen, especialidad, médico opcional y motivo normalizado devuelve el identificador existente, sin otro INSERT ni auditoría duplicada. Se bloquea la cita origen durante toda la comprobación y creación, incluso entre peticiones concurrentes. Una misma cita sí admite interconsultas distintas. No se impuso una restricción global de una derivación por consulta ni se modificaron datos históricos.

### Programación con Secretaría y agenda de destino

Secretaría consulta pendientes y programa por el mismo `POST /api/v1/secretaria/citas`. El cuerpo actual `codPac` + `codSlot` continúa funcionando. El nuevo campo opcional `codDer` activa la programación derivada; en ese caso el paciente puede omitirse y se obtiene de la cita origen. Si se proporciona, debe coincidir.

Se reutiliza `reservar_cita` para validar agenda, especialidad, destinatario, horarios, bloqueos, habilitación del paciente, ventana D..D+14, conflictos y exclusiones. La función crea la cita hija `Confirmada`, registra `cod_cit_padre`, canal `Derivacion`, Secretaría y actor, y actualiza la derivación a `Agendada` con `cod_cit_hija`, todo dentro de la misma transacción.

Las reservas simultáneas de un mismo ticket se serializan por los bloqueos existentes; solo una crea la hija. Un rollback revierte tanto la cita como el consumo del ticket. La hija aparece en la agenda ordinaria del destinatario y requiere check-in antes de atenderse mediante Fase 1. QR y notificaciones siguen sus triggers actuales.

La reprogramación usa la función vigente `reprogramar_cita`, ampliada para conservar el ticket, canal y destino. La primera hija apunta a la consulta origen; tras reprogramar, la nueva cita apunta a la hija anterior y `derivaciones.cod_cit_hija` apunta a la cita vigente. Se conserva la cadena completa. Cancelar no reabre automáticamente un ticket de uso único; se mantiene el comportamiento de consumo existente.

### Análisis y resultados

Se permite solicitar un análisis desde una cita propia `En Espera` o `Atendida`, tal como exige `trg_validar_analisis`. Se registra únicamente `tipoAnalisis`; el usuario solicitante procede de la sesión, el estado inicial es `Pendiente` y PostgreSQL genera identificador y fechas. No se guarda resultado al solicitar.

El esquema utiliza `varchar(150)` libre y no tiene catálogo de tipos de estudio. La API exige texto no vacío de hasta 150 caracteres, sin inventar códigos o catálogos. Por cita, el mismo tipo normalizado —espacios exteriores y mayúsculas/minúsculas— devuelve la solicitud existente, incluso si ya tiene resultado. Tipos diferentes crean solicitudes diferentes. La serialización se realiza bloqueando la cita.

La consulta de análisis se limita a citas propias. El médico solicitante puede registrar `En Proceso` desde `Pendiente`, o completar desde `Pendiente`/`En Proceso` con un resultado textual obligatorio de hasta 20000 caracteres. El esquema no asigna estas operaciones a otro rol ni define un laboratorio independiente. El actor y la fecha de cada cambio quedan en la auditoría existente. No se acepta un actor desde el cliente.

`Completado`, `Entregado` y `Cancelado` son finales para esta API. Tampoco se sobrescriben análisis con un resultado, documento o fecha de entrega ya registrados. Dos completados concurrentes producen una actualización y un conflicto. `fecha_entrega_real` no se utiliza como fecha de resultado: completar no equivale a entregar. No se añade una fecha clínica ficticia ni una tabla nueva.

No existe un mecanismo local de almacenamiento/descarga de PDF para análisis. La API no acepta ni expone `archivo_pdf_url`, no publica rutas privadas y no genera archivos ficticios. Tampoco implementa entrega al paciente, recetas ni historia clínica completa.

## B. Archivos creados y modificados

Rutas relativas a `backend_clinica/`.

Creados:

| Archivo | Responsabilidad |
|---|---|
| `src/main/java/org/clinica/dto/medico/SeguimientoDtos.java` | Solicitudes estrictas y proyecciones seguras de derivaciones y análisis |
| `src/main/java/org/clinica/repository/DerivacionRepository.java` | Detalle/listado paginado y búsqueda de derivaciones idénticas |
| `src/main/java/org/clinica/repository/AnalisisRepository.java` | Consulta propia, inserción y persistencia de resultados |
| `src/main/java/org/clinica/service/MedicoDerivacionService.java` | Emisión, validación, deduplicación y consulta de derivaciones |
| `src/main/java/org/clinica/service/MedicoAnalisisService.java` | Solicitudes, resultados, propiedad, transiciones y bloqueos |
| `src/main/resources/db/migration/V10__derivaciones_recepcion_enlazadas.sql` | Evolución incremental de reservas y reprogramación derivadas |
| `src/test/java/org/clinica/service/MedicoSeguimientoTest.java` | Pruebas HTTP/SQL, concurrencia, rollback y seguridad de Fase 2 |
| `docs/medico-derivaciones-analisis-fase2.md` | Informe y guía manual de esta fase |

Modificados:

| Archivo | Cambio |
|---|---|
| `src/main/java/org/clinica/controller/MedicoController.java` | Nuevas rutas de seguimiento y catálogos activos |
| `src/main/java/org/clinica/controller/SecretariaController.java` | Listado administrativo de derivaciones y mensaje del contrato ampliado de reserva |
| `src/main/java/org/clinica/service/MedicoAtencionService.java` | Visibilidad del método de identificación para reutilizarlo; atención y consultas sin cambios |
| `src/main/java/org/clinica/service/ReservaCitaService.java` | Rama opcional `codDer`, paciente derivado del origen y validación del destino |
| `src/main/java/org/clinica/dto/recepcion/RecepcionDtos.java` | Campo opcional `NuevaCita.codDer` |
| `src/main/java/org/clinica/config/TriggerExceptionMapper.java` | Traducción segura de SQLSTATE P4094 a HTTP 409 |
| `src/test/java/org/clinica/service/FlywayCompatibilityTest.java` | Cuenta de migraciones de instalación nueva: B9 + V10 + R; conserva validación de datos e historia anterior |

No se cambiaron frontend, credenciales, variables privadas, dependencias ni migraciones históricas. Se conservaron los cambios locales detectados al comenzar: documentos eliminados, archivos sin seguimiento y modificaciones previas de `.env.example`, `.gitignore` y archivos Laravel. No se realizaron ramas, operaciones remotas, commits, reset, clean ni push.

## C. Endpoints, JSON y errores

Todas las respuestas de seguimiento incluyen `Cache-Control: no-store` y `Pragma: no-cache`. Ningún endpoint acepta parámetros para cambiar el actor autenticado.

### Médico: ROL003 activo

| Método y ruta | Parámetros/cuerpo | Éxito |
|---|---|---|
| `POST /api/v1/medico/citas/{codCit}/derivaciones` | `{"codEspDestino":"ESP000001","codMedDestino":"MED000002","motivo":"Interconsulta"}`; `codMedDestino` opcional | 201, `DerivacionResumen` |
| `GET /api/v1/medico/derivaciones` | `estado` opcional: Pendiente, Agendada, Completada, Cancelada; `page=0..10000`, `size=1..50`, defecto 0/20 | 200, `{"derivaciones":[],"page":0,"size":20,"hayMas":false}` |
| `GET /api/v1/medico/derivaciones/{codDer}` | Identificador administrativo propio | 200, `DerivacionResumen` |
| `POST /api/v1/medico/citas/{codCit}/analisis` | `{"tipoAnalisis":"Hemograma"}` | 201, `AnalisisResumen` |
| `GET /api/v1/medico/citas/{codCit}/analisis` | Cita propia | 200, array de `AnalisisResumen`, o `[]` |
| `PATCH /api/v1/medico/analisis/{codAna}` | `{"estado":"En Proceso"}` o `{"estado":"Completado","resultado":"Resultado verificado"}` | 200, `AnalisisResumen` actualizado |
| `GET /api/v1/medico/especialidades` | Sin parámetros | 200, array del catálogo activo existente |
| `GET /api/v1/medico/medicos` | `codEsp` obligatorio | 200, médicos reservables de la especialidad según configuración administrativa |

Los POST devuelven 201 con el mismo objeto e identificador ante un reintento semánticamente idéntico. No crean un registro nuevo ni repiten la auditoría. El motivo de derivación se normaliza quitando espacios exteriores y se compara exactamente; el tipo de análisis también se compara sin distinguir mayúsculas/minúsculas. No se requiere una clave de idempotencia nueva ni una tabla auxiliar.

Ejemplo ficticio de derivación:

```json
{
  "codDer":"DER000001",
  "codCitOrigen":"CIT00000001",
  "codPac":"PAC000001",
  "nombrePaciente":"Paciente de prueba",
  "codMedEmisor":"MED000001",
  "nombreMedicoEmisor":"Médico emisor",
  "codEspDestino":"ESP000002",
  "nombreEspecialidad":"Especialidad destino",
  "codMedDestino":"MED000002",
  "nombreMedicoDestino":"Médico destinatario",
  "estado":"Pendiente",
  "fechaDerivacion":"2026-11-14T10:00:00-04:00",
  "codCitHija":null
}
```

Ejemplo ficticio de análisis recién solicitado:

```json
{
  "codAna":"ANA000001",
  "codCit":"CIT00000001",
  "solicitadoPorUsu":"USU000003",
  "tipoAnalisis":"Hemograma",
  "estado":"Pendiente",
  "resultado":null,
  "fechaRegistro":"2026-11-14T10:00:00-04:00",
  "fechaEntregaEstimada":null,
  "fechaEntregaReal":null
}
```

### Secretaría: ROL002 activo

| Método y ruta | Parámetros/cuerpo | Éxito |
|---|---|---|
| `GET /api/v1/secretaria/derivaciones` | `estado` opcional, por defecto Pendiente; paginación 0/20 con límites idénticos al médico | 200, página administrativa sin motivo ni resultados |
| `POST /api/v1/secretaria/citas` | Tradicional: `{"codPac":"PAC000001","codSlot":"SLT00000001"}` | 201, `CitaRecepcion` actual |
| `POST /api/v1/secretaria/citas` | Derivada: `{"codDer":"DER000001","codSlot":"SLT00000001"}`; `codPac` opcional, debe coincidir si se envía | 201, `CitaRecepcion` con canal Derivacion |

Se reutilizan sin cambios los endpoints de disponibilidad, check-in, detalle y cancelación de Secretaría. Ejemplo de respuesta de reserva derivada (mismo contrato existente):

```json
{
  "codCit":"CIT00000002","codSlot":"SLT00000001","codPac":"PAC000001",
  "nombrePaciente":"Paciente de prueba","codMed":"MED000002","nombreMedico":"Médico destinatario",
  "codEsp":"ESP000002","nombreEspecialidad":"Especialidad destino","fecha":"2026-11-15",
  "horaInicio":"10:00:00","horaFin":"10:30:00","estado":"Confirmada",
  "canalReserva":"Derivacion","codSec":"SEC000001","qrDisponible":true
}
```

### Errores

- 400 `VALIDACION`: campos desconocidos, datos vacíos/excesivos, identificadores inválidos, paginación o estado de consulta inválidos, resultado ausente al completar. Reservas vencidas/fuera de ventana conservan 400 del motor.
- 401: token ausente, inválido, expirado o revocado; formato actual de autenticación.
- 403: rol no permitido o usuario/perfil inactivo; solo Secretaría programa por su endpoint y solo Médico ejecuta operaciones clínicas. Administrador no recibe permisos clínicos.
- 404 `NO_ENCONTRADO`: cita/derivación/análisis ajeno o inexistente, especialidad inexistente, slot inexistente.
- 409 `DERIVACION_NO_PERMITIDA`: cita origen sin atención finalizada.
- 409 `DESTINO_NO_HABILITADO`: especialidad inactiva o médico no reservable/incompatible.
- 409 `DESTINO_INCOMPATIBLE`: paciente o slot no corresponde al ticket.
- 409 `DERIVACION_NO_DISPONIBLE`: ticket consumido/no habilitado, o conflicto de destino detectado por SQL P4094.
- 409 `ANALISIS_NO_PERMITIDO`: cita fuera de En Espera/Atendida.
- 409 `ANALISIS_NO_MODIFICABLE`: estado final, resultado/documento previo o transición repetida.
- Los conflictos de agenda y concurrencia mantienen el manejo existente. No se publican excepciones SQL, resultados ajenos ni rutas de archivos.

Ejemplo de negocio: `{"ok":false,"error":"DERIVACION_NO_PERMITIDA","mensaje":"La cita origen debe estar atendida."}`. Autenticación conserva `{"error":{"code":"AUTHENTICATION_REQUIRED","message":"Debes iniciar sesion para continuar."}}`.

## D. Base de datos y consistencia

Se usan `usuarios`, `roles`, `medicos`, `pacientes`, `especialidades`, `medico_especialidades`, `horarios`, `slots_agenda`, `citas`, `derivaciones`, `analisis`, `bloqueos_agenda`, `bitacora` y la vista `v_agenda_disponibilidad`.

Funciones reutilizadas: `crear_derivacion`, `reservar_cita`, `reprogramar_cita`, `bloquear_agenda_medico`, `registrar_checkin`, `marcar_cita_atendida`, `fecha_clinica`, `estado_reserva_paciente`, `actor_actual` y `auditar_fila`.

Triggers conservados: `trg_validar_cita`, `trg_procesar_evento_cita`, `trg_auditar_citas`, `trg_validar_analisis`, `trg_auditar_analisis`, `trg_auditar_derivaciones` y la inmutabilidad de `bitacora`. Se mantienen las claves foráneas, unicidad de `derivaciones.cod_cit_hija`, exclusiones de solapamiento e índice parcial de cita activa por slot.

**Fue necesaria V10**, exclusivamente para actualizar las definiciones de dos funciones existentes, sin agregar tablas ni columnas:

1. `reservar_cita` rechazaba ROL002 cuando recibía `codDer`. Se habilita Secretaría activa para ese canal y se conserva el resto del motor V9. El ticket debe estar Pendiente y sin hija; los conflictos se traducen a P4094/HTTP 409.
2. `reprogramar_cita` no conservaba el ticket ni validaba el destino original de una cita derivada. Se mantiene su reserva centralizada, la cadena de reprogramaciones y la transacción, añadiendo validación del destino, canal y actualización del enlace vigente.

Las migraciones históricas no se editaron. V10 se ejecuta por Flyway tanto sobre B9 como sobre la secuencia histórica. Solo se aplicó en el servidor aislado de pruebas. El arranque habitual aplicará V10 por el mecanismo Flyway ya configurado; esta fase no arrancó la aplicación contra la base habitual ni ejecutó SQL allí.

No se introdujo una restricción única retroactiva sobre interconsultas o análisis que pudiera invalidar datos anteriores. La API serializa deduplicación y escritura bajo la cita padre. Los cambios de resultados usan el orden de bloqueo cita -> análisis y validan el estado después de adquirir el bloqueo. Auditoría y cambios se confirman o revierten juntos.

## E. Compilación y pruebas

Resultados reales del 14 de noviembre de 2026, con Java 25, Quarkus 3.39.4 y PostgreSQL 15.19:

| Verificación | Resultado |
|---|---|
| `mvnw.cmd -o -DskipTests compile` | BUILD SUCCESS |
| `mvnw.cmd -o -Dtest=MedicoSeguimientoTest test` | 49 aprobadas, 0 fallos, 0 errores, 0 omitidas; duración total 2 min 56 s |
| `scripts/integration/Invoke-Verification.ps1 -Mode Backend -Offline` | BUILD SUCCESS, código 0; `verify` con `skipITs=false`; duración 10 min 10 s |
| Surefire en la verificación completa | 342 aprobadas, 0 fallos, 0 errores, 0 omitidas |
| Failsafe / `RuntimeSecurityIT` | 1 aprobada, 0 fallos, 0 errores, 0 omitidas; JAR iniciado con perfil prod sobre la base aislada V10 |
| Total de la verificación final | **343 pruebas aprobadas**; incluye las 49 nuevas y las 32 de Fase 1 |
| Empaquetado | Generado `target/quarkus-app/`, con arranque real de `quarkus-run.jar` verificado |
| Guía manual | Los 6 bloques PowerShell y los 3 ejemplos JSON pasaron validación de sintaxis |
| Revisión del diff | `git diff --check` sin errores de whitespace |

Las 49 pruebas nuevas se ejecutaron primero de forma aislada y nuevamente dentro de las 343 de la verificación final; no se suman dos veces como pruebas distintas. Aprobaron las regresiones existentes de agendas (30), actualización de agendas (5), especialidades (16), check-in (20), gestión de citas (47), recepción (24), sanciones (33), administración de Secretaría (21), autorización de personal (19), compatibilidad Flyway (2), autenticación y demás componentes. Los reportes finales no contienen pruebas fallidas ni omitidas. No se desactivaron pruebas.

Evidencia local, ignorada por Git: `target/medico-fase2-compile.log`, `target/medico-fase2-tests.log`, `target/medico-fase2-verify.log`, `target/surefire-reports/` y `target/failsafe-reports/`.

No ejecutado: navegador, pruebas Laravel, recorrido manual con cuentas habituales, compilación nativa, despliegue y validación exhaustiva de Fase 3. La guía manual describe operaciones reales, pero su recorrido no se presenta como ejecutado. El flujo entre módulos sí fue ejercitado por HTTP y SQL en las pruebas automatizadas.

Las pruebas nuevas usan HTTP real de Quarkus, funciones SQL y PostgreSQL 15 aislado en `127.0.0.1:35441/clinica_fase12_tests`. Las fixtures se crean únicamente allí; no se desactivan triggers o restricciones ni se alteran datos de la base habitual.

Incluyen flujo completo de derivación -> programación -> agenda destinataria -> check-in -> atención, propiedad, catálogos activos, destinos inválidos, solicitudes duplicadas secuenciales/concurrentes, reserva simultánea con distintos slots, rollback después de reservar y reintento, reprogramación vinculada, reserva tradicional, solicitudes de análisis en espera/atendidas, resultados, estados finales, auditoría, JSON mínimo y roles.

La expiración de sesión también se verifica en la prueba existente `AuthServiceTest.expiredSessionIsRejected`, reutilizada por las nuevas rutas. La verificación integral de Fase 3, frontend y navegador no forman parte de esta ejecución.

## F. Problemas y límites

- Incompatibilidad real de permisos SQL para Secretaría: corregida en V10, sin suplantar un médico o administrador.
- Pérdida de asociación del ticket al reprogramar: corregida en la misma migración incremental.
- No hay catálogo de estudios, actor de laboratorio ni almacenamiento de documentos. Se implementa el alcance de la tabla actual, con resultados del médico solicitante y sin PDF, entregas, recetas ni historia clínica adicional.
- La deduplicación semántica devuelve solicitudes previas del mismo tipo dentro de una cita. No existe un identificador persistente de nueva orden/repetición de estudio dentro de la misma consulta. No se inventó ese flujo; estudios distintos sí se admiten.
- Los estados de derivación `Completada` y `Cancelada` se pueden consultar cuando existan, pero no se añadieron operaciones clínicas/administrativas nuevas para cambiarlos. La programación usa Pendiente -> Agendada y la atención de la hija conserva las reglas de Fase 1.
- La cancelación de una hija no reactiva automáticamente el ticket. Se mantiene la trazabilidad y su uso único. Cambios de fecha antes del cierre/cancelación usan `reprogramar_cita`.
- El sistema conserva sus sesiones locales al proceso. Se observaron las advertencias preexistentes sobre `quarkus.hibernate-orm.database.generation` y la clave de logging del proceso de integración. Al terminar ese proceso, el harness imprime `Process exited with non-zero status: 1`, como en los registros anteriores y en Fase 1; Failsafe confirma 1 prueba aprobada, sin fallos/errores, y Maven termina con código 0 y BUILD SUCCESS. No se modificó ese harness ajeno al alcance funcional.

## G. Guía manual PowerShell

Usar cuentas activas, una consulta propia ya atendida y datos de prueba autorizados. El backend debe haber arrancado con Flyway V10 aplicado. No cambiar reloj, datos de agenda ni credenciales para forzar check-in. Un turno futuro solo admite check-in el día correspondiente; el análisis puede solicitarse ahora desde la consulta origen ya atendida.

### 1–4. Médico emisor, catálogos y derivación

```powershell
$api = 'http://127.0.0.1:8080/api/v1'
function New-ClinicaSesion {
    param([string]$Correo)
    $secreto = Read-Host 'Contraseña' -AsSecureString
    $credencial = [PSCredential]::new($Correo, $secreto)
    $cuerpo = @{email=$Correo; password=$credencial.GetNetworkCredential().Password} | ConvertTo-Json
    try {
        $respuesta = Invoke-RestMethod -Method Post -Uri "$api/auth/login" -ContentType 'application/json' -Body $cuerpo
        return @{Authorization='Bearer ' + $respuesta.data.session.accessToken}
    } finally { Remove-Variable secreto,credencial,cuerpo,respuesta -ErrorAction SilentlyContinue }
}
$medico = New-ClinicaSesion -Correo (Read-Host 'Correo del médico emisor')
$codCit = Read-Host 'Cita propia atendida de prueba'
$origen = Invoke-RestMethod -Uri "$api/medico/citas/$codCit" -Headers $medico
if ($origen.estado -ne 'Atendida') { throw 'Primero completar el flujo de check-in y atención de Fase 1.' }
Invoke-RestMethod -Uri "$api/medico/especialidades" -Headers $medico
$codEsp = Read-Host 'Especialidad destino activa'
Invoke-RestMethod -Uri "$api/medico/medicos?codEsp=$codEsp" -Headers $medico
$codDestino = Read-Host 'Médico destino; vacío permite cualquier médico habilitado de esa especialidad'
$solicitud = @{codEspDestino=$codEsp; motivo='Interconsulta de prueba autorizada'}
if ($codDestino) { $solicitud.codMedDestino=$codDestino }
$derivacion = Invoke-RestMethod -Method Post -Uri "$api/medico/citas/$codCit/derivaciones" `
    -Headers $medico -ContentType 'application/json' -Body ($solicitud | ConvertTo-Json)
$codDer = $derivacion.codDer
Invoke-RestMethod -Uri "$api/medico/derivaciones/$codDer" -Headers $medico
Invoke-RestMethod -Uri "$api/medico/derivaciones?estado=Pendiente&page=0&size=20" -Headers $medico
```

### 5–8. Secretaría, disponibilidad, cita hija y vínculo

```powershell
$secretaria = New-ClinicaSesion -Correo (Read-Host 'Correo de Secretaría')
Invoke-RestMethod -Uri "$api/secretaria/derivaciones?estado=Pendiente&page=0&size=20" -Headers $secretaria
$fecha = Read-Host 'Fecha del turno destino (YYYY-MM-DD; hoy a D+14)'
$consulta = "$api/secretaria/disponibilidad?codEsp=$codEsp&codPac=$($derivacion.codPac)&fecha=$fecha"
if ($codDestino) { $consulta += "&codMed=$codDestino" }
$cupos = Invoke-RestMethod -Uri $consulta -Headers $secretaria
$cupos | Select-Object codSlot,codMed,nombreMedico,fecha,horaInicio,horaFin
$codSlot = Read-Host 'Slot disponible elegido de esa lista'
$hija = Invoke-RestMethod -Method Post -Uri "$api/secretaria/citas" -Headers $secretaria `
    -ContentType 'application/json' -Body (@{codDer=$codDer;codSlot=$codSlot} | ConvertTo-Json)
$hija | Select-Object codCit,codPac,codMed,codEsp,fecha,estado,canalReserva
$enlace = Invoke-RestMethod -Uri "$api/medico/derivaciones/$codDer" -Headers $medico
if ($enlace.codCitOrigen -ne $codCit -or $enlace.codCitHija -ne $hija.codCit -or $hija.codPac -ne $origen.codPac) {
    throw 'Vínculo administrativo incorrecto'
}
```

### 9–12. Destinatario, agenda, análisis y resultado

```powershell
$destinatario = New-ClinicaSesion -Correo (Read-Host 'Correo del médico asignado a la hija')
Invoke-RestMethod -Uri "$api/medico/agenda?fecha=$fecha" -Headers $destinatario
Invoke-RestMethod -Uri "$api/medico/citas/$($hija.codCit)" -Headers $destinatario

# El emisor solicita el análisis de la consulta origen ya atendida.
$analisis = Invoke-RestMethod -Method Post -Uri "$api/medico/citas/$codCit/analisis" -Headers $medico `
    -ContentType 'application/json' -Body (@{tipoAnalisis='Hemograma de prueba'} | ConvertTo-Json)
Invoke-RestMethod -Uri "$api/medico/citas/$codCit/analisis" -Headers $medico
Invoke-RestMethod -Method Patch -Uri "$api/medico/analisis/$($analisis.codAna)" -Headers $medico `
    -ContentType 'application/json' -Body '{"estado":"En Proceso"}'
Invoke-RestMethod -Method Patch -Uri "$api/medico/analisis/$($analisis.codAna)" -Headers $medico `
    -ContentType 'application/json' -Body '{"estado":"Completado","resultado":"Resultado de prueba autorizado"}'
```

Para atender también la hija, el día de su cita registrar su QR por el check-in existente y luego usar el cierre de Fase 1. El QR se ingresa oculto, no se imprime:

```powershell
$qrSecreto = Read-Host 'QR de la cita hija de hoy' -AsSecureString
$qrCredencial = [PSCredential]::new('qr',$qrSecreto)
$cuerpoQr = @{tokenQr=$qrCredencial.GetNetworkCredential().Password} | ConvertTo-Json
try {
    Invoke-RestMethod -Method Post -Uri "$api/secretaria/checkin" -Headers $secretaria `
        -ContentType 'application/json' -Body $cuerpoQr
} finally { Remove-Variable qrSecreto,qrCredencial,cuerpoQr -ErrorAction SilentlyContinue }
Invoke-RestMethod -Method Post -Uri "$api/medico/citas/$($hija.codCit)/atender" `
    -Headers $destinatario -ContentType 'application/json' -Body '{}'
# Desde En Espera o Atendida, el destinatario puede solicitar análisis de SU cita hija.
```

### 13. Rechazos, reintentos y cierre de sesiones

```powershell
function Test-ClinicaHttp {
    param([string]$Uri,[string]$Metodo='Get',[hashtable]$Cabeceras=@{},[string]$Cuerpo,[int]$Esperado)
    $opciones=@{Uri=$Uri;Method=$Metodo;Headers=$Cabeceras;ErrorAction='Stop'}
    if ($Cuerpo) { $opciones.Body=$Cuerpo; $opciones.ContentType='application/json' }
    try { $null=Invoke-RestMethod @opciones; throw 'La petición debía ser rechazada.' }
    catch {
        if ($null -eq $_.Exception.Response) { throw }
        $codigo=[int]$_.Exception.Response.StatusCode
        if ($codigo -ne $Esperado) { throw "HTTP recibido $codigo; esperado $Esperado" }
        Write-Host "HTTP $codigo correcto"
    }
}
Test-ClinicaHttp -Uri "$api/medico/derivaciones" -Esperado 401
Test-ClinicaHttp -Uri "$api/medico/citas/$codCit/analisis" -Cabeceras $secretaria -Esperado 403
# Usar médicos distintos para comprobar propiedad:
Test-ClinicaHttp -Uri "$api/medico/citas/$codCit/analisis" -Cabeceras $destinatario -Esperado 404
Test-ClinicaHttp -Uri "$api/medico/derivaciones/$codDer" -Cabeceras $destinatario -Esperado 404
Test-ClinicaHttp -Uri "$api/secretaria/citas" -Metodo Post -Cabeceras $secretaria `
    -Cuerpo (@{codDer=$codDer;codSlot=$codSlot} | ConvertTo-Json) -Esperado 409
Test-ClinicaHttp -Uri "$api/medico/analisis/$($analisis.codAna)" -Metodo Patch -Cabeceras $medico `
    -Cuerpo '{"estado":"Completado","resultado":"Intento repetido"}' -Esperado 409
Test-ClinicaHttp -Uri "$api/medico/derivaciones?size=0" -Cabeceras $medico -Esperado 400

# Repetir la misma solicitud de derivación devuelve el mismo codDer, sin otra hija.
$reintento=Invoke-RestMethod -Method Post -Uri "$api/medico/citas/$codCit/derivaciones" -Headers $medico `
    -ContentType 'application/json' -Body ($solicitud | ConvertTo-Json)
if ($reintento.codDer -ne $codDer) { throw 'El reintento duplicó la derivación' }

foreach ($cabecera in @($medico,$secretaria,$destinatario)) {
    $null=Invoke-RestMethod -Method Post -Uri "$api/auth/logout" -Headers $cabecera
}
Remove-Variable medico,secretaria,destinatario -ErrorAction SilentlyContinue
```

No imprimir las variables de sesión ni pegar contraseñas/tokens en el historial. Los ejemplos son administrativos y no contienen credenciales reales. Para verificar estados de origen inválidos, usar citas de prueba propias confirmadas/canceladas: emitir una derivación debe devolver 409; solicitar un análisis sin check-in también debe devolver 409.

Para repetir las pruebas disponibles y empaquetado, desde la raíz y con el PostgreSQL aislado existente iniciado:

```powershell
& .\scripts\integration\Invoke-Verification.ps1 -Mode Backend -Offline
```

No apuntar esa ejecución a la base habitual ni recrearla. La configuración y credenciales se leen del runtime de pruebas ya existente, sin publicarlas en consola.

## H. Estado final

**Completamente funcional en el alcance de esta fase:** emisión/consulta de derivaciones, programación administrativa por Secretaría, relaciones padre/hija y reprogramación, agenda/check-in/atención del destinatario, solicitudes/consulta/resultados textuales de análisis, permisos, deduplicación, transacciones y auditoría. Fase 1 conserva sus reglas y sus 32 pruebas aprobadas.

**Parcialmente implementado:** ninguna operación fundamental solicitada quedó a medias dentro del alcance mínimo compatible con el esquema. El módulo de análisis implementa solicitudes, consulta y resultados; no pretende representar un laboratorio completo.

**Pendiente/fuera del alcance:** almacenamiento o descarga de PDF, entrega al paciente, nuevas reglas de laboratorio, repetición explícita de una misma orden de estudio dentro de una cita, frontend, despliegue y recorrido exhaustivo de Fase 3. No se inició esa fase ni se realizaron commits o push.

El backend queda preparado para Fase 3. El frontend puede comenzar a consumir los contratos documentados después de arrancar el backend actualizado y aplicar V10 con el Flyway habitual. La migración no se ejecutó contra la base habitual durante esta implementación.
