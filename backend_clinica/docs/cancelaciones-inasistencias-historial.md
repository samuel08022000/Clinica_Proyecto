# Recepción: cancelaciones, inasistencias e historial — Fase 7

## Diagnóstico

El punto de partida fue `438ef34` (Fase 6). El backend no tenía cambios locales;
las eliminaciones de documentos y el PDF sin seguimiento fuera del backend eran
preexistentes. Java requerido: 25; Quarkus: 3.39.4. No hay `AGENTS.md` aplicable
al backend.

| Funcionalidad | Componente existente reutilizado | Ausencia o problema | Solución |
| --- | --- | --- | --- |
| Cancelación | `cancelar_cita`, campos de cancelación en `citas` | Sin endpoint; bloqueaba cita antes de coordinar agenda | V8 conserva firma, coordina primero por médico y devuelve errores controlados |
| Inasistencia | `marcar_inasistencias_vencidas`, `ClinicaDaemonScheduler` cada cinco minutos | Sin operación manual ni coordinación con check-in/bloqueos | Núcleo SQL compartido y envoltura manual autorizada |
| Incidencias | `procesar_evento_cita`, `incidencias_sanciones` inmutable | Actor de sistema podía heredar contexto de conexión | Contexto local explícito: usuario autenticado o NULL para sistema |
| Historial | `v_historial_citas`, `CitaRepository` | Sin filtros, paginación o motivos/responsables | Proyección DTO sobre la vista y joins existentes |
| Reserva y disponibilidad | `reservar_cita`, exclusiones, índice parcial y `v_agenda_disponibilidad` | Cancelación no compartía el bloqueo de V5 | Reutilizarlo; nunca escribir slots para forzar disponibilidad |
| Permisos y auditoría | `AuthService`, `DbActorHelper`, `auditar_fila` | Faltaban rutas protegidas | Exclusivamente `ROL002`, actor derivado de sesión |

`ReservaCitaController` e `IncidenciaNotificacionController` estaban vacíos. No
existía una cancelación HTTP de paciente que conservar; sí se conserva y prueba
la política de propiedad de `cancelar_cita` para pacientes. No se crean rutas
nuevas de paciente en esta fase.

## Contratos de recepción

Todas las rutas nuevas requieren Bearer válido con **ROL002**, incluyendo las
consultas. ROL001, ROL003 y ROL004 reciben 403; no se amplían permisos de administrador.
Las respuestas exitosas llevan `Cache-Control: no-store` y `Pragma: no-cache`.
Las rutas y JSON de las fases anteriores no cambian.

| Método | Ruta | Uso |
| --- | --- | --- |
| POST | `/api/v1/secretaria/citas/{codCit}/cancelar` | Cancelar con motivo |
| POST | `/api/v1/secretaria/citas/{codCit}/inasistencia` | Registrar vencimiento sin llegada |
| GET | `/api/v1/secretaria/citas` | Agenda administrativa paginada |
| GET | `/api/v1/secretaria/pacientes/{codPac}/historial` | Cronología administrativa paginada |

Cancelación, solicitud y respuesta ficticias:

```json
{"motivo":"El paciente solicitó cancelar su consulta"}
```

```json
{
  "codCit":"CIT00000001",
  "estado":"Cancelada",
  "motivoCancelacion":"El paciente solicitó cancelar su consulta",
  "canceladoPorUsu":"USU000001",
  "canceladoEn":"2026-11-11T09:00:00-04:00",
  "cancelada":true
}
```

El motivo es obligatorio, se recortan espacios exteriores y se admiten de 1 a
2000 caracteres Unicode. PostgreSQL usa `text` sin máximo declarado; **2000 es
un límite de API y función**, no una longitud inventada de columna. Se rechazan
campos adicionales, incluyendo actor, estado, cita o fecha de auditoría.

Inasistencia no admite datos del cliente; el cuerpo puede estar ausente o ser `{}`:

```json
{
  "codCit":"CIT00000002",
  "estado":"No Asistió",
  "codInc":"INC000001",
  "registradoPorUsu":"USU000001",
  "fechaRegistro":"2026-11-11T10:31:00-04:00",
  "motivo":"Inasistencia sin check-in antes del fin de la cita.",
  "inasistenciaRegistrada":true
}
```

Consultas:

- `GET /citas`: filtros `fecha`, `desde`, `hasta`, `estado`, `codPac`, `codMed`,
  `page`, `size`. `fecha` no se combina con `desde/hasta`. Sin fechas se consulta
  el día clínico actual, incluso si hay otros filtros.
- `GET /pacientes/{codPac}/historial`: `desde`, `hasta`, `estado`, `page`, `size`.
  Sin fechas incluye toda su cronología, siempre paginada. No aplica D+14 a la
  consulta histórica ni exige que el paciente pueda realizar nuevas reservas.
- Fechas inclusivas `YYYY-MM-DD`, años 0001–9999, rangos coherentes.
- `page` comienza en 0, máximo 10000; `size` por defecto 20, entre 1 y 50.
  Parámetros no numéricos o desbordados devuelven 400.
- Orden ascendente y estable: fecha, hora de inicio y código de cita.

Ejemplo de página, con todos los campos reales del DTO:

```json
{
  "citas":[{
    "codCit":"CIT00000001",
    "codPac":"PAC000001",
    "nombrePaciente":"Paciente Ejemplo",
    "codMed":"MED000001",
    "nombreMedico":"Profesional Ejemplo",
    "codEsp":"ESP000001",
    "nombreEspecialidad":"Especialidad Ejemplo",
    "fecha":"2026-11-11",
    "horaInicio":"09:00:00",
    "horaFin":"09:30:00",
    "estado":"Cancelada",
    "canalReserva":"Ventanilla",
    "motivoCancelacion":"El paciente solicitó cancelar su consulta",
    "canceladoPorUsu":"USU000001",
    "canceladoEn":"2026-11-11T08:30:00-04:00",
    "horaLlegadaCheckin":null,
    "checkinPorUsu":null,
    "fechaAtencion":null,
    "codIncInasistencia":null,
    "inasistenciaRegistradaEn":null,
    "inasistenciaPorUsu":null,
    "motivoInasistencia":null
  }],
  "page":0,
  "size":20,
  "hayMas":false
}
```

Se consulta `size+1` para conocer `hayMas`, sin conteo global. La proyección plana
incluye cancelación e inasistencia en una sola consulta, sin cargar colecciones
por cita. No hay N+1. Si datos antiguos contienen varias incidencias de inasistencia,
se muestra la última por secuencial sin borrar las anteriores. Un índice no único
por cita/tipo respalda las consultas y comprobaciones. No se crea un historial
paralelo ni una ruta redundante de incidencias. Sanciones y otras incidencias no
se exponen mediante estas rutas.

## Estados, ventanas y concurrencia

Estados reales: `Confirmada`, `En Espera`, `Atendida`, `Cancelada`, `No Asistió`,
`Afectada/Pendiente Reprogramación`, `Reprogramada`.

Cancelación permite `Confirmada` y `Afectada/Pendiente Reprogramación`, sin llegada
ni incidencia de cancelación anterior. Rechaza los demás estados con 409. V1
permitía implícitamente cancelar `En Espera`; se cierra esa posibilidad hasta
contar con una política explícita posterior al check-in, conforme al criterio
conservador solicitado. No se borran ni sobrescriben llegadas, incidencias o citas.
No se impone un plazo de anticipación adicional que el modelo no define.

La cita cancelada deja de ocupar el slot por el índice parcial y las exclusiones
existentes. La vista central decide si vuelve a ofrecerse. Un bloqueo, perfil
inactivo, versión retirada, cita incompatible o fecha fuera de ventana impide
ofrecerlo aunque su reserva anterior esté cancelada. No se modifica `slots_agenda`.

Inasistencia requiere `Confirmada`, ambos campos de check-in nulos, ninguna
incidencia de inasistencia anterior y `fecha + hora_fin < ahora_clinica()`. Se
conserva el criterio de V1: finalización estrictamente vencida, sin tolerancia
inventada. `ahora_clinica()` usa la zona `America/La_Paz` y el instante inicial de
la transacción PostgreSQL; es coherente con el trigger de validación existente.
El caso de igualdad no está vencido. Una operación iniciada antes del fin puede
requerir reintento después, incluso si esperó un bloqueo durante la transacción.

Manual y automático comparten `procesar_inasistencia_vencida`. La envoltura manual
verifica usuario, rol y perfil de secretaría activos y devuelve 409 ante estado
incompatible. La envoltura automática revalida y omite las citas ya procesadas.
El núcleo interno no tiene permiso de ejecución para PUBLIC. La tarea existente
sigue ejecutándose cada cinco minutos; no se crea otro scheduler. Para sistema,
`registrado_por_usu` y actor de bitácora son NULL y nunca un usuario heredado de
otra petición. La hora y motivo permanecen en la incidencia existente.

Cancelación, inasistencia, reserva, check-in y bloqueo adquieren el bloqueo por
médico de V5 antes del bloqueo de cita/slot. Solo una cancelación o llegada/
inasistencia efectiva puede ganar sobre la misma cita. Una reserva concurrente
puede encontrar el turno todavía ocupado y recibir 409; puede reintentarse tras
confirmarse la cancelación. Los triggers mantienen una incidencia por transición,
y las funciones rechazan reutilizar un estado con incidencia anterior. No se
impone un índice único que pudiese impedir migrar datos históricos duplicados.

El check-in existente permite la llegada durante el mismo día clínico: no se le
añade un corte horario en esta fase. Tras vencer el turno, check-in e inasistencia
pueden competir; el bloqueo y el estado final deciden cuál se confirma. Definir
una tolerancia o cierre de acreditación diferente requiere una política aprobada.

## Errores, auditoría y migración

Se mantiene `{ "ok": false, "error": "...", "mensaje": "..." }`.
Validación 400; sesión ausente/inválida/revocada 401; rol o actor no autorizado 403;
recurso inexistente 404; transición o ventana incompatible 409. Los mensajes
nuevos son estáticos, sin SQLSTATE, SQL ni datos internos.

`DbActorHelper` recibe únicamente la identidad de `AuthService`. La función vuelve
a comprobar permisos y fija el mismo actor local a la transacción. Los triggers
existentes de eventos y bitácora registran motivo, responsable y transición.
La cancelación mantiene la cola de notificaciones de V1; esto no significa que
se haya entregado un correo. No se añade otra auditoría, cola ni envío.

V8 reemplaza `cancelar_cita` y `marcar_inasistencias_vencidas` conservando firmas;
añade la envoltura manual, el núcleo compartido y un índice no único. No añade
tablas/columnas, no cambia los QR y no actualiza registros históricos. V1–V7
permanecen intactas. Aplicarla mediante Flyway con el rol de migración habitual.

## Archivos

Modificados: `SecretariaController`, `RecepcionDtos`, `CitaRepository`,
`CitaFunctionRepository`, `TriggerExceptionMapper`, `AgendaMigrationUpgradeTest`.

Nuevos: `GestionCitaService`, `V8__cancelaciones_inasistencias_recepcion.sql`,
`GestionCitaTest`, el adaptador de pruebas `DaemonTestAccess` y este documento.

No se cambia el scheduler Java, autenticación, OTP, Gmail, Google, frontend ni
configuración de herramientas. La Fase 8 no se implementa.

## Verificación ejecutada

Java 25.0.4.1, Quarkus 3.39.4 y PostgreSQL **16.14 aislado**, en loopback,
puerto 35439. Se usaron bases nuevas de pruebas y bases adicionales de actualización;
no se ejecutaron pruebas sobre desarrollo. Comando final:

```text
mvnw.cmd -o -DskipITs=false verify
```

Resultado final: **BUILD SUCCESS**, 11/11/2026 01:30:18 `America/La_Paz`.

| Grupo final | Aprobadas | Fallos | Errores | Omitidas |
| --- | ---: | ---: | ---: | ---: |
| Gestión de citas, historial, permisos y concurrencia | 47 | 0 | 0 | 0 |
| Actualizaciones desde V4, V5, V6 y V7 | 4 | 0 | 0 | 0 |
| Otras regresiones de Surefire, incluidas QR y asistencia | 175 | 0 | 0 | 0 |
| Aplicación JVM empaquetada, Failsafe | 1 | 0 | 0 | 0 |
| **Total** | **227** | **0** | **0** | **0** |

Los 47 casos nuevos cubren:

- Cancelación, motivo, hora, actor de sesión, incidencia y cupo disponible.
- Cupos bloqueados, médicos/especialidades inactivos, versiones retiradas,
  fechas pasadas y fuera de ventana; se conserva la cita y su slot histórico.
- Rechazo de cancelaciones repetidas, atendidas, ausencias, reprogramadas y con llegada.
- Validación de entradas, campos de suplantación y recursos inexistentes.
- Rollback íntegro de cancelación e inasistencia, seguido de reintento exitoso.
- Inasistencia válida, anticipada, duplicada y estados incompatibles.
- Invocación directa de la tarea automática real, con actor de sistema NULL,
  contexto anterior deliberado y zona de sesión PostgreSQL `Asia/Tokyo`.
- Propiedad del paciente en la función SQL de cancelación existente.
- Historial por paciente, filtros, paginación, orden estable incluso con empates,
  campos de respuesta permitidos y conservación tras inactivación y cambio de duración.
- Parámetros de página/fecha inválidos, incluidos desbordamientos y año cero.
- 401 sin sesión o con sesión inválida; 403 para paciente, médico y administrador;
  headers falsificados sin efecto y rechazo de identificadores de actor en JSON.

Las cinco carreras solicitadas se ejecutaron con barrera y dos tareas concurrentes:

| Carrera | Resultado comprobado |
| --- | --- |
| Dos cancelaciones | Un 200 y un 409; una transición, una incidencia y actor ganador correcto |
| Cancelación/reserva | Cancelación 200; reserva 201 o 409 según el orden; reintento tras cancelación confirma y queda una sola reserva activa |
| Cancelación/check-in | Un 200 y un 409; estado final coherente, sin cancelar una llegada confirmada |
| Inasistencia/check-in | Un 200 y un 409; una llegada o una ausencia, sin ambos registros |
| Inasistencia manual/automática | Un único cambio e incidencia; el manual confirma o recibe 409 si ganó la tarea |

La prueba desde V7 conserva una cancelación previa, su actor, motivo e incidencia,
los QR de las citas y el bloqueo histórico. El segundo intento de cancelar esa
cita tras migrar se rechaza. Las funciones de QR de V7 no se sustituyen en V8.

Hubo dos corridas previas fallidas, conservadas en los logs:

- `fase7-verificacion.log`: 222 casos de Surefire, 17 fallos y 2 errores. Cambiar
  la firma de paginación durante la inicialización dejó invocadores desactualizados;
  dos tareas del fixture también intentaban leer JPA sin contexto activo.
- `fase7-final.log`: 226 casos de Surefire, 1 fallo y 0 errores. La petición del
  fixture pasaba objetos `LocalDate` en filtros, en lugar de cadenas ISO explícitas.
- `fase7-suite-final.log`: corrida final descrita arriba, 226 Surefire + 1 Failsafe,
  todos aprobados. Se corrigieron los fixtures y se reinició la ejecución con
  firmas estables; no se eliminaron ni deshabilitaron pruebas.

Los logs están en `target/`, junto con los informes Surefire/Failsafe.
Los temporizadores permanecen desactivados durante las pruebas: se invoca la tarea
real directamente. No se verificó el disparo por reloj cada cinco minutos,
entrega real de Gmail, un intercambio real con Google, PostgreSQL 15, binario
nativo ni carga masiva. Las regresiones de proveedores utilizan los dobles y
modos de prueba existentes.

V8 solo se aplicó a bases aisladas. Las migraciones históricas y el frontend
permanecen sin cambios. Persisten los avisos previos de `quarkus.http.cors`
no reconocido y de la propiedad obsoleta de generación de esquema de Hibernate.

El clúster temporal de esta fase fue detenido y retirado tras finalizar la suite.
Se conservaron los tres logs. Revisión final de Git: seis archivos modificados y
cinco nuevos dentro del backend; documentos locales preexistentes intactos respecto
del estado inicial, sin cambios en frontend ni migraciones históricas, sin ramas
nuevas, commits o push. `git diff --check` no encontró errores de espacios.
