# Guía de migraciones Flyway — Sistema de Control de Citas Médicas

## Introducción

Flyway mantiene el esquema PostgreSQL como parte versionada del proyecto. Quarkus lo ejecuta al arrancar antes de usar las entidades y servicios. Laravel consume la API y no migra las tablas clínicas.

Las migraciones **V** se ordenan por versión y se aplican una sola vez por base; que el SQL sea idempotente no autoriza a repetirlo manualmente. Las **R** no tienen versión: Flyway las ejecuta después de las versionadas pendientes y las repite cuando cambia su checksum. **B** representa un arranque acumulativo para bases nuevas y no reemplaza ni borra las V históricas.

La tabla `public.flyway_schema_history` registra orden, versión, descripción, tipo, nombre del script, checksum, instalador, fecha, duración y éxito. El checksum detecta cambios en el contenido. Editar o retirar una V aplicada puede impedir validate y el arranque. La ruta física no es el checksum, pero el archivo original debe seguir siendo resoluble. No se ha alterado ninguna V ni R histórica en Fase 13.

## Inventario completo y explicación técnica

Ubicación normal: `backend_clinica/src/main/resources/db/migration/`. Ubicación local adicional: `backend_clinica/db/dev-migration/`. No se hallaron otras migraciones clínicas Flyway. Las tres migraciones PHP de Laravel son infraestructura de users/cache/jobs y no migran PostgreSQL clínico; permanecen por compatibilidad y no se ejecutan desde los scripts Composer de arranque. Las fixtures Java y los SQL integrity de src/test son herramientas opt-in, no migraciones.

### B9__instalacion_sin_datos_demo.sql

- **Versión y tipo:** 9, B acumulativa.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/B9__instalacion_sin_datos_demo.sql`.
- **Objetivo:** Arranque acumulativo sin datos demo para instalaciones nuevas.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`, `analisis`, `verificaciones_otp`, `tokens_recuperacion`, `configuracion_seguridad`, `notificaciones`, `bitacora`, `usuarios_identidades_externas`, `bloqueos_citas_afectadas`
- **Tablas creadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`, `analisis`, `verificaciones_otp`, `tokens_recuperacion`, `configuracion_seguridad`, `notificaciones`, `bitacora`, `usuarios_identidades_externas`, `bloqueos_citas_afectadas`
- **Columnas creadas:** roles: `cod_rol`, `nombre_rol`, `descripcion`, `estado`; usuarios: `secuencial`, `cod_usu`, `nombre_completo`, `email`, `password_hash`, `cod_rol`, `estado`, `creado_en`, `actualizado_en`; pacientes: `secuencial`, `cod_pac`, `cod_usu`, `ci`, `fecha_nacimiento`, `telefono`, `telefono_verificado`, `ciudad_residencia`, `estado_cuenta`, `bloqueado_hasta`, `creado_en`; secretarias: `secuencial`, `cod_sec`, `cod_usu`, `ci`, `telefono`, `estado`, `creado_en`; medicos: `secuencial`, `cod_med`, `cod_usu`, `ci`, `matricula_profesional`, `resumen_curricular`, `duracion_consulta_min`, `estado`, `creado_en`; especialidades: `secuencial`, `cod_esp`, `nombre_especialidad`, `descripcion`, `duracion_consulta_min`, `estado`, `creado_en`; medico_especialidades: `cod_med`, `cod_esp`, `duracion_consulta_min`, `estado`; horarios: `secuencial`, `cod_hor`, `cod_med`, `dia_semana`, `hora_inicio`, `hora_fin`, `pausa_inicio`, `pausa_fin`, `estado`, `creado_en`; bloqueos_agenda: `secuencial`, `cod_blo`, `cod_med`, `cod_usu_admin`, `fecha_bloqueo`, `hora_inicio`, `hora_fin`, `motivo`, `creado_en`; slots_agenda: `secuencial`, `cod_slot`, `cod_hor`, `cod_med`, `cod_esp`, `inicio_at`, `fin_at`, `fecha`, `hora_inicio`, `hora_fin`, `creado_en`; citas: `secuencial`, `cod_cit`, `cod_pac`, `cod_slot`, `cod_med`, `cod_esp`, `fecha`, `hora_inicio`, `hora_fin`, `estado`, `canal_reserva`, `cod_sec`, `creado_por_usu`, `cancelado_por_usu`, `motivo_cancelacion`, `motivo_reprogramacion`, `cancelado_en`, `token_qr`, `hora_llegada_checkin`, `checkin_por_usu`, `fecha_atencion`, `atendida_por_usu`, `cod_cit_padre`, `creado_en`, `actualizado_en`; derivaciones: `secuencial`, `cod_der`, `cod_cit_origen`, `cod_cit_hija`, `cod_med_emisor`, `cod_esp_destino`, `cod_med_destino`, `motivo_derivacion`, `estado`, `fecha_derivacion`; incidencias_sanciones: `secuencial`, `cod_inc`, `cod_pac`, `cod_cit`, `registrado_por_usu`, `tipo_incidencia`, `motivo`, `fecha_fin_sancion`, `fecha_registro`; analisis: `secuencial`, `cod_ana`, `cod_cit`, `solicitado_por_usu`, `tipo_analisis`, `resultado`, `archivo_pdf_url`, `estado`, `fecha_registro`, `fecha_entrega_estimada`, `fecha_entrega_real`, `creado_en`; verificaciones_otp: `secuencial`, `cod_otp`, `cod_usu`, `telefono_destino`, `codigo_hash`, `tipo_token`, `intentos`, `max_intentos`, `expira_en`, `consumido_en`, `creado_en`; tokens_recuperacion: `secuencial`, `cod_rec`, `cod_usu`, `token_hash`, `expira_en`, `usado_en`, `creado_en`; configuracion_seguridad: `nombre`, `secreto`, `creado_en`; notificaciones: `cod_not`, `cod_usu`, `cod_cit`, `canal`, `tipo_alerta`, `destinatario`, `mensaje`, `fecha_programada`, `estado`, `intentos`, `max_intentos`, `enviado_en`, `en_proceso_desde`, `ultimo_error`, `creado_en`; bitacora: `secuencial`, `cod_bit`, `cod_usu`, `nombre_tabla`, `accion`, `clave_registro`, `detalles`, `datos_anteriores`, `datos_nuevos`, `registrado_en`; usuarios_identidades_externas: `secuencial`, `cod_identidad`, `cod_usu`, `proveedor`, `identificador_externo`, `email_proveedor`, `email_verificado`, `vinculado_en`, `actualizado_en`; bloqueos_citas_afectadas: `cod_blo`, `cod_cit`
- **Alteraciones de columnas/restricciones:** `citas: ADD CONSTRAINT ex_citas_medico_sin_solape EXCLUDE USING gist (cod_med WITH =, tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&) WHERE (estado NOT IN ('Cancelada','Reprogramada'))`; `citas: ADD CONSTRAINT ex_citas_paciente_sin_solape EXCLUDE USING gist (cod_pac WITH =, tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&) WHERE (estado NOT IN ('Cancelada','Reprogramada'))`; `usuarios: ALTER COLUMN password_hash DROP NOT NULL`; `usuarios: ADD CONSTRAINT ck_usuarios_password_hash_seguro CHECK (password_hash IS NULL OR length(trim(password_hash)) >= 20)`; `slots_agenda: DROP CONSTRAINT slots_agenda_cod_hor_cod_esp_inicio_at_key, DROP CONSTRAINT slots_agenda_cod_med_cod_esp_inicio_at_key, ADD CONSTRAINT uq_slots_agenda_version UNIQUE (cod_hor, cod_esp, inicio_at, fin_at)`; `bloqueos_agenda: ADD COLUMN trazabilidad_completa boolean NOT NULL DEFAULT false`
- **Funciones SQL:** `fecha_clinica`, `ahora_clinica`, `actor_actual`, `auditar_fila`, `impedir_edicion_historial`, `validar_horario_semanal`, `registrar_paciente`, `crear_otp`, `validar_otp`, `crear_token_recuperacion`, `restablecer_contrasena`, `generar_slots_agenda`, `regenerar_slots_automaticamente`, `validar_cita`, `crear_token_qr`, `procesar_evento_cita`, `procesar_bloqueo_agenda`, `reservar_cita`, `cancelar_cita`, `reprogramar_cita`, `registrar_checkin`, `marcar_cita_atendida`, `crear_derivacion`, `registrar_sancion`, `aplicar_sancion_paciente`, `desbloquear_sanciones_vencidas`, `marcar_inasistencias_vencidas`, `tomar_notificaciones`, `finalizar_notificacion`, `validar_analisis`, `impedir_borrado_citas`, `bloquear_agenda_medico`, `validar_duracion_agenda`, `serializar_bloqueo_agenda`, `serializar_reserva_agenda`, `verificar_token_qr`, `cita_admite_checkin`, `procesar_inasistencia_vencida`, `registrar_inasistencia`, `estado_reserva_paciente`
- **Triggers creados:** `trg_validar_horario_semanal`, `trg_slots_despues_horario`, `trg_slots_despues_medico_especialidad`, `trg_slots_despues_especialidad`, `trg_slots_despues_medico`, `trg_validar_cita`, `trg_procesar_evento_cita`, `trg_auditar_citas`, `trg_procesar_bloqueo_agenda`, `trg_aplicar_sancion_paciente`, `trg_validar_analisis`, `trg_auditar_usuarios`, `trg_auditar_pacientes`, `trg_auditar_secretarias`, `trg_auditar_medicos`, `trg_auditar_especialidades`, `trg_auditar_medico_especialidades`, `trg_auditar_horarios`, `trg_auditar_bloqueos`, `trg_auditar_derivaciones`, `trg_auditar_analisis`, `trg_auditar_incidencias`, `trg_incidencias_inmutables`, `trg_bitacora_inmutable`, `trg_citas_no_delete`, `trg_bloqueos_citas_inmutables`, `trg_auditar_bloqueos_citas`, `trg_validar_duracion_medico`, `trg_validar_duracion_vinculo`, `trg_validar_duracion_especialidad`, `trg_serializar_bloqueo`, `trg_00_serializar_reserva`
- **Índices explícitos:** `uq_usuarios_email_ci`, `ix_usuarios_rol_estado`, `uq_pacientes_ci_normalizado`, `ix_pacientes_bloqueo`, `uq_secretarias_ci_normalizado`, `uq_medicos_ci_normalizado`, `ix_medico_especialidades_esp`, `ix_horarios_medico_dia`, `ix_bloqueos_med_fecha`, `ix_slots_fecha_esp_med`, `ix_slots_med_inicio`, `uq_citas_slot_activa`, `ix_citas_paciente_fecha`, `ix_citas_medico_fecha`, `ix_citas_estado_fecha`, `ix_derivaciones_origen`, `ix_incidencias_paciente_fecha`, `ix_analisis_cita_estado`, `ix_otp_usuario_fecha`, `ix_recuperacion_usuario_fecha`, `ix_notificaciones_pendientes`, `ix_notificaciones_usuario_fecha`, `ix_bitacora_tabla_fecha`, `ix_bitacora_usuario_fecha`, `ix_identidad_usuario`, `ix_incidencias_cita_tipo`, `ix_incidencias_sancion_paciente_fin`
- **Restricciones con nombre:** `ex_citas_medico_sin_solape`, `ex_citas_paciente_sin_solape`, `ck_usuarios_password_hash_seguro`, `ck_identidad_proveedor`, `uq_identidad_externa`, `uq_usuario_proveedor`, `slots_agenda_cod_hor_cod_esp_inicio_at_key`, `slots_agenda_cod_med_cod_esp_inicio_at_key`, `uq_slots_agenda_version` Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** PostgreSQL 15, pgcrypto y btree_gist; R se aplica después. Reproduce V1 y V4–V9.
- **Servicios Quarkus relacionados:** Todos los servicios clínicos actuales, igual que V1 y V4–V9.
- **Datos demo:** No: solo los cuatro roles y configuración funcional de V1.
- **Carga:** classpath de dev, test y entorno habitual; se ejecuta solo con un historial nuevo.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** Contiene la concatenación literal del SQL funcional V1, V4, V5, V6, V7, V8 y V9, identificada por comentarios INICIO/FIN FUENTE. No incluye V2 ni V3 ni la repetible. Flyway lo usa exclusivamente al crear un historial nuevo: aplica B9, ignora V1–V9 y después aplica R. Con un historial ya iniciado lo ignora y continúa las V originales. Es una baseline migration SQL_BASELINE, no el comando baseline ni baseline-on-migrate. Se añade al classpath normal y no depende de licencia Teams. La prueba compara columnas, funciones PL/pgSQL, triggers, índices, restricciones y vistas con la cadena histórica. Debe conservarse inmutable tras su aplicación; las futuras modificaciones van en V10 o posterior.

### R__verificacion_email_otp.sql

- **Versión y tipo:** sin versión, R repetible.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/R__verificacion_email_otp.sql`.
- **Objetivo:** Incorpora verificación de correo a pacientes y a OTP.
- **Tablas afectadas o consultadas:** `usuarios`, `pacientes`, `verificaciones_otp`
- **Tablas creadas:** Ninguno.
- **Columnas creadas:** No crea tablas.
- **Alteraciones de columnas/restricciones:** `pacientes: ADD COLUMN IF NOT EXISTS email_verificado boolean NOT NULL DEFAULT true`; `pacientes: ALTER COLUMN email_verificado SET DEFAULT false`; `pacientes: ADD COLUMN IF NOT EXISTS email_verificado_en timestamptz`; `verificaciones_otp: ADD COLUMN IF NOT EXISTS email_destino varchar(254)`; `verificaciones_otp: ALTER COLUMN telefono_destino DROP NOT NULL`; `verificaciones_otp: DROP CONSTRAINT IF EXISTS verificaciones_otp_tipo_token_check`; `verificaciones_otp: ADD CONSTRAINT verificaciones_otp_tipo_token_check CHECK (tipo_token IN ('VERIFICACION_TELEFONO', 'VERIFICACION_EMAIL'))`; `verificaciones_otp: DROP CONSTRAINT IF EXISTS ck_verificaciones_otp_destino`; `verificaciones_otp: ADD CONSTRAINT ck_verificaciones_otp_destino CHECK ( (tipo_token = 'VERIFICACION_TELEFONO' AND telefono_destino IS NOT NULL AND email_destino IS NULL) OR (tipo_token = 'VERIFICACION_EMAIL' AND email_destino IS NOT NULL AND telefono_destino IS NULL) )`
- **Funciones SQL:** `crear_otp_email`, `validar_otp_email`
- **Triggers creados:** Ninguno.
- **Índices explícitos:** `ix_otp_usuario_tipo_fecha`
- **Restricciones con nombre:** `IF`, `verificaciones_otp_tipo_token_check`, `ck_verificaciones_otp_destino` Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V1; en bases nuevas B9; se ejecuta después de las V/B pendientes.
- **Servicios Quarkus relacionados:** PatientRegistrationGateway, PatientRegistrationService, AuthAccountGateway y ConfiguredOtpDeliveryProvider.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** Añade email_verificado/email_verificado_en y email_destino; permite destino telefónico nulo solo para OTP de correo. Al incorporar la columna considera verificados a los pacientes anteriores y luego establece false para altas nuevas. crear_otp_email limita emisiones, vence códigos y guarda SHA-256; validar_otp_email bloquea la fila, cuenta intentos, consume el código e impide reutilización. La migración repetible conserva su checksum histórico en esta fase para no disparar cambios de datos. No envía correos: la entrega corresponde al proveedor configurado.

### V1__control_citas_postgresql_completo.sql

- **Versión y tipo:** 1, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V1__control_citas_postgresql_completo.sql`.
- **Objetivo:** Crea el motor clínico completo y los cuatro roles funcionales, sin insertar usuarios.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`, `analisis`, `verificaciones_otp`, `tokens_recuperacion`, `configuracion_seguridad`, `notificaciones`, `bitacora`
- **Tablas creadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`, `analisis`, `verificaciones_otp`, `tokens_recuperacion`, `configuracion_seguridad`, `notificaciones`, `bitacora`
- **Columnas creadas:** roles: `cod_rol`, `nombre_rol`, `descripcion`, `estado`; usuarios: `secuencial`, `cod_usu`, `nombre_completo`, `email`, `password_hash`, `cod_rol`, `estado`, `creado_en`, `actualizado_en`; pacientes: `secuencial`, `cod_pac`, `cod_usu`, `ci`, `fecha_nacimiento`, `telefono`, `telefono_verificado`, `ciudad_residencia`, `estado_cuenta`, `bloqueado_hasta`, `creado_en`; secretarias: `secuencial`, `cod_sec`, `cod_usu`, `ci`, `telefono`, `estado`, `creado_en`; medicos: `secuencial`, `cod_med`, `cod_usu`, `ci`, `matricula_profesional`, `resumen_curricular`, `duracion_consulta_min`, `estado`, `creado_en`; especialidades: `secuencial`, `cod_esp`, `nombre_especialidad`, `descripcion`, `duracion_consulta_min`, `estado`, `creado_en`; medico_especialidades: `cod_med`, `cod_esp`, `duracion_consulta_min`, `estado`; horarios: `secuencial`, `cod_hor`, `cod_med`, `dia_semana`, `hora_inicio`, `hora_fin`, `pausa_inicio`, `pausa_fin`, `estado`, `creado_en`; bloqueos_agenda: `secuencial`, `cod_blo`, `cod_med`, `cod_usu_admin`, `fecha_bloqueo`, `hora_inicio`, `hora_fin`, `motivo`, `creado_en`; slots_agenda: `secuencial`, `cod_slot`, `cod_hor`, `cod_med`, `cod_esp`, `inicio_at`, `fin_at`, `fecha`, `hora_inicio`, `hora_fin`, `creado_en`; citas: `secuencial`, `cod_cit`, `cod_pac`, `cod_slot`, `cod_med`, `cod_esp`, `fecha`, `hora_inicio`, `hora_fin`, `estado`, `canal_reserva`, `cod_sec`, `creado_por_usu`, `cancelado_por_usu`, `motivo_cancelacion`, `motivo_reprogramacion`, `cancelado_en`, `token_qr`, `hora_llegada_checkin`, `checkin_por_usu`, `fecha_atencion`, `atendida_por_usu`, `cod_cit_padre`, `creado_en`, `actualizado_en`; derivaciones: `secuencial`, `cod_der`, `cod_cit_origen`, `cod_cit_hija`, `cod_med_emisor`, `cod_esp_destino`, `cod_med_destino`, `motivo_derivacion`, `estado`, `fecha_derivacion`; incidencias_sanciones: `secuencial`, `cod_inc`, `cod_pac`, `cod_cit`, `registrado_por_usu`, `tipo_incidencia`, `motivo`, `fecha_fin_sancion`, `fecha_registro`; analisis: `secuencial`, `cod_ana`, `cod_cit`, `solicitado_por_usu`, `tipo_analisis`, `resultado`, `archivo_pdf_url`, `estado`, `fecha_registro`, `fecha_entrega_estimada`, `fecha_entrega_real`, `creado_en`; verificaciones_otp: `secuencial`, `cod_otp`, `cod_usu`, `telefono_destino`, `codigo_hash`, `tipo_token`, `intentos`, `max_intentos`, `expira_en`, `consumido_en`, `creado_en`; tokens_recuperacion: `secuencial`, `cod_rec`, `cod_usu`, `token_hash`, `expira_en`, `usado_en`, `creado_en`; configuracion_seguridad: `nombre`, `secreto`, `creado_en`; notificaciones: `cod_not`, `cod_usu`, `cod_cit`, `canal`, `tipo_alerta`, `destinatario`, `mensaje`, `fecha_programada`, `estado`, `intentos`, `max_intentos`, `enviado_en`, `en_proceso_desde`, `ultimo_error`, `creado_en`; bitacora: `secuencial`, `cod_bit`, `cod_usu`, `nombre_tabla`, `accion`, `clave_registro`, `detalles`, `datos_anteriores`, `datos_nuevos`, `registrado_en`
- **Alteraciones de columnas/restricciones:** `citas: ADD CONSTRAINT ex_citas_medico_sin_solape EXCLUDE USING gist (cod_med WITH =, tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&) WHERE (estado NOT IN ('Cancelada','Reprogramada'))`; `citas: ADD CONSTRAINT ex_citas_paciente_sin_solape EXCLUDE USING gist (cod_pac WITH =, tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&) WHERE (estado NOT IN ('Cancelada','Reprogramada'))`
- **Funciones SQL:** `fecha_clinica`, `ahora_clinica`, `actor_actual`, `auditar_fila`, `impedir_edicion_historial`, `validar_horario_semanal`, `registrar_paciente`, `crear_otp`, `validar_otp`, `crear_token_recuperacion`, `restablecer_contrasena`, `generar_slots_agenda`, `regenerar_slots_automaticamente`, `validar_cita`, `crear_token_qr`, `procesar_evento_cita`, `procesar_bloqueo_agenda`, `reservar_cita`, `cancelar_cita`, `reprogramar_cita`, `registrar_checkin`, `marcar_cita_atendida`, `crear_derivacion`, `registrar_sancion`, `aplicar_sancion_paciente`, `desbloquear_sanciones_vencidas`, `marcar_inasistencias_vencidas`, `tomar_notificaciones`, `finalizar_notificacion`, `validar_analisis`, `impedir_borrado_citas`
- **Triggers creados:** `trg_validar_horario_semanal`, `trg_slots_despues_horario`, `trg_slots_despues_medico_especialidad`, `trg_slots_despues_especialidad`, `trg_slots_despues_medico`, `trg_validar_cita`, `trg_procesar_evento_cita`, `trg_auditar_citas`, `trg_procesar_bloqueo_agenda`, `trg_aplicar_sancion_paciente`, `trg_validar_analisis`, `trg_auditar_usuarios`, `trg_auditar_pacientes`, `trg_auditar_secretarias`, `trg_auditar_medicos`, `trg_auditar_especialidades`, `trg_auditar_medico_especialidades`, `trg_auditar_horarios`, `trg_auditar_bloqueos`, `trg_auditar_derivaciones`, `trg_auditar_analisis`, `trg_auditar_incidencias`, `trg_incidencias_inmutables`, `trg_bitacora_inmutable`, `trg_citas_no_delete`
- **Índices explícitos:** `uq_usuarios_email_ci`, `ix_usuarios_rol_estado`, `uq_pacientes_ci_normalizado`, `ix_pacientes_bloqueo`, `uq_secretarias_ci_normalizado`, `uq_medicos_ci_normalizado`, `ix_medico_especialidades_esp`, `ix_horarios_medico_dia`, `ix_bloqueos_med_fecha`, `ix_slots_fecha_esp_med`, `ix_slots_med_inicio`, `uq_citas_slot_activa`, `ix_citas_paciente_fecha`, `ix_citas_medico_fecha`, `ix_citas_estado_fecha`, `ix_derivaciones_origen`, `ix_incidencias_paciente_fecha`, `ix_analisis_cita_estado`, `ix_otp_usuario_fecha`, `ix_recuperacion_usuario_fecha`, `ix_notificaciones_pendientes`, `ix_notificaciones_usuario_fecha`, `ix_bitacora_tabla_fecha`, `ix_bitacora_usuario_fecha`
- **Restricciones con nombre:** `ex_citas_medico_sin_solape`, `ex_citas_paciente_sin_solape` Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** PostgreSQL 15; extensiones pgcrypto y btree_gist; usuario propietario con permisos de extensión.
- **Servicios Quarkus relacionados:** AuthService, PatientRegistrationService, PersonalService, EspecialidadService, AgendaHorarioService, ReservaCitaService, GestionCitaService, AtencionCheckinService, RecepcionService, SancionService y ClinicaDaemonScheduler.
- **Datos demo:** No. Los cuatro roles y la configuración criptográfica son datos funcionales.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** Las claves generadas USU/PAC/SEC/MED y las FK con ON DELETE RESTRICT mantienen la identidad y las referencias. Los perfiles tienen relación uno a uno con usuarios; médico–especialidad es muchos a muchos. Slots materializa intervalos de horarios, mientras la vista calcula su disponibilidad. Las exclusiones GiST evitan solapamientos por paciente y médico; los triggers verifican roles, fechas, estados y auditoría. Las funciones SECURITY DEFINER fijan search_path. La clave HMAC para QR se genera en configuracion_seguridad mediante aleatoriedad de PostgreSQL; no es una cuenta ni una credencial demo. OTP y recuperación almacenan hashes. Las notificaciones son una cola real; crear una fila no significa entregar correo. Las dos vistas son v_agenda_disponibilidad y v_historial_citas. No ejecutar V1 manualmente: su encabezado histórico advierte de reconstrucción, aunque el archivo actual usa CREATE TABLE sin DROP TABLE. Se preserva literalmente.

### V2__seed_usuarios_prueba.sql

- **Versión y tipo:** 2, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V2__seed_usuarios_prueba.sql`.
- **Objetivo:** Carga histórica de cuatro cuentas ficticias, sus perfiles, tres especialidades, un médico vinculado, horarios de lunes a viernes y generación de slots.
- **Tablas afectadas o consultadas:** `usuarios`, `pacientes`, `secretarias`, `medicos`, `especialidades`, `medico_especialidades`, `horarios`
- **Tablas creadas:** Ninguno.
- **Columnas creadas:** No crea tablas.
- **Alteraciones de columnas/restricciones:** Ninguna mediante ALTER TABLE.
- **Funciones SQL:** Ninguno.
- **Triggers creados:** Ninguno.
- **Índices explícitos:** Ninguno.
- **Restricciones con nombre:** Ninguno. Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V1. Las bases existentes siguen conservando este archivo para validación.
- **Servicios Quarkus relacionados:** No tiene consumidor necesario en ejecución: los servicios consultan tablas, no estas identidades. Sus antiguos scripts y el test de login demo fueron retirados; los tests vivos generan cuentas aleatorias.
- **Datos demo:** Sí: cuentas, perfiles, especialidades, vínculos, horarios y slots.
- **Carga:** classpath de dev, test y entorno habitual; solo se ejecuta en la cadena histórica, no en bases nuevas que usan B9.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** Usa INSERT/SELECT y ON CONFLICT/NOT EXISTS para evitar duplicados. Contiene un BCrypt común y una contraseña de demostración en el SQL histórico: no se reproducen aquí. El nombre “desarrollo” del encabezado no restringía su ejecución: la ubicación classpath lo hacía global. Se conserva byte por byte para validar instalaciones antiguas. B9 evita su ejecución en bases vacías. Una base histórica que solo llegó a V1 todavía necesita V2 para seguir esa cadena; no se ha falseado su historial ni se han borrado usuarios. No utilizar esas identidades como cuentas autorizadas.

### V4__identidades_google.sql

- **Versión y tipo:** 4, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V4__identidades_google.sql`.
- **Objetivo:** Permite identidades externas de Google y cuentas sin contraseña local.
- **Tablas afectadas o consultadas:** `usuarios`, `usuarios_identidades_externas`
- **Tablas creadas:** `usuarios_identidades_externas`
- **Columnas creadas:** usuarios_identidades_externas: `secuencial`, `cod_identidad`, `cod_usu`, `proveedor`, `identificador_externo`, `email_proveedor`, `email_verificado`, `vinculado_en`, `actualizado_en`
- **Alteraciones de columnas/restricciones:** `usuarios: ALTER COLUMN password_hash DROP NOT NULL`; `usuarios: ADD CONSTRAINT ck_usuarios_password_hash_seguro CHECK (password_hash IS NULL OR length(trim(password_hash)) >= 20)`
- **Funciones SQL:** Ninguno.
- **Triggers creados:** Ninguno.
- **Índices explícitos:** `ix_identidad_usuario`
- **Restricciones con nombre:** `ck_usuarios_password_hash_seguro`, `ck_identidad_proveedor`, `uq_identidad_externa`, `uq_usuario_proveedor` Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** usuarios de V1; Google se habilita solo mediante configuración externa.
- **Servicios Quarkus relacionados:** GoogleAccountGateway, GoogleAuthService, GoogleOidcProvider, AuthAccountGateway, AuthService y PasswordRecoveryService.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** password_hash pasa a admitir NULL; la comprobación de longitud sigue rechazando valores locales triviales. usuarios_identidades_externas distingue proveedor y subject, con unicidad por identidad y por usuario/proveedor. No guarda tokens OAuth ni vincula automáticamente por correo. Los permisos de tabla y secuencia se limitan al usuario actual.

### V5__administracion_agendas_consistentes.sql

- **Versión y tipo:** 5, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V5__administracion_agendas_consistentes.sql`.
- **Objetivo:** Hace consistentes los cambios de agenda y la concurrencia entre reservas y bloqueos.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `especialidades`, `medico_especialidades`, `horarios`, `bloqueos_agenda`, `slots_agenda`, `citas`, `derivaciones`, `bloqueos_citas_afectadas`
- **Tablas creadas:** `bloqueos_citas_afectadas`
- **Columnas creadas:** bloqueos_citas_afectadas: `cod_blo`, `cod_cit`
- **Alteraciones de columnas/restricciones:** `slots_agenda: DROP CONSTRAINT slots_agenda_cod_hor_cod_esp_inicio_at_key, DROP CONSTRAINT slots_agenda_cod_med_cod_esp_inicio_at_key, ADD CONSTRAINT uq_slots_agenda_version UNIQUE (cod_hor, cod_esp, inicio_at, fin_at)`; `bloqueos_agenda: ADD COLUMN trazabilidad_completa boolean NOT NULL DEFAULT false`
- **Funciones SQL:** `bloquear_agenda_medico`, `validar_horario_semanal`, `validar_duracion_agenda`, `serializar_bloqueo_agenda`, `procesar_bloqueo_agenda`, `serializar_reserva_agenda`, `reservar_cita`
- **Triggers creados:** `trg_bloqueos_citas_inmutables`, `trg_auditar_bloqueos_citas`, `trg_validar_duracion_medico`, `trg_validar_duracion_vinculo`, `trg_validar_duracion_especialidad`, `trg_serializar_bloqueo`, `trg_00_serializar_reserva`
- **Índices explícitos:** Ninguno.
- **Restricciones con nombre:** `slots_agenda_cod_hor_cod_esp_inicio_at_key`, `slots_agenda_cod_med_cod_esp_inicio_at_key`, `uq_slots_agenda_version` Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V1; catálogos y relaciones médico–especialidad existentes.
- **Servicios Quarkus relacionados:** AgendaHorarioService, AgendaRepository, ReservaCitaService, CitaFunctionRepository y AtencionCheckinService.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** La unicidad de slots incorpora fin_at para conservar versiones referenciadas al cambiar duración. bloqueos_agenda añade trazabilidad_completa; bloqueos_citas_afectadas registra la relación causal inmutable y auditable. Bloqueos por médico serializan reservas, horarios, duraciones y excepciones. Las restricciones impiden horarios solapados y duraciones incompatibles. Sustituye reservar_cita y la vista de disponibilidad sin borrar citas previas.

### V6__reservas_recepcion_errores_y_habilitacion.sql

- **Versión y tipo:** 6, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V6__reservas_recepcion_errores_y_habilitacion.sql`.
- **Objetivo:** Refuerza reservar_cita con habilitación real del paciente y errores SQL tipados.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `slots_agenda`, `citas`, `derivaciones`
- **Tablas creadas:** Ninguno.
- **Columnas creadas:** No crea tablas.
- **Alteraciones de columnas/restricciones:** Ninguna mediante ALTER TABLE.
- **Funciones SQL:** `reservar_cita`
- **Triggers creados:** Ninguno.
- **Índices explícitos:** Ninguno.
- **Restricciones con nombre:** Ninguno. Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V5 y R__verificacion_email_otp al utilizar la función.
- **Servicios Quarkus relacionados:** ReservaCitaService, RecepcionService, SecretariaService, TriggerExceptionMapper y CitaFunctionRepository.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** Mantiene los bloqueos de V5 y valida actor, canal, perfil activo de secretaría, identidad del paciente, ventana futura D+14, verificación de correo, sanciones y derivación. Convierte conflictos concurrentes en códigos SQLSTATE que la API traduce a HTTP. No añade tablas ni datos. email_verificado procede de R; el cuerpo PL/pgSQL se usa después de terminar las migraciones.

### V7__checkin_qr_validacion_y_concurrencia.sql

- **Versión y tipo:** 7, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V7__checkin_qr_validacion_y_concurrencia.sql`.
- **Objetivo:** Valida QR firmado y registra check-in con control de concurrencia.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `secretarias`, `bloqueos_agenda`, `citas`, `configuracion_seguridad`
- **Tablas creadas:** Ninguno.
- **Columnas creadas:** No crea tablas.
- **Alteraciones de columnas/restricciones:** Ninguna mediante ALTER TABLE.
- **Funciones SQL:** `verificar_token_qr`, `cita_admite_checkin`, `registrar_checkin`
- **Triggers creados:** Ninguno.
- **Índices explícitos:** Ninguno.
- **Restricciones con nombre:** Ninguno. Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V1 (secreto aleatorio y formato QR), V5 (bloqueos), V6 (reserva).
- **Servicios Quarkus relacionados:** AtencionCheckinService, SecretariaService, CitaRepository y CitaFunctionRepository.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** verificar_token_qr comprueba formato, firma HMAC y correspondencia con la fecha; cita_admite_checkin consulta elegibilidad sin efectos laterales. registrar_checkin reevalúa reglas bajo bloqueos, valida el perfil activo y evita reutilización. Conserva tokens QR originales y no incorpora un generador ficticio. Nunca copiar tokens a documentación o logs.

### V8__cancelaciones_inasistencias_recepcion.sql

- **Versión y tipo:** 8, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V8__cancelaciones_inasistencias_recepcion.sql`.
- **Objetivo:** Registra cancelaciones e inasistencias con trazabilidad y exclusión mutua.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `citas`, `incidencias_sanciones`
- **Tablas creadas:** Ninguno.
- **Columnas creadas:** No crea tablas.
- **Alteraciones de columnas/restricciones:** Ninguna mediante ALTER TABLE.
- **Funciones SQL:** `cancelar_cita`, `procesar_inasistencia_vencida`, `registrar_inasistencia`, `marcar_inasistencias_vencidas`
- **Triggers creados:** Ninguno.
- **Índices explícitos:** `ix_incidencias_cita_tipo`
- **Restricciones con nombre:** Ninguno. Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V5–V7; citas, incidencias_sanciones, perfiles y bitácora de V1.
- **Servicios Quarkus relacionados:** GestionCitaService, SecretariaService, CitaFunctionRepository y ClinicaDaemonScheduler.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** cancelar_cita conserva motivo y actor, registra incidencia una sola vez y rechaza repetición con P4092. procesar_inasistencia_vencida es el núcleo interno de registrar_inasistencia y del proceso periódico. Se revoca su ejecución pública. El índice por cita/tipo acelera consultas sin deduplicar ni borrar incidencias históricas. La concurrencia con QR y reservas usa los bloqueos existentes.

### V9__sanciones_temporales_consistentes.sql

- **Versión y tipo:** 9, V versionada.
- **Ruta:** `backend_clinica/src/main/resources/db/migration/V9__sanciones_temporales_consistentes.sql`.
- **Objetivo:** Unifica vigencia de sanciones y preserva antecedentes históricos.
- **Tablas afectadas o consultadas:** `roles`, `usuarios`, `pacientes`, `secretarias`, `medicos`, `slots_agenda`, `citas`, `derivaciones`, `incidencias_sanciones`
- **Tablas creadas:** Ninguno.
- **Columnas creadas:** No crea tablas.
- **Alteraciones de columnas/restricciones:** Ninguna mediante ALTER TABLE.
- **Funciones SQL:** `estado_reserva_paciente`, `registrar_sancion`, `aplicar_sancion_paciente`, `desbloquear_sanciones_vencidas`, `reservar_cita`
- **Triggers creados:** Ninguno.
- **Índices explícitos:** `ix_incidencias_sancion_paciente_fin`
- **Restricciones con nombre:** Ninguno. Las PK, UNIQUE, CHECK y FK declaradas en las tablas completan estas reglas; las FK relacionan los objetos listados y restringen borrados.
- **Dependencias:** V1 y motor de reservas V5–V8; R para verificación de correo.
- **Servicios Quarkus relacionados:** SancionService, PacienteRepository, AuthAccountGateway, ReservaCitaService, RecepcionService y ClinicaDaemonScheduler.
- **Datos demo:** No.
- **Carga:** classpath de dev, test y entorno habitual; en bases nuevas queda cubierta por B9, salvo R; en históricas se aplica y valida según su historial.
- **Conservación:** obligatoria; no eliminar ni editar si fue aplicada.
- **Funcionamiento y seguridad:** estado_reserva_paciente calcula el estado efectivo sin esperar al scheduler. registrar_sancion bloquea al paciente, evita nuevas sanciones superpuestas y audita actor y duración. El trigger aplica el bloqueo; desbloquear_sanciones_vencidas reactiva solo cuando corresponde. reservar_cita usa la misma vigencia bajo el mismo bloqueo. El índice parcial por paciente/fin acelera sanciones. No borra ni fusiona las sanciones antiguas.

### V3__corregir_credenciales_usuarios_demo.sql (local)

- **Versión/tipo/ruta:** 3, V; `backend_clinica/db/dev-migration/V3__corregir_credenciales_usuarios_demo.sql`. Es un archivo local ignorado por Git; su existencia depende del equipo. En esta auditoría existe y se conservó sin imprimir su contenido sensible.
- **Objetivo:** rotar el hash compartido de las cuatro identidades históricas de V2. Actualiza únicamente `usuarios.password_hash`; consulta usuarios y los perfiles pacientes/secretarias/medicos. No crea tablas, columnas, índices, restricciones, funciones persistentes ni triggers: usa un bloque DO transaccional.
- **Dependencias:** V1/V2; marcador Flyway `environment=development`, nombre de base `db_clinica`, cuatro cuentas esperadas activas con los roles y perfiles concretos. Si no se cumplen, falla y no actualiza parcialmente.
- **Servicios:** AuthService lee los hashes resultantes; ninguna función actual necesita esa rotación demo. Los generadores PowerShell/PHP, plantilla y verificador de login compartido se eliminaron; las suites usan fixtures con credenciales aleatorias propias.
- **Datos demo/seguridad:** sí; hash local sensible. No versionar, imprimir ni regenerar. Si ya figura en flyway_schema_history, conservar exactamente el archivo aplicado. Una copia de otro equipo con hash distinto no resuelve el checksum. No copiar al classpath normal.
- **Perfil/conservación:** solo la ubicación adicional del perfil dev permite resolverla. Se conserva esa configuración por compatibilidad con bases que ya la registraron. B9 la deja debajo del arranque inicial y no la ejecuta. Una instalación histórica con V3 pendiente aún podría ejecutarla al iniciar dev: revisar el historial antes del arranque y respaldar la copia; esta fase no modifica ni mueve la V3 local porque podría estar aplicada. Fuera de dev, para una base que sí aplicó V3, el operador debe incluir explícitamente la ubicación original mediante QUARKUS_FLYWAY_LOCATIONS o validate fallará; no ignorar “missing”.

## Orden de aplicación y perfiles

Estado observado el 13 de noviembre de 2026: la base habitual `db_clinica` contiene diez filas exitosas: V1, V2, V3 local, V4–V9 y R. La consulta fue de solo lectura. Sus diez checksums coinciden con los archivos locales resueltos por Flyway 12.0.0 en el servidor aislado; no se migró ni modificó la base habitual. B9 se probó exclusivamente en bases nuevas aisladas. Las bases de otros equipos pueden tener otro historial: comprobarlo antes de arrancar.

1. **Base vacía:** B9 → R__verificacion_email_otp. No se ejecutan V2 ni V3. Roles iniciales sí; usuarios, perfiles, especialidades, horarios y citas no.
2. **Base histórica:** V1 → V2 → V3 si existe y está habilitada → V4 → V5 → V6 → V7 → V8 → V9; R después de las V pendientes (o nuevamente si cambiara su checksum). B9 no se aplica a un historial iniciado. Una base que solo tenga V1 conserva la cadena con V2; requeriría un plan particular autorizado para omitir su demo.
3. **dev:** classpath más filesystem:db/dev-migration; out-of-order=true y placeholder environment=development para compatibilidad con una V3 tardía. No genera V3 automáticamente. Revisar V3 pendiente en bases históricas antes de arrancar. **test y habitual/prod:** solo classpath, out-of-order=false por defecto. Las pruebas de compatibilidad cargan V3 explícitamente en una base temporal y validan su checksum.
4. **Todas las ejecuciones normales:** migrate-at-start=true, validate-on-migrate=true (validación dentro de migrate, antes de aplicar pendientes), baseline-on-migrate=false, clean-disabled=true. Un esquema ajeno no vacío y sin historial se rechaza: no se adopta automáticamente. B9 no es baseline-on-migrate ni altera un historial previo.

## Ejecución segura y revisión en DBeaver

Para una instalación nueva, crear desde DBeaver una **base adicional vacía**, de nombre elegido expresamente, con un propietario local autorizado. Crear una base no sustituye ni elimina otra. Configurar externamente la URL JDBC y las credenciales; no copiar secretos a SQL ni a Git. Revisar que la URL señala esa base y arrancar Quarkus desde backend_clinica con `.\mvnw.cmd quarkus:dev`. B9 y R se ejecutan automáticamente. No ejecutar los SQL históricos en el editor.

En DBeaver, actualizar el esquema public y revisar Tables, Views, Functions, Triggers, Foreign Keys e Indexes; instalar el driver PostgreSQL cuando lo solicite. Ejecutar solo estas consultas para la comprobación:

```sql
SELECT current_database(), current_user, version();
SELECT installed_rank, version, description, type, script, checksum, installed_on, success
FROM public.flyway_schema_history ORDER BY installed_rank;
SELECT cod_rol, nombre_rol, estado FROM public.roles ORDER BY cod_rol;
SELECT cod_rol, count(*) FROM public.usuarios GROUP BY cod_rol;
SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name;
SELECT routine_name FROM information_schema.routines WHERE routine_schema='public' ORDER BY routine_name;
SELECT event_object_table, trigger_name FROM information_schema.triggers WHERE trigger_schema='public';
```

Una instalación nueva debe mostrar B9 como SQL_BASELINE y R como SQL, sin cuentas. Una histórica conserva las filas V originales. Flyway valida los checksums al arrancar; consultar la columna checksum solo permite observarlos, no sustituye validate. Ante un fallo de validación, detener el arranque, identificar el nombre del archivo y recuperar su original autorizado. No revelar parámetros de SQL ni credenciales al compartir logs. La suite `FlywayCompatibilityTest` prueba B9, cadena histórica, igualdad del esquema, conservación de cuentas/checksums y V3 original cuando existe; exige el servidor exclusivo 35441. `AgendaMigrationUpgradeTest` prueba upgrades desde V4–V8 con citas, QR, bloqueos y sanciones.

## Separación final de datos demo

V2 se conserva como historia y B9 permite instalaciones nuevas sin su seed. Los usuarios ya existentes no se borran ni se cambian sus contraseñas. V3 local sigue disponible para validación; ya no se distribuyen generadores de credenciales compartidas. Las fixtures se crean exclusivamente desde src/test y scripts/integration en PostgreSQL aislado con contraseñas aleatorias y guardas de propiedad; no se empaquetan como datos de arranque. El proveedor OTP en memoria es una herramienta opt-in de dev/test/integration con clave y loopback: sus endpoints no existen en el artefacto habitual. SMTP, Google y OTP reales se conservan.

## Advertencias obligatorias

- No editar migraciones aplicadas.
- No borrar archivos históricos sin plan de compatibilidad.
- No manipular checksums manualmente ni usar repair para ocultar problemas.
- No usar flyway clean en bases con información.
- No ejecutar scripts SQL históricos manualmente sobre una base existente.
- No borrar usuarios ni perfiles para “limpiar” un historial. Una cuenta demo histórica reutilizada puede tener referencias clínicas reales; requiere revisión individual autorizada.
- B9 no crea el primer administrador. La propuesta de alta controlada está en [configuracion-dbeaver.md](../../../../../../docs/configuracion-dbeaver.md); no hay contraseña universal ni endpoint de bootstrap.

Referencia del comportamiento B: [documentación oficial de Flyway](https://documentation.red-gate.com/flyway/flyway-concepts/migrations/baseline-migrations). Las baseline migrations están disponibles en Community según su [matriz oficial](https://documentation.red-gate.com/flyway/learn-more-about-flyway/feature-summary).
