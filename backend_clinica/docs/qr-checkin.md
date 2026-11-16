# Acreditación y asistencia en recepción — Fase 6

## Diagnóstico y reutilización

| Funcionalidad | Implementación previa | Cambio de esta fase |
| --- | --- | --- |
| QR firmado | `crear_token_qr`, `citas.token_qr`, clave `QR_HMAC` en PostgreSQL | Validación estricta reutilizable; conserva formato y tokens existentes |
| Llegada | `registrar_checkin`, campos `hora_llegada_checkin` y `checkin_por_usu` | Revalidación transaccional de estado, fecha, bloqueo y llegada anterior |
| Concurrencia | Bloqueo por médico de V5 y bloqueo de cita | Check-in participa del mismo bloqueo, antes de bloquear la fila de cita |
| API de recepción | `SecretariaController`, `AuthService`, `CitaFunctionRepository` | Tres rutas adicionales, exclusivamente `ROL002` |
| Auditoría | `DbActorHelper`, `auditar_fila` y bitácora inmutable | Usa el actor de la sesión y los triggers existentes |

V1 ya firma con HMAC-SHA256 un contenido de código de cita, fecha y nonce aleatorio
de 16 bytes. El token es Base64 del contenido, un punto y la firma hexadecimal.
No se cambia el generador ni la clave. El código de cita por sí solo no acredita
autenticidad. Además de verificar la firma, se exige coincidencia exacta con el
token actualmente almacenado y con la fecha de la cita.

La función anterior no coordinaba el check-in con los bloqueos de V5 ni comprobaba
explícitamente bloqueos o una llegada anterior; tampoco fallaba de forma cerrada
si faltaba la clave. V7 corrige estas condiciones sin actualizar filas históricas.

## Contratos

Todas las rutas requieren `Authorization: Bearer <sesión>` con `ROL002`. Paciente,
médico y administrador reciben 403. Una sesión ausente o inválida recibe 401.
No se admiten identificadores de secretario ni campos extra en el POST.

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | `/api/v1/secretaria/citas/{codCit}/acreditacion` | Autenticidad del QR almacenado y elegibilidad actual |
| POST | `/api/v1/secretaria/checkin` | Registra una sola llegada y devuelve la asistencia |
| GET | `/api/v1/secretaria/citas/{codCit}/asistencia` | Consulta sin modificar la cita |

Ejemplo ficticio de acreditación:

```json
{
  "codCit": "CIT00000001",
  "nombrePaciente": "Paciente Ejemplo",
  "fecha": "2026-11-20",
  "estado": "Confirmada",
  "qrValido": true,
  "admiteCheckin": true
}
```

Solicitud de check-in (el valor mostrado es un marcador, no un token válido):

```json
{"tokenQr": "CONTENIDO_DEL_QR_PRESENTADO"}
```

Respuesta del POST y contrato de consulta de asistencia:

```json
{
  "codCit": "CIT00000001",
  "nombrePaciente": "Paciente Ejemplo",
  "fecha": "2026-11-20",
  "estado": "En Espera",
  "horaLlegadaCheckin": "2026-11-20T08:55:00-04:00",
  "checkinPorUsu": "USU000001",
  "checkinRegistrado": true
}
```

Antes de la llegada, los dos campos de registro son null y
`checkinRegistrado` es false. Los tres endpoints exitosos incluyen
`Cache-Control: no-store`. Ninguna respuesta incluye el token QR. El GET de cita
anterior conserva su contrato con `qrDisponible`; no incluía fecha/hora ni actor
de llegada, por eso se añade la consulta específica de asistencia.

Errores conservan `{ "ok": false, "error": "...", "mensaje": "..." }`:

| Situación | HTTP | error |
| --- | --- | --- |
| JSON/campos/token/formato/firma inválidos | 400 | `QR_INVALIDO` |
| QR auténtico sin cita asociada o sustituido | 404 | `ACREDITACION_NO_ENCONTRADA` |
| Consulta de cita inexistente | 404 | `NO_ENCONTRADO` |
| Estado, fecha, bloqueo o llegada incompatibles | 409 | `CHECKIN_NO_PERMITIDO` |
| Perfil de secretaría no habilitado | 403 | `SECRETARIA_NO_HABILITADA` |
| Clave QR indisponible | 500 | `ERROR_ACREDITACION` |

## Transacción y reglas

La identidad se obtiene de `AuthService`; el servicio deriva el perfil de
secretaría y llama a `CitaFunctionRepository.registrarCheckin`. PostgreSQL valida
también que usuario, rol y perfil estén activos. Los headers de actor se ignoran.

El check-in toma el bloqueo transaccional de agenda por médico y después bloquea
la cita, vuelve a comprobar el token y permite exclusivamente `Confirmada`, sin
llegada previa, del día clínico actual en `America/La_Paz`, sin bloqueo temporal
superpuesto. La transición es `En Espera`. `clock_timestamp()` registra la hora
real después de adquirir los bloqueos. Un segundo intento devuelve 409 y conserva
la hora y actor originales. El trigger existente escribe estado anterior, nuevo,
actor y fecha en bitácora, dentro de la misma transacción. Un rollback también
revierte la llegada y su auditoría.

`qrValido` expresa autenticidad y correspondencia del token, no autorización de
check-in: una cita futura, cancelada o ya acreditada puede mantener un QR auténtico.
`admiteCheckin` añade las condiciones actuales y es orientativo; el POST siempre
revalida bajo bloqueo.

## Migración y límites

Aplicar V7 mediante el Flyway habitual; no modificar V1–V6. V7 incorpora dos
funciones auxiliares y reemplaza la implementación de `registrar_checkin` con su
misma firma. No crea tablas, no cambia claves, no regenera tokens y no cancela citas.

La política existente autoriza el mismo día clínico; no define un margen de
minutos antes/después de la consulta. Los estados `No Asistió`, `Cancelada`,
`Atendida` y `Afectada/Pendiente Reprogramación` no admiten llegada.

La entrega, representación gráfica o descarga del QR al paciente queda para un
flujo autorizado específico. No existe descarga pública ni exposición del token
en URLs. El cliente debe enviar el escaneo por POST y evitar registrar su cuerpo.
No se añade generación de imágenes, notificaciones ni cambios de frontend.

Las pruebas de esta fase utilizan el generador original y una base aislada.
Las citas de hoy/ayer se modelan como reservas preexistentes mediante fixtures
propios, manteniendo activos los triggers y restricciones. Las verificaciones
desde V4, V5 y V6 conservan el token original y comprueban su firma tras actualizar.
Las integraciones reales de Gmail/Google requieren sus servicios externos;
la suite de regresión utiliza los dobles y el modo DEMO ya existentes.

## Archivos de la fase

Modificados:

- `SecretariaController.java`: rutas, autorización existente y respuestas sin caché.
- `AtencionCheckinService.java`: implementación de la clase previamente vacía,
  validación del cuerpo, actor autenticado y llamada transaccional a PostgreSQL.
- `CitaRepository.java`: proyecciones administrativas de acreditación y asistencia.
- `RecepcionDtos.java`: solicitud exclusiva de token y respuestas sin secretos.
- `TriggerExceptionMapper.java`: errores QR estáticos con el formato JSON existente.
- `AgendaMigrationUpgradeTest.java`: actualización desde V6 y conservación de QR.

Nuevos:

- `V7__checkin_qr_validacion_y_concurrencia.sql`.
- `CheckinTest.java`.
- Este documento.

## Resultado de verificación

Ejecución final: `mvnw.cmd -o -DskipITs=false verify`, Java 25.0.4.1,
Quarkus 3.39.4 y PostgreSQL 16.14 aislado en loopback. Terminó el
11 de noviembre de 2026 a las 00:00:09 de Bolivia, con `BUILD SUCCESS`.

| Grupo | Casos aprobados | Fallos / errores / omitidos |
| --- | ---: | --- |
| QR, asistencia, permisos, rollback y concurrencia | 20 | 0 / 0 / 0 |
| Actualización desde V4, V5 y V6 con datos y QR preexistentes | 3 | 0 / 0 / 0 |
| Regresiones restantes de Surefire | 155 | 0 / 0 / 0 |
| Aplicación JVM empaquetada, Failsafe | 1 | 0 / 0 / 0 |
| Total | **179** | **0 / 0 / 0** |

Los casos nuevos comprueban QR originales en canales Web/Ventanilla, estado
`En Espera`, hora real, actor autenticado, consultas sin escrituras, tokens vacíos,
manipulados, sustituidos, auténticos sin cita o con fecha incorrecta, citas pasadas,
futuras, canceladas y afectadas, bloqueo incluso ante un estado legado contradictorio,
check-in repetido, dos secretarios concurrentes, rollback y reintento, ausencia de
Bearer, Bearer inválido, roles no autorizados, headers falsificados, campos extra,
QR que no puede utilizarse como sesión y zona horaria de sesión PostgreSQL diferente.

Dos secretarios sobre el mismo QR produjeron un 200 y un 409, con una sola llegada
y un solo registro de transición. En la prueba adicional de bloqueo frente a
check-in, el bloqueo ganó: la cita quedó `Afectada/Pendiente Reprogramación`,
el check-in devolvió 409 y no registró llegada. El test admite también el orden
serial válido inverso, según la política de bloqueos confirmados de V5.

Las regresiones incluyen agendas, reservas concurrentes, personal, especialidades,
sesiones, registro público, OTP, Google y recuperación de acceso. No se realizó una
prueba externa con cuentas reales de Google ni entrega real por Gmail, ni una
ejecución contra PostgreSQL 15 o un binario nativo.

El clúster temporal se detuvo y retiró. V7 se aplicó solamente en bases aisladas;
la base de desarrollo no se modificó. El log de la ejecución final se conserva en
`target/fase6-final.log`, junto con los informes Surefire/Failsafe. Persisten avisos
anteriores sobre `quarkus.http.cors` no reconocido y una propiedad de generación
de esquema de Hibernate obsoleta; no se cambió esa configuración en esta fase.
