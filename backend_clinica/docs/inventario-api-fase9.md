> Documento histórico de la fase indicada. En Fase 13 se retiraron /hello, los generadores y el test de credenciales demo; sus resultados anteriores se conservan como evidencia. Para comandos vigentes consultar ../../docs/ejecucion-integracion.md y docs/fase13-auditoria.md desde la raíz.

# Inventario de integración API — Fase 9

Referencia: controladores y DTO del directorio de trabajo, 11 de noviembre de 2026. Prefijo de todas las rutas siguientes: `/api/v1`. Los códigos en `{}` son parámetros de ruta. `—` significa sin cuerpo. Las respuestas indicadas son JSON; `[]` significa un arreglo del DTO indicado, sin envoltorio `data`.

## Seguridad y errores comunes

`A` = Bearer válido ROL004; `S` = Bearer válido exclusivamente ROL002; `U` = sesión válida de cualquiera de los cuatro roles; `P` = público, sujeto a validaciones. Laravel conserva el Bearer en su sesión del servidor y lo transmite mediante `Authorization`; el QR no autentica usuarios. No enviar encabezados de actor.

Todas las operaciones protegidas pueden responder 401/403. Los errores administrativos/de recepción conservan `{ "ok": false, "error": "CODIGO", "mensaje": "Descripción" }`; autenticación conserva `{ "error": { "code": "CODIGO", "message": "Descripción" } }`. No unificar estos contratos desde Blade. 500 representa un fallo inesperado. Los estados adicionales de cada fila son los previstos por el controlador/servicio, no una afirmación de que cada variante se haya invocado por HTTP en Fase 9.

Consumidores: `actual` = AuthController/CheckRolSession de Laravel; `futuro` = sin pantalla funcional del módulo, accesible mediante QuarkusApiClient; `prueba` = desarrollo aislado, nunca interfaz de producción.

## Autenticación

Las respuestas exitosas de esta sección llevan `{ "data": ... }`. Las operaciones sensibles usan Cache-Control no-store. Los parámetros que se indican en las filas GET son query strings.

| Método y ruta | Permiso | Solicitud / parámetros | Respuesta `data` | HTTP | Consumidor |
|---|---|---|---|---|---|
| POST `/auth/login` | P | `{email,password}` | `{user,session:{accessToken,tokenType,expiresAt}}` | 200; 401,403,422,423 | actual |
| GET `/auth/me` | U | — | `{user,expiresAt}` | 200; 401,403,423 | actual |
| GET `/auth/authorize` | U | `roles=ROL002` o lista separada por comas | `{user,expiresAt}` | 200; 401,403,422,423 | actual |
| POST `/auth/logout` | U | — | `{revoked}` | 200; 401 | actual |
| POST `/auth/register` | P | `Registro` | `{patientId,roleCode,emailVerificationRequired,verification}` | 201; 409,422,503 | actual |
| POST `/auth/otp/request` | P | `{email,password}` | `Verificacion` | 202; 401,409,422,429,503 | actual |
| POST `/auth/otp/verify` | P | `{verificationToken,code}` | `{verified,nextAction}` | 200; 401,422,429 | actual |
| POST `/auth/otp/resend` | P | `{verificationToken}` | `Verificacion` | 202; 401,403,422,429,503 | actual |
| POST `/auth/otp/status` | P | `{verificationToken}` | `{verified,maskedDestination,verificationExpiresAt}` | 200; 401 | actual |
| POST `/auth/google/authorization-url` | P | `{state,nonce,codeChallenge,redirectUri}` | `{authorizationUrl}` | 200; 422,503 | actual, función configurable |
| POST `/auth/google/exchange` | P | `{code,codeVerifier,nonce,redirectUri}` | `{status,login,continuationToken,continuationExpiresAt,suggestedName,email,emailVerified,verification}` (campos según resultado) | 200; 401,403,409,422,503 | actual |
| POST `/auth/google/register` | P con continuación válida | `{continuationToken,ci,fechaNacimiento,telefono,ciudadResidencia}` | mismo tipo de resultado que `/google/exchange` | 201; 401,409,422,503 | actual |
| POST `/auth/google/link` | ROL001 | `{continuationToken}` | `{linked}` | 200; 401,403,409,422 | actual |
| POST `/auth/password/forgot` | P | `{email}` | `{message}` neutro | 202; 422,503 | actual |
| POST `/auth/password/reset` | P con token de un uso | `{email,token,password}` | `{reset,nextAction}` | 200; 422 | actual |
| POST `/auth/otp/demo-code` | solo DEMO local + `X-Demo-Otp-Key` | `{verificationToken}` | `{code,expiresAt}` | 200; 404 | prueba |
| POST `/auth/password/demo-link` | solo DEMO local + `X-Demo-Otp-Key` | `{email}` | `{resetUrl,expiresAt}` | 200; 404 | prueba |

`user = {id,name,email,role:{code,name,slug},accountStatus}`.

`Registro = {nombreCompleto,email,password,ci,fechaNacimiento,telefono,ciudadResidencia}`. Fechas ISO `yyyy-MM-dd`. No reutilizar nombres snake_case de formularios Laravel directamente: AuthController ya los traduce.

`Verificacion = {verificationToken,maskedDestination,otpExpiresAt,resendAvailableAt,verificationExpiresAt,deliveryMode,deliveryConfirmed}`. Los códigos/continuaciones se conservan en sesión y no deben entrar en listados o registros de aplicación.

## Personal y especialidades

Todas estas rutas requieren **A** y su consumidor es **futuro**. GET tiene éxito 200; POST 201; PUT 200 salvo contraseña 204. Errores de consulta: 400/404 además de autenticación; escrituras: 400/404/409 además de autenticación. No se crean rutas DELETE.

| Método y ruta | Query / cuerpo | JSON de respuesta |
|---|---|---|
| GET `/personal/medicos` | `estado=Activo|Inactivo`, opcional | `Medico[]` |
| GET `/personal/medicos/{codMed}` | — | `Medico` |
| POST `/personal/medicos` | `AltaMedico` | `Medico` |
| PUT `/personal/medicos/{codMed}` | `EdicionMedico` | `Medico` |
| PUT `/personal/medicos/{codMed}/estado` | `{estado:"Activo"}` o Inactivo | `Medico` |
| GET `/personal/secretarias` | `estado=Activo|Inactivo`, opcional | `Secretaria[]` |
| GET `/personal/secretarias/{codSec}` | — | `Secretaria` |
| POST `/personal/secretarias` | `{nombreCompleto,email,ci,telefono,password}` | `Secretaria` |
| PUT `/personal/secretarias/{codSec}` | `{nombreCompleto,email,ci,telefono}` | `Secretaria` |
| PUT `/personal/secretarias/{codSec}/estado` | `{estado:"Inactivo"}` o Activo | `Secretaria` |
| PUT `/personal/secretarias/{codSec}/password` | `{password}` | sin cuerpo, **204** |
| GET `/personal/especialidades` | sin filtro: activas; `estado=Activo|Inactivo|Todos` | sin filtro: `EspecialidadOpcion[]`; con filtro: `Especialidad[]` |
| GET `/personal/especialidades/{codEsp}` | — | `Especialidad` |
| POST `/personal/especialidades` | `{nombreEspecialidad,descripcion?,duracionConsultaMin?,estado?}` | `Especialidad` |
| PUT `/personal/especialidades/{codEsp}` | `{nombreEspecialidad,descripcion}` | `Especialidad` |
| PUT `/personal/especialidades/{codEsp}/estado` | `{estado}` | `Especialidad` |
| GET `/personal/especialidades/{codEsp}/medicos` | — | `{codMed,nombreCompleto,estadoMedico,estadoVinculo,duracionConsultaMin}[]` |

`AltaMedico = {nombreCompleto,email,password,ci,matriculaProfesional,resumenCurricular,duracionConsultaMin,codigosEspecialidades:[codEsp]}`. Edición mantiene esos campos; password y codigosEspecialidades admiten omisión según contrato existente.

`Medico = {codMed,codUsu,nombreCompleto,email,ci,matriculaProfesional,resumenCurricular,duracionConsultaMin,estado,especialidades:[{codEsp,nombreEspecialidad,duracionConsultaMin,estado}]}`.

`Secretaria = {codSec,codUsu,nombreCompleto,email,ci,telefono,estado}`. `Especialidad = {codEsp,nombreEspecialidad,descripcion,duracionConsultaMin,estado}`; `EspecialidadOpcion` solo incluye codEsp,nombreEspecialidad,duracionConsultaMin.

## Agendas y bloqueos

Todas las rutas requieren **A**, consumidor **futuro**. Estados comunes: 400/401/403/404/409/500. Consultas y PUT exitosos 200, POST 201. La disponibilidad consulta el motor PostgreSQL; no reserva ni regenera por un endpoint independiente.

| Método y ruta | Parámetros / cuerpo | JSON de respuesta |
|---|---|---|
| GET `/administracion/medicos/{codMed}/horarios` | — | `Horario[]` |
| POST `/administracion/medicos/{codMed}/horarios` | `HorarioEntrada` | `Horario` |
| PUT `/administracion/horarios/{codHor}` | `HorarioEntrada` | `Horario` |
| PUT `/administracion/horarios/{codHor}/estado` | `{estado}` | `Horario` |
| PUT `/administracion/medicos/{codMed}/duracion` | `{duracionConsultaMin:30}` | `{codMed,duracionConsultaMin}` |
| GET `/administracion/medicos/{codMed}/bloqueos` | `desde?,hasta?` | `Bloqueo[]` |
| POST `/administracion/medicos/{codMed}/bloqueos` | `{fechaBloqueo,horaInicio?,horaFin?,motivo}` | `Bloqueo` |
| GET `/administracion/bloqueos/{codBlo}` | — | `Bloqueo` |
| GET `/administracion/bloqueos/{codBlo}/citas-afectadas` | — | `{codBlo,trazabilidadCompleta,citas:[{codCit,codPac,codMed,codEsp,fecha,horaInicio,horaFin,estado}]}` |
| GET `/administracion/disponibilidad` | `codEsp?,codMed?,fecha?` | `Cupo[]` |

`HorarioEntrada = {diaSemana,horaInicio,horaFin,pausaInicio?,pausaFin?}`, ISO lunes=1/domingo=7. `Horario = {codHor,codMed,...HorarioEntrada,estado}`. Horas `HH:mm[:ss]`; pausas vienen ambas o ninguna.

`Bloqueo = {codBlo,codMed,codUsuAdmin,fechaBloqueo,horaInicio,horaFin,motivo,creadoEn,cantidadCitasAfectadas,trazabilidadCompleta}`. Sin horas representa jornada completa. No enviar codUsuAdmin en el cuerpo.

`Cupo = {codSlot,codMed,nombreMedico,codEsp,nombreEspecialidad,fecha,horaInicio,horaFin}`. Duraciones admitidas: 20,30,45,60. La edición incompatible con citas futuras da conflicto; no reprograma silenciosamente.

## Secretaría

Todas estas rutas requieren exclusivamente **S**, consumidor **futuro**. La Fase 9 las ejercita mediante el cliente PHP existente, **no** mediante pantallas nuevas. Consultas y transiciones 200; reserva 201. Errores posibles 400/401/403/404/409/500. No se admiten roles ni actores en el JSON.

| Método y ruta | Query / cuerpo | JSON de respuesta |
|---|---|---|
| GET `/secretaria/pacientes` | `q`: 3 caracteres alfanuméricos mínimo, longitud máxima 100; máximo 20 coincidencias | `{pacientes:[{codPac,nombreCompleto,ci,email,estadoCuenta}]}` |
| GET `/secretaria/pacientes/{codPac}` | — | `Paciente` |
| GET `/secretaria/especialidades` | — | `EspecialidadOpcion[]`, solo activas |
| GET `/secretaria/medicos` | `codEsp`, obligatorio | `{codMed,nombreCompleto,duracionConsultaMin}[]` |
| GET `/secretaria/disponibilidad` | `codEsp,codMed?,fecha,codPac?` | `Cupo[]`; fecha clínica D..D+14 |
| POST `/secretaria/citas` | **`{codPac,codSlot}`** | `Cita` |
| GET `/secretaria/citas/{codCit}` | — | `Cita` |
| GET `/secretaria/citas/{codCit}/acreditacion` | — | `{codCit,nombrePaciente,fecha,estado,qrValido,admiteCheckin}` |
| GET `/secretaria/citas/{codCit}/asistencia` | — | `Asistencia` |
| POST `/secretaria/checkin` | `{tokenQr}` exclusivamente en cuerpo | `Asistencia`, estado En Espera |
| GET `/secretaria/citas` | `fecha?` o `desde?,hasta?`; `estado?,codPac?,codMed?,page=0,size=20` | `PaginaCitas` |
| GET `/secretaria/pacientes/{codPac}/historial` | `desde?,hasta?,estado?,page=0,size=20` | `PaginaCitas` |
| POST `/secretaria/citas/{codCit}/cancelar` | `{motivo}` | `{codCit,estado,motivoCancelacion,canceladoPorUsu,canceladoEn,cancelada}` |
| POST `/secretaria/citas/{codCit}/inasistencia` | `{}` | `{codCit,estado,codInc,registradoPorUsu,fechaRegistro,motivo,inasistenciaRegistrada}` |

`Paciente = {codPac,nombreCompleto,ci,email,telefono,ciudadResidencia,estadoCuenta,estadoUsuario,emailVerificado,bloqueadoHasta,motivoInhabilitacion,habilitadoParaReservar}`.

`Cita = {codCit,codSlot,codPac,nombrePaciente,codMed,nombreMedico,codEsp,nombreEspecialidad,fecha,horaInicio,horaFin,estado,canalReserva,codSec,qrDisponible}`. El canal creado por secretaría es **Ventanilla**. Ni qrDisponible ni el código de cita son el token QR.

`Asistencia = {codCit,nombrePaciente,fecha,estado,horaLlegadaCheckin,checkinPorUsu,checkinRegistrado}`.

`PaginaCitas = {citas:[Historial],page,size,hayMas}`; no hay total global. `Historial = {codCit,codPac,nombrePaciente,codMed,nombreMedico,codEsp,nombreEspecialidad,fecha,horaInicio,horaFin,estado,canalReserva,motivoCancelacion,canceladoPorUsu,canceladoEn,horaLlegadaCheckin,checkinPorUsu,fechaAtencion,codIncInasistencia,inasistenciaRegistradaEn,inasistenciaPorUsu,motivoInasistencia}`. No incluye diagnósticos ni QR.

No existe POST de registro presencial habilitado: requiere política de identidad/contacto. No confundir esa ausencia con el registro público que sí existe.

## Incidencias y sanciones

Todas requieren **A**, consumidor **futuro**. Consultas 200; POST 201; errores 400/401/403/404/409/500. Paginación base cero, size por defecto 20 y máximo 50. No existe levantamiento anticipado: falta política aprobada; no inventar esa ruta desde Laravel.

| Método y ruta | Query / cuerpo | JSON de respuesta |
|---|---|---|
| GET `/administracion/pacientes` | `q?,estado?,page=0,size=20` | `{pacientes:[Paciente],page,size,hayMas}` |
| GET `/administracion/pacientes/{codPac}` | — | `Paciente` |
| GET `/administracion/pacientes/{codPac}/incidencias` | `tipo?,page=0,size=20` | `PaginaIncidencias` |
| GET `/administracion/pacientes/{codPac}/sanciones` | `vigente=true|false`, opcional; `page=0,size=20` | `PaginaIncidencias` |
| GET `/administracion/pacientes/{codPac}/sanciones/{codInc}` | — | `Incidencia` |
| POST `/administracion/pacientes/{codPac}/sanciones` | **`{motivo,dias}`**, entero 1..365 | `Incidencia` |

`PaginaIncidencias = {incidencias:[Incidencia],page,size,hayMas}`.

`Incidencia = {codInc,codPac,codCit,tipoIncidencia,motivo,registradoPorUsu,fechaRegistro,fechaFinSancion,vigente}`. Tipos: CANCELACION, INASISTENCIA_NO_SHOW, SANCION_BLOQUEO, OTRA. Estado administrativo de paciente: Activo, Inactivo, Bloqueado_Temporal. Los filtros booleanos deben llegar como las cadenas `true` o `false`, no `1`/`0`.

## Ejemplos ficticios para futuras pantallas

Reserva: `POST /api/v1/secretaria/citas` con `{ "codPac": "PAC000001", "codSlot": "SLT0000000001" }`. El identificador se toma de Cupo; no construir códigos por concatenación. Respuesta 201 de tipo Cita, con estado Confirmada y canalReserva Ventanilla.

Cancelación: `POST /api/v1/secretaria/citas/CIT00000001/cancelar` con `{ "motivo": "Solicitud del paciente" }`; respuesta 200 de tipo Cancelacion. El servidor añade actor y fecha.

Sanción: `POST /api/v1/administracion/pacientes/PAC000001/sanciones` con `{ "motivo": "Revisión administrativa de inasistencias", "dias": 2 }`; respuesta 201 de tipo Incidencia. No enviar fechaFin ni administrador: el contrato real usa días y sesión.

## Alcance del consumidor actual

Laravel tiene formularios de autenticación/registro/OTP/Google/recuperación y cuatro rutas de inicio protegidas por rol. Sus pantallas de inicio no implementan aún los módulos clínicos completos. PortalController solo consulta un catálogo público si QUARKUS_SPECIALTIES_ENDPOINT está configurado; **no existe un catálogo público equivalente entre estas rutas**. No apuntarlo a `/personal/especialidades` ni ampliar sus permisos para llenar el portal.

ReservaCitaController y otros controladores heredados están vacíos: no hay una API web de reserva del paciente que pueda atribuirse a una pantalla existente. La función SQL central sí admite canal Web y su concurrencia se prueba en backend. La entrega autorizada del QR al paciente sigue pendiente de interfaz/política específica.

Otros recursos técnicos: GET `/hello` fuera de `/api/v1`; OpenAPI `/q/openapi` en el entorno de desarrollo usado. No constituyen módulos clínicos ni sustituyen las pruebas del inventario.
