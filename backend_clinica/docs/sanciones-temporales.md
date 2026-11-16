# Administración de sanciones temporales — Fase 8

## Diagnóstico y decisiones

Punto de partida: `1c5cdc6`, Fase 7. Backend sin cambios locales iniciales;
documentos eliminados y PDF sin seguimiento fuera del backend eran preexistentes.
Java requerido: 25; Quarkus: 3.39.4. No hay instrucciones `AGENTS.md` aplicables al backend.

| Funcionalidad | Componente existente | Problema o ausencia | Cambio |
| --- | --- | --- | --- |
| Aplicar sanción | `registrar_sancion`, `trg_aplicar_sancion_paciente` | Sin endpoints, motivo vacío admitido y solapamientos acumulados | Validar y serializar por fila de paciente, preservar firmas |
| Reservas restringidas | `reservar_cita` V6, bloqueo de paciente `FOR UPDATE` | Estado persistido impedía reservar después del vencimiento hasta el cron | Comprobación de estado efectivo dentro del motor existente |
| Finalización | `desbloquear_sanciones_vencidas`, scheduler cada 5 minutos | Dependencia del cron y posible actor heredado | Vigencia de lectura inmediata; limpieza existente con actor de sistema |
| Perfil autenticado | `AuthAccountGateway` y `AuthService` | Sanción vencida seguía causando 423 hasta el cron | Ajustar solo composición del estado del paciente, reutilizando la misma función SQL |
| Consultas | `PacienteRepository`, `IncidenciaSancion` | Sin paginación administrativa de incidencias/sanciones | DTO y consultas parametrizadas |
| Historial y auditoría | `incidencias_sanciones`, bitácora inmutable | Ya implementados | Reutilizar sin editar ni eliminar registros |
| Levantamiento anticipado | Ninguno | No hay política ni evento de reversión definido | Pendiente; no se expone una operación insegura |

El modelo real utiliza `pacientes.estado_cuenta`, `pacientes.bloqueado_hasta` y
las incidencias `SANCION_BLOQUEO`, con `fecha_registro`, `fecha_fin_sancion` y
`registrado_por_usu`. El identificador es `cod_inc`; no se inventa una tabla de sanciones.

## Contratos HTTP

Exclusivamente Bearer **ROL004**, validado por `AuthService`. Paciente, secretario y
médico reciben 403; sesión ausente/inválida recibe 401. Los headers de actor se ignoran.
Respuestas exitosas con `Cache-Control: no-store` y `Pragma: no-cache`.

| Método | Ruta |
| --- | --- |
| GET | `/api/v1/administracion/pacientes` |
| GET | `/api/v1/administracion/pacientes/{codPac}` |
| GET | `/api/v1/administracion/pacientes/{codPac}/incidencias` |
| GET | `/api/v1/administracion/pacientes/{codPac}/sanciones` |
| GET | `/api/v1/administracion/pacientes/{codPac}/sanciones/{codInc}` |
| POST | `/api/v1/administracion/pacientes/{codPac}/sanciones` |

La consulta individual adicional sirve como recurso de la cabecera `Location` del
POST y permite consultar vigencia/finalización. Una sanción de otro paciente devuelve 404.

Solicitud de creación:

```json
{"motivo":"Inasistencias reiteradas revisadas por administración","dias":7}
```

`dias` es un entero JSON de 1 a 365, como en la función existente. No se aceptan
fracciones, cadenas numéricas, booleanos ni `fechaFin`. El motivo se recorta y
debe tener de 1 a 2000 caracteres; este máximo es de API/función, pues la columna
real es `text`. No se admiten campos extra, actor, rol, paciente ni fechas de auditoría.

Ejemplo ficticio de respuesta 201:

```json
{
  "codInc":"INC000001",
  "codPac":"PAC000001",
  "codCit":null,
  "tipoIncidencia":"SANCION_BLOQUEO",
  "motivo":"Inasistencias reiteradas revisadas por administración",
  "registradoPorUsu":"USU000001",
  "fechaRegistro":"2026-11-11T09:00:00-04:00",
  "fechaFinSancion":"2026-11-18T09:00:00-04:00",
  "vigente":true
}
```

`codInc` lo genera PostgreSQL. Inicio y fin son instantes con zona; la duración se
calcula expresamente mediante días naturales en `America/La_Paz`, independientemente
de la zona de la conexión. La sanción comienza inmediatamente, no admite inicio programado.

Paginación común: `page=0`, `size=20`, máximo `size=50` y `page=10000`.
Parámetros no numéricos, fuera de rango o desbordados reciben 400.

- Pacientes: filtros opcionales `q` y `estado` (`Activo`, `Inactivo`, `Bloqueado_Temporal`).
  Si se proporciona `q`, requiere 3–100 caracteres y al menos tres letras/números.
  Busca nombre, CI y correo, escapando comodines y usando parámetros. Orden por nombre y código.
- Incidencias: `tipo` opcional entre los cuatro valores reales: `CANCELACION`,
  `INASISTENCIA_NO_SHOW`, `SANCION_BLOQUEO`, `OTRA`.
- Sanciones: `vigente=true|false` opcional. Orden por fecha de registro y secuencial,
  ambos descendentes. Las lecturas no cambian estado ni fechas.

Respuesta de historial:

```json
{"incidencias":[],"page":0,"size":20,"hayMas":false}
```

Cada elemento tiene el contrato de incidencia mostrado arriba. `vigente` solo es
true para `SANCION_BLOQUEO` cuya finalización sigue en el futuro. Las páginas se
calculan con `size+1`, sin consulta global de conteo. No se cargan entidades completas.

El perfil individual conserva `PacienteDetalle` de recepción:

```json
{
  "codPac":"PAC000001",
  "nombreCompleto":"Paciente Ejemplo",
  "ci":"1234567",
  "email":"paciente@example.test",
  "telefono":"70000000",
  "ciudadResidencia":"La Paz",
  "estadoCuenta":"Bloqueado_Temporal",
  "estadoUsuario":"Activo",
  "emailVerificado":true,
  "bloqueadoHasta":"2026-11-18T09:00:00-04:00",
  "motivoInhabilitacion":"PACIENTE_BLOQUEADO",
  "habilitadoParaReservar":false
}
```

El listado usa `{ "pacientes": [], "page": 0, "size": 20, "hayMas": false }`,
con el mismo perfil por elemento. No devuelve contraseñas, OTP, tokens ni información clínica.
La restricción es global para nuevas reservas Web y Ventanilla; no cancela citas existentes.

## Vigencia, concurrencia e historial

`estado_reserva_paciente` centraliza el estado efectivo en PostgreSQL:

- Un perfil `Inactivo` permanece inactivo, aun si venció una sanción.
- Fecha agregada futura o incidencia de sanción futura significa bloqueo.
- Un `Bloqueado_Temporal` con fecha vencida y sin sanción activa se considera
  `Activo`, aunque el cron todavía no haya limpiado la fila.
- La restricción real de `pacientes` prohíbe `Bloqueado_Temporal` sin fecha final;
  la función conserva además una comprobación defensiva para ese caso.

Las lecturas nunca actualizan la fila. `bloqueadoHasta` puede conservar una fecha
pasada hasta la limpieza; el frontend debe usar la habilitación y vigencia del servidor,
no asumir que cualquier fecha no nula representa una sanción activa.

`registrar_sancion` bloquea primero la misma fila de paciente que usa la reserva.
El trigger vuelve a validar administrador, fechas y ausencia de otra sanción vigente,
incluso en inserciones SQL. Dos administradores se serializan: el segundo encuentra
el bloqueo confirmado y recibe 409. No se prolonga ni reemplaza silenciosamente una sanción.
Se rechazan nuevos bloqueos de perfiles inactivos; no se reactiva un perfil al sancionarlo.

Si la reserva obtiene primero el bloqueo, puede confirmarse antes de la sanción;
la sanción posterior conserva esa cita. Si la sanción se confirma primero, la reserva
revalida bajo bloqueo y se rechaza. No se elimina ni se cambia ninguna cita para aplicar sanciones.

El cron existente sigue limpiando sanciones vencidas. La limpieza verifica que no
quede otra sanción activa, preserva perfiles inactivos y audita con actor de sistema
NULL, restaurando después el contexto previo. No se crea un segundo scheduler.
La vigencia efectiva permite nuevas operaciones iniciadas después del vencimiento
sin esperar esos cinco minutos, sujetas a las restantes reglas de reserva.

La autenticación mantiene su contrato previo: una sanción activa produce 423
`ACCOUNT_TEMPORARILY_BLOCKED`. Se ajusta `AuthAccountGateway` para dejar de devolver
un perfil temporalmente bloqueado cuando ya venció. `AuthService`, contraseñas,
sesiones, OTP, recuperación y Google no se reimplementan ni se modifican.
Decidir si en el futuro una sanción debe restringir solo reservas y permitir login
durante su vigencia requiere una política explícita y otra revisión de compatibilidad.

La decisión de sancionar es manual y exige motivo. No se agrega un umbral arbitrario
de faltas ni sanciones automáticas por cantidad. Se preservan cancelaciones,
inasistencias, check-ins, citas y sanciones anteriores.

No se implementa `/levantar`: las incidencias son inmutables y faltan una política
de reversión y un evento que relacione autorización, motivo y sanción original.
La limpieza por vencimiento no sirve para levantar anticipadamente una sanción.

## Auditoría, errores y migración

`DbActorHelper` recibe al administrador desde la sesión. La función y el trigger
validan rol/usuario activos y registran el mismo actor. Se reutilizan los triggers
de pacientes e incidencias; no se crea otro registro de auditoría. Un rollback
revierte la sanción, el bloqueo y la bitácora de éxito.

Se conserva el formato de errores de negocio `{ "ok": false, "error": "...", "mensaje": "..." }`.
Validación 400, paciente/sanción inexistente 404, conflicto de sanción 409;
errores nuevos estáticos sin SQLSTATE ni SQL público. Los errores de autenticación
conservan su contrato `{ "error": { "code": "...", "message": "..." } }`.

V9 añade una función de lectura y un índice parcial no único, y reemplaza las
funciones de aplicación, trigger y limpieza manteniendo nombres y firmas.
`reservar_cita` conserva íntegro el motor de V6; cambia únicamente la condición
de vigencia del paciente bajo su bloqueo existente. No hay tablas o columnas nuevas.
No se actualizan datos históricos al migrar, ni se intenta deduplicar sanciones
solapadas de versiones anteriores. V1–V8 y la migración repetible permanecen intactas.

## Archivos

Nuevos: `SancionController`, `SancionService`, `SancionDtos`, `IncidenciaRepository`,
`V9__sanciones_temporales_consistentes.sql`, `SancionTest` y este documento.

Modificados: `PacienteRepository`, `AuthAccountGateway`, `TriggerExceptionMapper`
y `AgendaMigrationUpgradeTest`.

## Integración posterior de Laravel

Consumir solo Quarkus con el Bearer correspondiente. Enviar `dias` entero,
convertir fechas ISO con zona para presentación clínica y respetar páginas y `hayMas`.
Mostrar motivos y responsable administrativos sin exponer datos clínicos. Interpretar
401/403, 409 y el 423 preexistente del login. Ocultar acciones según rol complementa
la autorización obligatoria del backend. No habilitar un botón de levantamiento
hasta definir e implementar su política. No conectar Laravel a PostgreSQL.

## Resultados de verificación

Ejecución final con Java 25.0.4.1, Quarkus 3.39.4 y PostgreSQL **16.14 aislado**,
en loopback, puerto 35440. Comando:

```text
mvnw.cmd -o -DskipITs=false verify
```

**BUILD SUCCESS**, 11/11/2026 13:52:14 `America/La_Paz`.

| Grupo | Aprobadas | Fallos | Errores | Omitidas |
| --- | ---: | ---: | ---: | ---: |
| Sanciones, vigencia, reservas, auditoría y permisos | 33 | 0 | 0 | 0 |
| Actualizaciones desde V4, V5, V6, V7 y V8 | 5 | 0 | 0 | 0 |
| Otras regresiones de Surefire | 222 | 0 | 0 | 0 |
| Aplicación JVM empaquetada, Failsafe | 1 | 0 | 0 | 0 |
| **Total** | **261** | **0** | **0** | **0** |

Los casos nuevos verifican creación, consultas y paginación, datos inválidos,
identidad real pese a headers falsificados, actores extra rechazados en JSON,
roles excluidos, sesión ausente/inválida, solapamientos, integridad de estados,
conservación de citas, cancelaciones, inasistencias y QR, rechazo de modificación
del historial y rollback de paciente, sanción y auditoría.

Se verificaron reservas Web directamente contra la función central existente y
Ventanilla mediante el endpoint real. El controlador de reserva web sigue vacío;
no se inventó un endpoint de paciente para esta fase. La protección SQL es común
a ambos canales. El login bloqueado conserva el 423 previo, y después de vencer
la sanción vuelve a funcionar.

Para vencimiento se insertaron periodos de cuatro segundos en registros propios
de la base aislada, usando administrador real y todos los triggers activos. Se
esperó el vencimiento con reloj PostgreSQL, sin cambiar fechas de incidencias
inmutables, deshabilitar restricciones ni esperar días. La API mantiene 1–365 días.
La reserva posterior se comprobó con el estado persistido todavía bloqueado,
antes de ejecutar el cron; después se comprobó su limpieza por la función existente.

| Concurrencia | Resultado comprobado |
| --- | --- |
| Dos administradores | Un 201 y un 409, una sanción y actor ganador correcto |
| Sanción/reserva Web | Sanción 201; reserva 201 o 403 según el orden; después de confirmar la sanción toda nueva reserva se rechaza |
| Sanción/reserva Ventanilla | Mismo criterio, usando petición HTTP real; se conserva una cita confirmada antes de la sanción |
| Vencimiento, limpieza y reserva | Limpieza 200 y reserva 201; historial conservado y perfil activo |
| Levantamiento anticipado | Pendiente por falta de política; no implementado ni contado como aprobado |

La actualización desde V8 incluye dos sanciones históricas solapadas, citas,
cancelación y QR preexistentes. Se conservaron sus registros y fechas y la función
nueva rechazó una tercera sanción vigente. No se modificaron migraciones históricas.

La primera corrida (`target/fase8-verificacion.log`) ejecutó 260 casos: 259
aprobados, 0 fallos y 1 error de fixture al intentar construir un estado que
`pacientes_check` prohíbe (`Bloqueado_Temporal` sin fecha). Se corrigió la prueba
para exigir ese rechazo y comprobar que no dejó cambios ni incidencias. No se
eliminó ni deshabilitó el caso ni se alteró la restricción. La prueba empaquetada
no llegó a ejecutarse en esa primera corrida.

El resultado final está en `target/fase8-final.log` y en los informes Surefire/Failsafe.
Las regresiones incluyen personal, especialidades, agendas, reservas, QR/check-in,
cancelaciones, inasistencias, sesiones, registro/OTP, Google y recuperación.
No se probaron entrega real de Gmail, intercambio real con Google, PostgreSQL 15,
binario nativo, carga masiva ni el disparo del temporizador cada cinco minutos.
Los proveedores usan los dobles existentes; la limpieza se invoca directamente.

V9 se aplicó solamente en bases aisladas. La base de desarrollo no se modificó.
Persisten los avisos previos de `quarkus.http.cors` no reconocido y de una propiedad
obsoleta de generación de esquema de Hibernate; esta fase no cambia esa configuración.

Al finalizar se detuvo y retiró únicamente el clúster temporal de Fase 8. Se
conservaron ambos logs e informes. Revisión final: cuatro archivos modificados y
siete nuevos dentro del backend; frontend, `AuthService` y migraciones anteriores
sin cambios. Los documentos locales preexistentes mantienen su estado inicial.
Sin ramas nuevas, commits ni push. `git diff --check` no detectó errores de espacios.
