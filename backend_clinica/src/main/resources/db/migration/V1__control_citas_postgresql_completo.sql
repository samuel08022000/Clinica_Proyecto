/* ============================================================================
   SISTEMA DE CONTROL DE CITAS MÉDICAS — PostgreSQL
   Reconstrucción basada en RF-01 a RF-26 del documento EVALUACION PROCESUAL.pdf.

   IMPORTANTE:
   - Script DESTRUCTIVO para las tablas del sistema en el esquema public: elimina
     sus datos actuales y los vuelve a crear. Hacer backup antes de ejecutarlo.
   - Ejecutar con un usuario propietario de la base de datos y permisos para crear
     extensiones. Requiere pgcrypto y btree_gist.
   - Configurar el backend para usar estas funciones y vistas; este script no crea
     endpoints Laravel ni envía SMS/correos por sí solo.
   - Contraseñas: el backend debe entregar aquí un hash bcrypt/Argon2, nunca texto
     plano. Los códigos OTP/tokens de recuperación se guardan como hash.
   - Para auditoría por actor, dentro de cada transacción autenticada ejecutar:
       SELECT set_config('app.cod_usu', '<COD_USU>', true);
   ============================================================================ */

SET TIME ZONE 'America/La_Paz';
SET client_encoding = 'UTF8';
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS btree_gist;
-- 1. ROLES Y USUARIOS (RF-01, RF-03, RF-04, RF-05)
CREATE TABLE public.roles (
    cod_rol       varchar(10) PRIMARY KEY,
    nombre_rol    varchar(40) NOT NULL UNIQUE,
    descripcion   text,
    estado        varchar(10) NOT NULL DEFAULT 'Activo'
                  CHECK (estado IN ('Activo','Inactivo'))
);

INSERT INTO public.roles(cod_rol, nombre_rol, descripcion) VALUES
 ('ROL001','Paciente','Usuario que solicita y consulta citas'),
 ('ROL002','Secretario','Personal de recepción y gestión presencial'),
 ('ROL003','Doctor','Profesional que atiende consultas'),
 ('ROL004','Administrador','Administra personal, agendas, catálogos y sanciones');

CREATE TABLE public.usuarios (
    secuencial       bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_usu          text GENERATED ALWAYS AS ('USU' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    nombre_completo  varchar(150) NOT NULL CHECK (length(trim(nombre_completo)) >= 3),
    email            varchar(254) NOT NULL,
    password_hash    varchar(255) NOT NULL,
    cod_rol          varchar(10) NOT NULL REFERENCES public.roles(cod_rol) ON UPDATE CASCADE ON DELETE RESTRICT,
    estado           varchar(12) NOT NULL DEFAULT 'Activo' CHECK (estado IN ('Activo','Inactivo')),
    creado_en        timestamptz NOT NULL DEFAULT now(),
    actualizado_en   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_usuarios_email_ci ON public.usuarios (lower(trim(email)));
CREATE INDEX ix_usuarios_rol_estado ON public.usuarios(cod_rol, estado);

-- 2. PERFILES DE PACIENTE, SECRETARÍA Y MÉDICO (RF-01, RF-05, RF-07)
CREATE TABLE public.pacientes (
    secuencial          bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_pac             text GENERATED ALWAYS AS ('PAC' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_usu             text NOT NULL UNIQUE REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    ci                  varchar(30) NOT NULL,
    fecha_nacimiento    date NOT NULL,
    telefono            varchar(25) NOT NULL,
    telefono_verificado boolean NOT NULL DEFAULT false,
    ciudad_residencia   varchar(100) NOT NULL,
    estado_cuenta       varchar(24) NOT NULL DEFAULT 'Activo'
                        CHECK (estado_cuenta IN ('Activo','Inactivo','Bloqueado_Temporal')),
    bloqueado_hasta     timestamptz,
    creado_en           timestamptz NOT NULL DEFAULT now(),
    CHECK ((estado_cuenta = 'Bloqueado_Temporal' AND bloqueado_hasta IS NOT NULL)
           OR estado_cuenta <> 'Bloqueado_Temporal')
);
CREATE UNIQUE INDEX uq_pacientes_ci_normalizado
    ON public.pacientes (upper(regexp_replace(trim(ci), '[^[:alnum:]]', '', 'g')));
CREATE INDEX ix_pacientes_bloqueo ON public.pacientes(estado_cuenta, bloqueado_hasta);

CREATE TABLE public.secretarias (
    secuencial bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_sec    text GENERATED ALWAYS AS ('SEC' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_usu    text NOT NULL UNIQUE REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    ci         varchar(30),
    telefono   varchar(25),
    estado     varchar(10) NOT NULL DEFAULT 'Activo' CHECK (estado IN ('Activo','Inactivo')),
    creado_en  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_secretarias_ci_normalizado
    ON public.secretarias (upper(regexp_replace(trim(ci), '[^[:alnum:]]', '', 'g')))
    WHERE ci IS NOT NULL AND trim(ci) <> '';

CREATE TABLE public.medicos (
    secuencial            bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_med               text GENERATED ALWAYS AS ('MED' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_usu               text NOT NULL UNIQUE REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    ci                    varchar(30),
    matricula_profesional varchar(80) NOT NULL UNIQUE,
    resumen_curricular    text,
    duracion_consulta_min smallint,
    estado                varchar(10) NOT NULL DEFAULT 'Activo' CHECK (estado IN ('Activo','Inactivo')),
    creado_en             timestamptz NOT NULL DEFAULT now(),
    CHECK (duracion_consulta_min IS NULL OR duracion_consulta_min IN (20,30,45,60))
);
CREATE UNIQUE INDEX uq_medicos_ci_normalizado
    ON public.medicos (upper(regexp_replace(trim(ci), '[^[:alnum:]]', '', 'g')))
    WHERE ci IS NOT NULL AND trim(ci) <> '';

-- 3. ESPECIALIDADES Y RELACIÓN MUCHOS-A-MUCHOS (RF-06, RF-07, RF-09)
CREATE TABLE public.especialidades (
    secuencial            bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_esp               text GENERATED ALWAYS AS ('ESP' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    nombre_especialidad   varchar(120) NOT NULL UNIQUE,
    descripcion           text,
    duracion_consulta_min smallint NOT NULL CHECK (duracion_consulta_min IN (20,30,45,60)),
    estado                varchar(10) NOT NULL DEFAULT 'Activo' CHECK (estado IN ('Activo','Inactivo')),
    creado_en             timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE public.medico_especialidades (
    cod_med               text NOT NULL REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    cod_esp               text NOT NULL REFERENCES public.especialidades(cod_esp) ON DELETE RESTRICT,
    duracion_consulta_min smallint,
    estado                varchar(10) NOT NULL DEFAULT 'Activo' CHECK (estado IN ('Activo','Inactivo')),
    PRIMARY KEY (cod_med, cod_esp),
    CHECK (duracion_consulta_min IS NULL OR duracion_consulta_min IN (20,30,45,60))
);
CREATE INDEX ix_medico_especialidades_esp ON public.medico_especialidades(cod_esp, estado);

-- 4. AGENDA SEMANAL Y EXCEPCIONES DE DISPONIBILIDAD (RF-08, RF-11, RF-12)
CREATE TABLE public.horarios (
    secuencial  bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_hor     text GENERATED ALWAYS AS ('HOR' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_med     text NOT NULL REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    dia_semana  smallint NOT NULL CHECK (dia_semana BETWEEN 1 AND 7), -- ISO: lunes=1...domingo=7
    hora_inicio time NOT NULL,
    hora_fin    time NOT NULL,
    pausa_inicio time,
    pausa_fin    time,
    estado      varchar(10) NOT NULL DEFAULT 'Activo' CHECK (estado IN ('Activo','Inactivo')),
    creado_en   timestamptz NOT NULL DEFAULT now(),
    CHECK (hora_fin > hora_inicio),
    CHECK ((pausa_inicio IS NULL AND pausa_fin IS NULL) OR
           (pausa_inicio IS NOT NULL AND pausa_fin IS NOT NULL
            AND pausa_inicio < pausa_fin AND pausa_inicio >= hora_inicio AND pausa_fin <= hora_fin))
);
CREATE INDEX ix_horarios_medico_dia ON public.horarios(cod_med, dia_semana, estado);

CREATE TABLE public.bloqueos_agenda (
    secuencial      bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_blo         text GENERATED ALWAYS AS ('BLO' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_med         text NOT NULL REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    cod_usu_admin   text NOT NULL REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    fecha_bloqueo   date NOT NULL,
    hora_inicio     time,
    hora_fin        time,
    motivo          varchar(500) NOT NULL CHECK (length(trim(motivo)) > 0),
    creado_en       timestamptz NOT NULL DEFAULT now(),
    CHECK ((hora_inicio IS NULL AND hora_fin IS NULL) OR
           (hora_inicio IS NOT NULL AND hora_fin IS NOT NULL AND hora_inicio < hora_fin))
);
CREATE INDEX ix_bloqueos_med_fecha ON public.bloqueos_agenda(cod_med, fecha_bloqueo);

-- Slots concretos, generados para cada fecha y especialidad. La disponibilidad real
-- se calcula en v_agenda_disponibilidad a partir de citas, pausas y bloqueos.
CREATE TABLE public.slots_agenda (
    secuencial bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_slot   text GENERATED ALWAYS AS ('SLT' || lpad(secuencial::text, 8, '0')) STORED PRIMARY KEY,
    cod_hor    text NOT NULL REFERENCES public.horarios(cod_hor) ON DELETE RESTRICT,
    cod_med    text NOT NULL REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    cod_esp    text NOT NULL REFERENCES public.especialidades(cod_esp) ON DELETE RESTRICT,
    inicio_at  timestamp NOT NULL,
    fin_at     timestamp NOT NULL,
    fecha      date GENERATED ALWAYS AS (inicio_at::date) STORED,
    hora_inicio time GENERATED ALWAYS AS (inicio_at::time) STORED,
    hora_fin   time GENERATED ALWAYS AS (fin_at::time) STORED,
    creado_en  timestamptz NOT NULL DEFAULT now(),
    CHECK (fin_at > inicio_at),
    UNIQUE (cod_hor, cod_esp, inicio_at),
    UNIQUE (cod_med, cod_esp, inicio_at)
);
CREATE INDEX ix_slots_fecha_esp_med ON public.slots_agenda(fecha, cod_esp, cod_med, inicio_at);
CREATE INDEX ix_slots_med_inicio ON public.slots_agenda(cod_med, inicio_at);

-- 5. CITAS, HISTORIAL Y RESERVAS ATÓMICAS (RF-13 a RF-25)
CREATE TABLE public.citas (
    secuencial      bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_cit         text GENERATED ALWAYS AS ('CIT' || lpad(secuencial::text, 8, '0')) STORED PRIMARY KEY,
    cod_pac         text NOT NULL REFERENCES public.pacientes(cod_pac) ON DELETE RESTRICT,
    cod_slot        text NOT NULL REFERENCES public.slots_agenda(cod_slot) ON DELETE RESTRICT,
    cod_med         text NOT NULL REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    cod_esp         text NOT NULL REFERENCES public.especialidades(cod_esp) ON DELETE RESTRICT,
    fecha           date NOT NULL,
    hora_inicio     time NOT NULL,
    hora_fin        time NOT NULL,
    estado          varchar(40) NOT NULL DEFAULT 'Confirmada'
                    CHECK (estado IN ('Confirmada','En Espera','Atendida','Cancelada',
                                      'No Asistió','Afectada/Pendiente Reprogramación','Reprogramada')),
    canal_reserva   varchar(15) NOT NULL DEFAULT 'Web' CHECK (canal_reserva IN ('Web','Ventanilla','Derivacion','Admin')),
    cod_sec         text REFERENCES public.secretarias(cod_sec) ON DELETE RESTRICT,
    creado_por_usu  text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    cancelado_por_usu text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    motivo_cancelacion text,
    motivo_reprogramacion text,
    cancelado_en    timestamptz,
    token_qr        text UNIQUE,
    hora_llegada_checkin timestamptz,
    checkin_por_usu text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    fecha_atencion  timestamptz,
    atendida_por_usu text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    cod_cit_padre   text REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    creado_en       timestamptz NOT NULL DEFAULT now(),
    actualizado_en  timestamptz NOT NULL DEFAULT now(),
    CHECK (hora_fin > hora_inicio),
    CHECK (cod_cit_padre IS NULL OR cod_cit_padre <> cod_cit),
    CHECK ((estado = 'Cancelada' AND cancelado_en IS NOT NULL AND motivo_cancelacion IS NOT NULL)
           OR estado <> 'Cancelada'),
    CHECK (estado <> 'En Espera' OR (hora_llegada_checkin IS NOT NULL AND checkin_por_usu IS NOT NULL)),
    CHECK (estado <> 'Atendida' OR (fecha_atencion IS NOT NULL AND atendida_por_usu IS NOT NULL))
);
-- Garantía contra doble reserva incluso con solicitudes concurrentes. Cancelar libera el cupo;
-- las citas históricas no se borran ni se solapan con nuevas citas activas.
ALTER TABLE public.citas ADD CONSTRAINT ex_citas_medico_sin_solape
    EXCLUDE USING gist (cod_med WITH =,
      tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&)
    WHERE (estado NOT IN ('Cancelada','Reprogramada'));
ALTER TABLE public.citas ADD CONSTRAINT ex_citas_paciente_sin_solape
    EXCLUDE USING gist (cod_pac WITH =,
      tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&)
    WHERE (estado NOT IN ('Cancelada','Reprogramada'));
CREATE UNIQUE INDEX uq_citas_slot_activa ON public.citas(cod_slot)
    WHERE estado NOT IN ('Cancelada','Reprogramada');
CREATE INDEX ix_citas_paciente_fecha ON public.citas(cod_pac, fecha DESC, hora_inicio DESC);
CREATE INDEX ix_citas_medico_fecha ON public.citas(cod_med, fecha, hora_inicio);
CREATE INDEX ix_citas_estado_fecha ON public.citas(estado, fecha);

CREATE TABLE public.derivaciones (
    secuencial      bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_der         text GENERATED ALWAYS AS ('DER' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_cit_origen  text NOT NULL REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    cod_cit_hija    text UNIQUE REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    cod_med_emisor  text NOT NULL REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    cod_esp_destino text NOT NULL REFERENCES public.especialidades(cod_esp) ON DELETE RESTRICT,
    cod_med_destino text REFERENCES public.medicos(cod_med) ON DELETE RESTRICT,
    motivo_derivacion text NOT NULL,
    estado          varchar(15) NOT NULL DEFAULT 'Pendiente'
                    CHECK (estado IN ('Pendiente','Agendada','Completada','Cancelada')),
    fecha_derivacion timestamptz NOT NULL DEFAULT now(),
    CHECK (cod_cit_hija IS NULL OR cod_cit_hija <> cod_cit_origen)
);
CREATE INDEX ix_derivaciones_origen ON public.derivaciones(cod_cit_origen, fecha_derivacion DESC);

CREATE TABLE public.incidencias_sanciones (
    secuencial        bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_inc           text GENERATED ALWAYS AS ('INC' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_pac           text NOT NULL REFERENCES public.pacientes(cod_pac) ON DELETE RESTRICT,
    cod_cit           text REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    registrado_por_usu text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    tipo_incidencia   varchar(30) NOT NULL
                      CHECK (tipo_incidencia IN ('CANCELACION','INASISTENCIA_NO_SHOW','SANCION_BLOQUEO','OTRA')),
    motivo            text NOT NULL,
    fecha_fin_sancion timestamptz,
    fecha_registro    timestamptz NOT NULL DEFAULT now(),
    CHECK ((tipo_incidencia = 'SANCION_BLOQUEO' AND fecha_fin_sancion IS NOT NULL)
           OR tipo_incidencia <> 'SANCION_BLOQUEO')
);
CREATE INDEX ix_incidencias_paciente_fecha ON public.incidencias_sanciones(cod_pac, fecha_registro DESC);

CREATE TABLE public.analisis (
    secuencial       bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_ana          text GENERATED ALWAYS AS ('ANA' || lpad(secuencial::text, 6, '0')) STORED PRIMARY KEY,
    cod_cit          text NOT NULL REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    solicitado_por_usu text NOT NULL REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    tipo_analisis    varchar(150) NOT NULL,
    resultado        text,
    archivo_pdf_url  text,
    estado           varchar(15) NOT NULL DEFAULT 'Pendiente'
                     CHECK (estado IN ('Pendiente','En Proceso','Completado','Cancelado','Entregado')),
    fecha_registro   timestamptz NOT NULL DEFAULT now(),
    fecha_entrega_estimada timestamptz,
    fecha_entrega_real timestamptz,
    creado_en        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_analisis_cita_estado ON public.analisis(cod_cit, estado);

-- 6. OTP, RECUPERACIÓN DE CONTRASEÑA Y CONFIGURACIÓN PRIVADA (RF-02, RF-03)
CREATE TABLE public.verificaciones_otp (
    secuencial    bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_otp       text GENERATED ALWAYS AS ('OTP' || lpad(secuencial::text, 8, '0')) STORED PRIMARY KEY,
    cod_usu       text NOT NULL REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    telefono_destino varchar(25) NOT NULL,
    codigo_hash   bytea NOT NULL,
    tipo_token    varchar(30) NOT NULL DEFAULT 'VERIFICACION_TELEFONO'
                  CHECK (tipo_token IN ('VERIFICACION_TELEFONO')),
    intentos      smallint NOT NULL DEFAULT 0 CHECK (intentos BETWEEN 0 AND 3),
    max_intentos  smallint NOT NULL DEFAULT 3 CHECK (max_intentos = 3),
    expira_en     timestamptz NOT NULL DEFAULT (now() + interval '5 minutes'),
    consumido_en  timestamptz,
    creado_en     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_otp_usuario_fecha ON public.verificaciones_otp(cod_usu, creado_en DESC);

CREATE TABLE public.tokens_recuperacion (
    secuencial bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_rec    text GENERATED ALWAYS AS ('REC' || lpad(secuencial::text, 8, '0')) STORED PRIMARY KEY,
    cod_usu    text NOT NULL REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    token_hash bytea NOT NULL,
    expira_en  timestamptz NOT NULL DEFAULT (now() + interval '15 minutes'),
    usado_en   timestamptz,
    creado_en  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_recuperacion_usuario_fecha ON public.tokens_recuperacion(cod_usu, creado_en DESC);

CREATE TABLE public.configuracion_seguridad (
    nombre  varchar(80) PRIMARY KEY,
    secreto bytea NOT NULL,
    creado_en timestamptz NOT NULL DEFAULT now()
);
INSERT INTO public.configuracion_seguridad(nombre, secreto)
VALUES ('QR_HMAC', gen_random_bytes(32));
REVOKE ALL ON public.configuracion_seguridad FROM PUBLIC;
REVOKE ALL ON public.verificaciones_otp FROM PUBLIC;
REVOKE ALL ON public.tokens_recuperacion FROM PUBLIC;

-- 7. COLA MULTICANAL DE NOTIFICACIONES (RF-12, RF-26)
CREATE TABLE public.notificaciones (
    cod_not          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cod_usu          text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    cod_cit          text REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    canal            varchar(12) NOT NULL CHECK (canal IN ('Web','Email','WhatsApp','SMS')),
    tipo_alerta      varchar(40) NOT NULL,
    destinatario     varchar(254),
    mensaje          text NOT NULL,
    fecha_programada timestamptz NOT NULL DEFAULT now(),
    estado           varchar(15) NOT NULL DEFAULT 'Pendiente'
                     CHECK (estado IN ('Pendiente','EnProceso','Enviada','Fallida','Cancelada')),
    intentos         smallint NOT NULL DEFAULT 0 CHECK (intentos >= 0),
    max_intentos     smallint NOT NULL DEFAULT 5 CHECK (max_intentos > 0),
    enviado_en       timestamptz,
    en_proceso_desde timestamptz,
    ultimo_error     text,
    creado_en        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_notificaciones_pendientes ON public.notificaciones(estado, fecha_programada)
    WHERE estado IN ('Pendiente','EnProceso');
CREATE INDEX ix_notificaciones_usuario_fecha ON public.notificaciones(cod_usu, creado_en DESC);

-- 8. BITÁCORA INMUTABLE DE AUDITORÍA (RNF-06 referenciado en el documento)
CREATE TABLE public.bitacora (
    secuencial        bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_bit           text GENERATED ALWAYS AS ('BIT' || lpad(secuencial::text, 8, '0')) STORED PRIMARY KEY,
    cod_usu           text REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    nombre_tabla      varchar(100) NOT NULL,
    accion            varchar(30) NOT NULL,
    clave_registro    text,
    detalles          text,
    datos_anteriores  jsonb,
    datos_nuevos      jsonb,
    registrado_en     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_bitacora_tabla_fecha ON public.bitacora(nombre_tabla, registrado_en DESC);
CREATE INDEX ix_bitacora_usuario_fecha ON public.bitacora(cod_usu, registrado_en DESC);

-- 9. FUNCIONES DE AUDITORÍA Y VALIDACIÓN
-- Las agendas se guardan como hora local de la clínica, independientemente de la zona horaria
-- de la conexión del backend.
CREATE OR REPLACE FUNCTION public.fecha_clinica() RETURNS date
LANGUAGE sql STABLE AS $$
    SELECT (now() AT TIME ZONE 'America/La_Paz')::date;
$$;
CREATE OR REPLACE FUNCTION public.ahora_clinica() RETURNS timestamp
LANGUAGE sql STABLE AS $$
    SELECT now() AT TIME ZONE 'America/La_Paz';
$$;

CREATE OR REPLACE FUNCTION public.actor_actual() RETURNS text
LANGUAGE sql STABLE AS $$
    SELECT NULLIF(current_setting('app.cod_usu', true), '')::text;
$$;

CREATE OR REPLACE FUNCTION public.auditar_fila() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_actor text;
    v_old jsonb;
    v_new jsonb;
    v_key text;
BEGIN
    v_actor := public.actor_actual();
    IF TG_OP <> 'INSERT' THEN
        v_old := to_jsonb(OLD) - ARRAY['password_hash','codigo_hash','token_hash','token_qr','telefono','email','ci','resultado','archivo_pdf_url'];
    END IF;
    IF TG_OP <> 'DELETE' THEN
        v_new := to_jsonb(NEW) - ARRAY['password_hash','codigo_hash','token_hash','token_qr','telefono','email','ci','resultado','archivo_pdf_url'];
    END IF;
    v_key := COALESCE(v_new->>TG_ARGV[0], v_old->>TG_ARGV[0], 'sin-clave');
    INSERT INTO public.bitacora(cod_usu, nombre_tabla, accion, clave_registro,
                                detalles, datos_anteriores, datos_nuevos)
    VALUES (v_actor, TG_TABLE_NAME, TG_OP, v_key,
            TG_OP || ' en ' || TG_TABLE_NAME, v_old, v_new);
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.impedir_edicion_historial() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'El registro histórico en % es inmutable; registre una nueva incidencia en lugar de modificarlo.', TG_TABLE_NAME;
END;
$$;

CREATE OR REPLACE FUNCTION public.validar_horario_semanal() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.estado = 'Activo' THEN
        IF EXISTS (
            SELECT 1 FROM public.horarios h
            WHERE h.cod_med = NEW.cod_med AND h.dia_semana = NEW.dia_semana
              AND h.estado = 'Activo' AND h.secuencial IS DISTINCT FROM NEW.secuencial
              AND NEW.hora_inicio < h.hora_fin AND NEW.hora_fin > h.hora_inicio
        ) THEN
            RAISE EXCEPTION 'El horario se superpone con otro rango activo del mismo médico en ese día.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_validar_horario_semanal
BEFORE INSERT OR UPDATE ON public.horarios
FOR EACH ROW EXECUTE FUNCTION public.validar_horario_semanal();

-- 10. REGISTRO Y VERIFICACIÓN OTP; envío del código lo realiza el backend (SMS gateway).
CREATE OR REPLACE FUNCTION public.registrar_paciente(
    p_nombre_completo text,
    p_email text,
    p_password_hash text,
    p_ci text,
    p_fecha_nacimiento date,
    p_telefono text,
    p_ciudad text
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_usu text; v_pac text;
BEGIN
    IF p_password_hash IS NULL OR length(trim(p_password_hash)) < 20 THEN
        RAISE EXCEPTION 'Se requiere un hash de contraseña bcrypt/Argon2 generado por el backend; no enviar la contraseña en texto plano.';
    END IF;
    INSERT INTO public.usuarios(nombre_completo,email,password_hash,cod_rol)
    VALUES (trim(p_nombre_completo), lower(trim(p_email)), p_password_hash, 'ROL001')
    RETURNING cod_usu INTO v_usu;
    INSERT INTO public.pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia)
    VALUES (v_usu,trim(p_ci),p_fecha_nacimiento,trim(p_telefono),trim(p_ciudad))
    RETURNING cod_pac INTO v_pac;
    RETURN v_pac;
END;
$$;

CREATE OR REPLACE FUNCTION public.crear_otp(p_cod_usu text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_tel text; v_rand bytea; v_num bigint; v_codigo text;
BEGIN
    SELECT p.telefono INTO v_tel
    FROM public.pacientes p JOIN public.usuarios u ON u.cod_usu=p.cod_usu
    WHERE p.cod_usu=p_cod_usu AND u.estado='Activo' AND p.estado_cuenta='Activo'
    FOR UPDATE OF p;
    IF NOT FOUND THEN RAISE EXCEPTION 'No existe un paciente activo con ese usuario.'; END IF;
    IF EXISTS (SELECT 1 FROM public.verificaciones_otp
               WHERE cod_usu=p_cod_usu AND creado_en > now()-interval '60 seconds'
                 AND consumido_en IS NULL) THEN
        RAISE EXCEPTION 'Espere al menos 60 segundos antes de solicitar otro código.';
    END IF;
    IF (SELECT count(*) FROM public.verificaciones_otp
        WHERE cod_usu=p_cod_usu AND creado_en > now()-interval '1 hour') >= 5 THEN
        RAISE EXCEPTION 'Se alcanzó el límite de solicitudes OTP por hora.';
    END IF;
    UPDATE public.verificaciones_otp SET consumido_en=now()
    WHERE cod_usu=p_cod_usu AND consumido_en IS NULL;
    v_rand := gen_random_bytes(4);
    v_num := ((get_byte(v_rand,0)::bigint << 24) +
              (get_byte(v_rand,1)::bigint << 16) +
              (get_byte(v_rand,2)::bigint << 8) + get_byte(v_rand,3)::bigint) % 1000000;
    v_codigo := lpad(v_num::text,6,'0');
    INSERT INTO public.verificaciones_otp(cod_usu,telefono_destino,codigo_hash)
    VALUES (p_cod_usu,v_tel,digest(v_codigo,'sha256'));
    -- Devuelve una sola vez el código al backend para enviarlo por SMS; nunca se guarda en claro.
    RETURN v_codigo;
END;
$$;

CREATE OR REPLACE FUNCTION public.validar_otp(p_cod_usu text, p_codigo text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_otp public.verificaciones_otp%ROWTYPE;
BEGIN
    SELECT * INTO v_otp FROM public.verificaciones_otp
    WHERE cod_usu=p_cod_usu AND tipo_token='VERIFICACION_TELEFONO'
      AND consumido_en IS NULL
    ORDER BY creado_en DESC LIMIT 1 FOR UPDATE;
    IF NOT FOUND THEN RETURN false; END IF;
    IF v_otp.expira_en <= now() OR v_otp.intentos >= v_otp.max_intentos THEN
        UPDATE public.verificaciones_otp SET consumido_en=now() WHERE cod_otp=v_otp.cod_otp;
        RETURN false;
    END IF;
    UPDATE public.verificaciones_otp SET intentos=intentos+1 WHERE cod_otp=v_otp.cod_otp;
    IF digest(COALESCE(p_codigo,''),'sha256') = v_otp.codigo_hash THEN
        UPDATE public.verificaciones_otp SET consumido_en=now() WHERE cod_otp=v_otp.cod_otp;
        UPDATE public.pacientes SET telefono_verificado=true WHERE cod_usu=p_cod_usu;
        RETURN true;
    END IF;
    IF v_otp.intentos + 1 >= v_otp.max_intentos THEN
        UPDATE public.verificaciones_otp SET consumido_en=now() WHERE cod_otp=v_otp.cod_otp;
    END IF;
    RETURN false;
END;
$$;

CREATE OR REPLACE FUNCTION public.crear_token_recuperacion(p_email text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_usu text; v_token text;
BEGIN
    SELECT cod_usu INTO v_usu FROM public.usuarios
    WHERE lower(email)=lower(trim(p_email)) AND estado='Activo';
    IF NOT FOUND THEN RETURN NULL; END IF; -- respuesta neutra para no revelar cuentas
    IF (SELECT count(*) FROM public.tokens_recuperacion
        WHERE cod_usu=v_usu AND creado_en > now()-interval '1 hour') >= 3 THEN
        RAISE EXCEPTION 'Se alcanzó el límite de solicitudes de recuperación por hora.';
    END IF;
    UPDATE public.tokens_recuperacion SET usado_en=now()
    WHERE cod_usu=v_usu AND usado_en IS NULL;
    v_token := encode(gen_random_bytes(32),'hex');
    INSERT INTO public.tokens_recuperacion(cod_usu,token_hash)
    VALUES (v_usu,digest(v_token,'sha256'));
    -- El backend entrega el token por un canal verificado y lo incluye en un enlace HTTPS.
    RETURN v_token;
END;
$$;

CREATE OR REPLACE FUNCTION public.restablecer_contrasena(
    p_email text, p_token text, p_nuevo_password_hash text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_usu text; v_rec public.tokens_recuperacion%ROWTYPE;
BEGIN
    IF p_nuevo_password_hash IS NULL OR length(trim(p_nuevo_password_hash)) < 20 THEN
        RAISE EXCEPTION 'Se requiere un hash de contraseña bcrypt/Argon2 generado por el backend.';
    END IF;
    SELECT cod_usu INTO v_usu FROM public.usuarios
    WHERE lower(email)=lower(trim(p_email)) AND estado='Activo';
    IF NOT FOUND THEN RETURN false; END IF;
    SELECT * INTO v_rec FROM public.tokens_recuperacion
    WHERE cod_usu=v_usu AND token_hash=digest(COALESCE(p_token,''),'sha256')
      AND usado_en IS NULL AND expira_en > now()
    ORDER BY creado_en DESC LIMIT 1 FOR UPDATE;
    IF NOT FOUND THEN RETURN false; END IF;
    UPDATE public.usuarios SET password_hash=p_nuevo_password_hash, actualizado_en=now()
    WHERE cod_usu=v_usu;
    UPDATE public.tokens_recuperacion SET usado_en=now() WHERE cod_rec=v_rec.cod_rec;
    UPDATE public.tokens_recuperacion SET usado_en=now()
    WHERE cod_usu=v_usu AND usado_en IS NULL;
    RETURN true;
END;
$$;

-- 11. GENERACIÓN DINÁMICA DE SLOTS (RF-08, RF-09, RF-10, RF-13)
CREATE OR REPLACE FUNCTION public.generar_slots_agenda(
    p_desde date DEFAULT public.fecha_clinica(),
    p_hasta date DEFAULT (public.fecha_clinica() + 14)
) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    r record; v_fecha date; v_inicio timestamp; v_fin timestamp;
    v_duracion integer; v_insertados integer := 0; v_total integer := 0;
BEGIN
    IF p_desde < public.fecha_clinica() OR p_hasta < p_desde OR p_hasta > public.fecha_clinica() + 14 THEN
        RAISE EXCEPTION 'La generación de slots debe estar dentro de la ventana móvil de D a D+14.';
    END IF;
    FOR r IN
        SELECT h.cod_hor, h.cod_med, h.dia_semana, h.hora_inicio, h.hora_fin,
               h.pausa_inicio, h.pausa_fin, m.duracion_consulta_min AS dur_med,
               me.cod_esp, me.duracion_consulta_min AS dur_med_esp,
               e.duracion_consulta_min AS dur_esp
        FROM public.horarios h
        JOIN public.medicos m ON m.cod_med=h.cod_med AND m.estado='Activo'
        JOIN public.medico_especialidades me ON me.cod_med=m.cod_med AND me.estado='Activo'
        JOIN public.especialidades e ON e.cod_esp=me.cod_esp AND e.estado='Activo'
        WHERE h.estado='Activo'
    LOOP
        v_duracion := COALESCE(r.dur_med_esp, r.dur_med, r.dur_esp);
        FOR v_fecha IN
            SELECT g::date FROM generate_series(p_desde::timestamp, p_hasta::timestamp, interval '1 day') AS g
            WHERE extract(isodow FROM g)::integer = r.dia_semana
        LOOP
            FOR v_inicio IN
                SELECT g::timestamp
                FROM generate_series(
                    v_fecha + r.hora_inicio,
                    (v_fecha + r.hora_fin) - make_interval(mins => v_duracion),
                    make_interval(mins => v_duracion)
                ) AS g
            LOOP
                v_fin := v_inicio + make_interval(mins => v_duracion);
                IF r.pausa_inicio IS NOT NULL
                   AND tsrange(v_inicio,v_fin,'[)') &&
                       tsrange(v_fecha+r.pausa_inicio,v_fecha+r.pausa_fin,'[)') THEN
                    CONTINUE;
                END IF;
                INSERT INTO public.slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
                VALUES (r.cod_hor,r.cod_med,r.cod_esp,v_inicio,v_fin)
                ON CONFLICT DO NOTHING;
                GET DIAGNOSTICS v_insertados = ROW_COUNT;
                v_total := v_total + v_insertados;
            END LOOP;
        END LOOP;
    END LOOP;
    RETURN v_total;
END;
$$;

-- Al cambiar el patrón semanal, una especialidad o su vínculo con un médico,
-- se regeneran automáticamente los slots de los próximos 14 días.
CREATE OR REPLACE FUNCTION public.regenerar_slots_automaticamente() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    PERFORM public.generar_slots_agenda(public.fecha_clinica(), public.fecha_clinica() + 14);
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_slots_despues_horario
AFTER INSERT OR UPDATE ON public.horarios
FOR EACH ROW EXECUTE FUNCTION public.regenerar_slots_automaticamente();
CREATE TRIGGER trg_slots_despues_medico_especialidad
AFTER INSERT OR UPDATE ON public.medico_especialidades
FOR EACH ROW EXECUTE FUNCTION public.regenerar_slots_automaticamente();
CREATE TRIGGER trg_slots_despues_especialidad
AFTER INSERT OR UPDATE ON public.especialidades
FOR EACH ROW EXECUTE FUNCTION public.regenerar_slots_automaticamente();
CREATE TRIGGER trg_slots_despues_medico
AFTER INSERT OR UPDATE ON public.medicos
FOR EACH ROW EXECUTE FUNCTION public.regenerar_slots_automaticamente();

-- Vista de disponibilidad: filtra bloqueos, pausas, citas existentes, inactividad y D..D+14.
CREATE OR REPLACE VIEW public.v_agenda_disponibilidad AS
SELECT s.cod_slot, s.cod_med, m.cod_usu AS cod_usu_medico,
       u.nombre_completo AS nombre_medico,
       s.cod_esp, e.nombre_especialidad,
       s.fecha, s.hora_inicio, s.hora_fin,
       CASE
         WHEN s.inicio_at <= public.ahora_clinica() THEN 'Expirado'
         WHEN m.estado <> 'Activo' OR e.estado <> 'Activo' OR h.estado <> 'Activo'
              OR me.estado <> 'Activo' THEN 'No Disponible'
         WHEN h.dia_semana <> extract(isodow FROM s.fecha)::integer
              OR s.inicio_at < (s.fecha + h.hora_inicio)
              OR s.fin_at > (s.fecha + h.hora_fin)
              OR (s.fin_at-s.inicio_at) <> make_interval(mins => COALESCE(me.duracion_consulta_min,m.duracion_consulta_min,e.duracion_consulta_min))
              OR (h.pausa_inicio IS NOT NULL AND
                  tsrange(s.inicio_at,s.fin_at,'[)') &&
                  tsrange(s.fecha+h.pausa_inicio,s.fecha+h.pausa_fin,'[)')) THEN 'No Disponible'
         WHEN EXISTS (
             SELECT 1 FROM public.bloqueos_agenda b
             WHERE b.cod_med=s.cod_med AND b.fecha_bloqueo=s.fecha
               AND (b.hora_inicio IS NULL OR
                    tsrange(s.inicio_at,s.fin_at,'[)') &&
                    tsrange(s.fecha+b.hora_inicio,s.fecha+b.hora_fin,'[)'))
         ) THEN 'Bloqueado'
         WHEN EXISTS (
             SELECT 1 FROM public.citas c
             WHERE c.cod_med=s.cod_med
               AND c.estado NOT IN ('Cancelada','Reprogramada')
               AND tsrange(s.fecha+s.hora_inicio,s.fecha+s.hora_fin,'[)') &&
                   tsrange(c.fecha+c.hora_inicio,c.fecha+c.hora_fin,'[)')
         ) THEN 'Ocupado'
         ELSE 'Disponible'
       END AS estado_disponibilidad
FROM public.slots_agenda s
JOIN public.medicos m ON m.cod_med=s.cod_med
JOIN public.usuarios u ON u.cod_usu=m.cod_usu
JOIN public.especialidades e ON e.cod_esp=s.cod_esp
JOIN public.horarios h ON h.cod_hor=s.cod_hor
JOIN public.medico_especialidades me ON me.cod_med=s.cod_med AND me.cod_esp=s.cod_esp
WHERE s.fecha BETWEEN public.fecha_clinica() AND public.fecha_clinica()+14;

-- 12. VALIDACIÓN DE CITA EN INSERT/UPDATE (incluye la modificación del turno)
CREATE OR REPLACE FUNCTION public.validar_cita() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_slot public.slots_agenda%ROWTYPE;
    v_pac public.pacientes%ROWTYPE;
    v_necesita_validar boolean;
BEGIN
    v_necesita_validar := (TG_OP='INSERT');
    IF TG_OP='UPDATE' THEN
        v_necesita_validar := (NEW.cod_slot IS DISTINCT FROM OLD.cod_slot)
          OR (OLD.estado IN ('Cancelada','Reprogramada') AND NEW.estado NOT IN ('Cancelada','Reprogramada'));
    END IF;

    SELECT * INTO v_slot FROM public.slots_agenda WHERE cod_slot=NEW.cod_slot FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'El slot solicitado no existe.'; END IF;

    -- Fechas, médico, especialidad y rango siempre proceden del slot: evita datos falsos del cliente.
    NEW.cod_med := v_slot.cod_med;
    NEW.cod_esp := v_slot.cod_esp;
    NEW.fecha := v_slot.fecha;
    NEW.hora_inicio := v_slot.hora_inicio;
    NEW.hora_fin := v_slot.hora_fin;
    NEW.actualizado_en := now();

    IF TG_OP='UPDATE' AND NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NEW.estado='En Espera' AND OLD.estado <> 'Confirmada' THEN
            RAISE EXCEPTION 'Solo una cita confirmada puede pasar a En Espera.';
        ELSIF NEW.estado='Atendida' AND OLD.estado <> 'En Espera' THEN
            RAISE EXCEPTION 'Solo una cita en espera puede pasar a Atendida.';
        ELSIF NEW.estado='No Asistió' AND (OLD.estado <> 'Confirmada' OR NEW.hora_llegada_checkin IS NOT NULL OR (NEW.fecha+NEW.hora_fin) >= public.ahora_clinica()) THEN
            RAISE EXCEPTION 'Solo puede marcarse No Asistió una cita confirmada vencida y sin check-in.';
        ELSIF NEW.estado='Cancelada' AND OLD.estado IN ('Atendida','No Asistió','Cancelada','Reprogramada') THEN
            RAISE EXCEPTION 'No se puede cancelar una cita ya atendida, ausente o cerrada.';
        END IF;
    END IF;

    IF v_necesita_validar AND NEW.estado NOT IN ('Cancelada','Reprogramada') THEN
        IF v_slot.fecha < public.fecha_clinica() OR v_slot.fecha > public.fecha_clinica() + 14 THEN
            RAISE EXCEPTION 'Solo se admiten reservas desde hoy hasta D+14.';
        END IF;
        IF v_slot.inicio_at <= public.ahora_clinica() THEN RAISE EXCEPTION 'El slot ya pasó.'; END IF;
        SELECT * INTO v_pac FROM public.pacientes WHERE cod_pac=NEW.cod_pac FOR UPDATE;
        IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente.'; END IF;
        IF v_pac.estado_cuenta='Inactivo' THEN RAISE EXCEPTION 'La cuenta del paciente está inactiva.'; END IF;
        IF v_pac.bloqueado_hasta IS NOT NULL AND v_pac.bloqueado_hasta > now() THEN
            RAISE EXCEPTION 'El paciente tiene una sanción activa hasta %.', v_pac.bloqueado_hasta;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM public.usuarios u WHERE u.cod_usu=v_pac.cod_usu AND u.estado='Activo') THEN
            RAISE EXCEPTION 'El usuario del paciente está inactivo.';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM public.medicos m WHERE m.cod_med=v_slot.cod_med AND m.estado='Activo') THEN
            RAISE EXCEPTION 'El médico no está activo para nuevas reservas.';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM public.especialidades e WHERE e.cod_esp=v_slot.cod_esp AND e.estado='Activo') THEN
            RAISE EXCEPTION 'La especialidad no está activa para nuevas reservas.';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM public.medico_especialidades me
            JOIN public.especialidades e ON e.cod_esp=me.cod_esp
            JOIN public.medicos m ON m.cod_med=me.cod_med
            JOIN public.horarios h ON h.cod_med=m.cod_med
            WHERE me.cod_med=v_slot.cod_med AND me.cod_esp=v_slot.cod_esp
              AND me.estado='Activo' AND e.estado='Activo' AND m.estado='Activo'
              AND h.cod_hor=v_slot.cod_hor AND h.estado='Activo'
              AND h.dia_semana=extract(isodow FROM v_slot.fecha)::integer
              AND v_slot.inicio_at >= v_slot.fecha+h.hora_inicio
              AND v_slot.fin_at <= v_slot.fecha+h.hora_fin
              AND (h.pausa_inicio IS NULL OR NOT (
                   tsrange(v_slot.inicio_at,v_slot.fin_at,'[)') &&
                   tsrange(v_slot.fecha+h.pausa_inicio,v_slot.fecha+h.pausa_fin,'[)')))
              AND (v_slot.fin_at-v_slot.inicio_at) = make_interval(mins => COALESCE(me.duracion_consulta_min,m.duracion_consulta_min,e.duracion_consulta_min))
        ) THEN
            RAISE EXCEPTION 'El slot ya no corresponde a una agenda/especialidad activa o cambió su duración.';
        END IF;
        IF EXISTS (
            SELECT 1 FROM public.bloqueos_agenda b
            WHERE b.cod_med=v_slot.cod_med AND b.fecha_bloqueo=v_slot.fecha
              AND (b.hora_inicio IS NULL OR tsrange(v_slot.inicio_at,v_slot.fin_at,'[)') &&
                   tsrange(v_slot.fecha+b.hora_inicio,v_slot.fecha+b.hora_fin,'[)'))
        ) THEN RAISE EXCEPTION 'El slot está bloqueado por una excepción de agenda.'; END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_validar_cita
BEFORE INSERT OR UPDATE ON public.citas
FOR EACH ROW EXECUTE FUNCTION public.validar_cita();

-- 13. TOKEN QR FIRMADO (RF-18, RF-19); no incluye información clínica.
CREATE OR REPLACE FUNCTION public.crear_token_qr(p_cod_cit text, p_fecha date) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_secret bytea; v_payload text; v_firma text;
BEGIN
    SELECT secreto INTO v_secret FROM public.configuracion_seguridad WHERE nombre='QR_HMAC';
    IF v_secret IS NULL THEN RAISE EXCEPTION 'No está configurada la clave de firma QR.'; END IF;
    v_payload := p_cod_cit || '|' || to_char(p_fecha,'YYYY-MM-DD') || '|' || encode(gen_random_bytes(16),'hex');
    v_firma := encode(hmac(convert_to(v_payload,'UTF8'),v_secret,'sha256'),'hex');
    RETURN encode(convert_to(v_payload,'UTF8'),'base64') || '.' || v_firma;
END;
$$;

-- 14. COLA DE CONFIRMACIONES / RECORDATORIOS / CAMBIOS DE CITA (RF-12, RF-22, RF-26)
CREATE OR REPLACE FUNCTION public.procesar_evento_cita() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_usu text; v_email text; v_tel text; v_msg text; v_es_nueva_confirmacion boolean := false;
BEGIN
    IF TG_OP='INSERT' THEN
        v_es_nueva_confirmacion := (NEW.estado='Confirmada');
    ELSE
        v_es_nueva_confirmacion := NEW.estado='Confirmada' AND
           (OLD.estado IS DISTINCT FROM NEW.estado OR OLD.cod_slot IS DISTINCT FROM NEW.cod_slot);
    END IF;

    SELECT p.cod_usu,u.email,p.telefono INTO v_usu,v_email,v_tel
    FROM public.pacientes p JOIN public.usuarios u ON u.cod_usu=p.cod_usu
    WHERE p.cod_pac=NEW.cod_pac;

    IF v_es_nueva_confirmacion THEN
        UPDATE public.citas SET token_qr=public.crear_token_qr(NEW.cod_cit,NEW.fecha)
        WHERE cod_cit=NEW.cod_cit;
        UPDATE public.notificaciones SET estado='Cancelada'
        WHERE cod_cit=NEW.cod_cit AND tipo_alerta IN ('Confirmacion','Recordatorio_24h') AND estado='Pendiente';
        v_msg := 'Confirmación de cita ' || NEW.cod_cit || ' para ' || to_char(NEW.fecha,'YYYY-MM-DD') ||
                 ' de ' || NEW.hora_inicio::text || ' a ' || NEW.hora_fin::text || '.';
        INSERT INTO public.notificaciones(cod_usu,cod_cit,canal,tipo_alerta,destinatario,mensaje,fecha_programada)
        VALUES (v_usu,NEW.cod_cit,'Web','Confirmacion',v_usu,v_msg,now()),
               (v_usu,NEW.cod_cit,'Email', 'Confirmacion',v_email,v_msg,now());
        INSERT INTO public.notificaciones(cod_usu,cod_cit,canal,tipo_alerta,destinatario,mensaje,fecha_programada)
        VALUES (v_usu,NEW.cod_cit,'Web','Recordatorio_24h',v_usu,
                'Recordatorio: tiene la cita ' || NEW.cod_cit || ' el ' || to_char(NEW.fecha,'YYYY-MM-DD') ||
                ' de ' || NEW.hora_inicio::text || ' a ' || NEW.hora_fin::text || '.',
                (NEW.fecha + NEW.hora_inicio) AT TIME ZONE 'America/La_Paz' - interval '24 hours'),
               (v_usu,NEW.cod_cit,'Email','Recordatorio_24h',v_email,
                'Recordatorio: tiene la cita ' || NEW.cod_cit || ' el ' || to_char(NEW.fecha,'YYYY-MM-DD') ||
                ' de ' || NEW.hora_inicio::text || ' a ' || NEW.hora_fin::text || '.',
                (NEW.fecha + NEW.hora_inicio) AT TIME ZONE 'America/La_Paz' - interval '24 hours');
    ELSIF TG_OP='UPDATE' AND NEW.estado='Cancelada' AND OLD.estado <> 'Cancelada' THEN
        UPDATE public.notificaciones SET estado='Cancelada'
        WHERE cod_cit=NEW.cod_cit AND tipo_alerta IN ('Confirmacion','Recordatorio_24h') AND estado='Pendiente';
        INSERT INTO public.notificaciones(cod_usu,cod_cit,canal,tipo_alerta,destinatario,mensaje,fecha_programada)
        VALUES (v_usu,NEW.cod_cit,'Web','Cita_Cancelada',v_usu,
                'La cita '||NEW.cod_cit||' fue cancelada. Motivo: '||COALESCE(NEW.motivo_cancelacion,'No especificado'),now()),
               (v_usu,NEW.cod_cit,'Email','Cita_Cancelada',v_email,
                'La cita '||NEW.cod_cit||' fue cancelada. Motivo: '||COALESCE(NEW.motivo_cancelacion,'No especificado'),now());
        UPDATE public.analisis SET estado='Cancelado'
        WHERE cod_cit=NEW.cod_cit AND estado='Pendiente';
        INSERT INTO public.incidencias_sanciones(cod_pac,cod_cit,registrado_por_usu,tipo_incidencia,motivo)
        VALUES (NEW.cod_pac,NEW.cod_cit,COALESCE(NEW.cancelado_por_usu,public.actor_actual()),
                'CANCELACION',COALESCE(NEW.motivo_cancelacion,'Cancelación de cita'));
    ELSIF TG_OP='UPDATE' AND NEW.estado='No Asistió' AND OLD.estado <> 'No Asistió' THEN
        INSERT INTO public.incidencias_sanciones(cod_pac,cod_cit,registrado_por_usu,tipo_incidencia,motivo)
        VALUES (NEW.cod_pac,NEW.cod_cit,public.actor_actual(),'INASISTENCIA_NO_SHOW','Inasistencia sin check-in antes del fin de la cita.');
    ELSIF TG_OP='UPDATE' AND NEW.estado='Afectada/Pendiente Reprogramación'
          AND OLD.estado IS DISTINCT FROM NEW.estado THEN
        UPDATE public.notificaciones SET estado='Cancelada'
        WHERE cod_cit=NEW.cod_cit AND tipo_alerta IN ('Confirmacion','Recordatorio_24h') AND estado='Pendiente';
        INSERT INTO public.notificaciones(cod_usu,cod_cit,canal,tipo_alerta,destinatario,mensaje,fecha_programada)
        VALUES (v_usu,NEW.cod_cit,'Web','Cita_Afectada',v_usu,
                'La cita '||NEW.cod_cit||' fue afectada por un bloqueo de agenda. Contacte a la clínica para reprogramar.',now()),
               (v_usu,NEW.cod_cit,'Email','Cita_Afectada',v_email,
                'La cita '||NEW.cod_cit||' fue afectada por un bloqueo de agenda. Contacte a la clínica para reprogramar.',now());
    ELSIF TG_OP='UPDATE' AND NEW.estado='En Espera' AND OLD.estado IS DISTINCT FROM NEW.estado THEN
        UPDATE public.citas SET hora_llegada_checkin=COALESCE(NEW.hora_llegada_checkin,now())
        WHERE cod_cit=NEW.cod_cit AND hora_llegada_checkin IS NULL;
    ELSIF TG_OP='UPDATE' AND NEW.estado='Atendida' AND OLD.estado IS DISTINCT FROM NEW.estado THEN
        UPDATE public.citas SET fecha_atencion=COALESCE(NEW.fecha_atencion,now())
        WHERE cod_cit=NEW.cod_cit AND fecha_atencion IS NULL;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_procesar_evento_cita
AFTER INSERT OR UPDATE OF estado, cod_slot, cod_pac, fecha ON public.citas
FOR EACH ROW EXECUTE FUNCTION public.procesar_evento_cita();

-- Auditoría de citas separada de los cambios internos exclusivos del token QR.
CREATE TRIGGER trg_auditar_citas
AFTER INSERT OR UPDATE OF estado, cod_slot, cod_pac, cod_med, cod_esp, fecha,
    hora_inicio, hora_fin, canal_reserva, cancelado_por_usu, motivo_cancelacion,
    checkin_por_usu, atendida_por_usu ON public.citas
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_cit');

-- 15. BLOQUEOS: marcar citas confirmadas afectadas y avisar para reprogramar (RF-11, RF-12)
CREATE OR REPLACE FUNCTION public.procesar_bloqueo_agenda() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    PERFORM set_config('app.cod_usu',NEW.cod_usu_admin,true);
    UPDATE public.citas c
       SET estado='Afectada/Pendiente Reprogramación'
     WHERE c.cod_med=NEW.cod_med AND c.fecha=NEW.fecha_bloqueo
       AND c.estado='Confirmada'
       AND (NEW.hora_inicio IS NULL OR
            tsrange(c.fecha+c.hora_inicio,c.fecha+c.hora_fin,'[)') &&
            tsrange(c.fecha+NEW.hora_inicio,c.fecha+NEW.hora_fin,'[)'));
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_procesar_bloqueo_agenda
AFTER INSERT OR UPDATE ON public.bloqueos_agenda
FOR EACH ROW EXECUTE FUNCTION public.procesar_bloqueo_agenda();

-- 16. OPERACIONES DE NEGOCIO: reservar, cancelar, check-in, atender, derivar y sancionar.
CREATE OR REPLACE FUNCTION public.reservar_cita(
    p_cod_pac text,
    p_cod_slot text,
    p_cod_actor text,
    p_canal text DEFAULT 'Web',
    p_cod_der text DEFAULT NULL
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_slot public.slots_agenda%ROWTYPE;
    v_rol text; v_pac_usu text; v_cod_cit text; v_med_actor text;
    v_der public.derivaciones%ROWTYPE;
BEGIN
    IF p_cod_der IS NOT NULL THEN p_canal := 'Derivacion'; END IF;
    IF p_canal='Derivacion' AND p_cod_der IS NULL THEN
        RAISE EXCEPTION 'Una reserva por derivación requiere cod_der.';
    END IF;
    PERFORM set_config('app.cod_usu',p_cod_actor,true);
    SELECT u.cod_rol INTO v_rol FROM public.usuarios u WHERE u.cod_usu=p_cod_actor AND u.estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'El actor no existe o está inactivo.'; END IF;
    SELECT cod_usu INTO v_pac_usu FROM public.pacientes WHERE cod_pac=p_cod_pac;
    IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente.'; END IF;
    IF p_canal='Web' AND v_pac_usu <> p_cod_actor THEN
        RAISE EXCEPTION 'Una reserva web solo puede realizarse para la cuenta autenticada.';
    ELSIF p_canal='Ventanilla' AND v_rol NOT IN ('ROL002','ROL004') THEN
        RAISE EXCEPTION 'Solo secretaría o administración pueden reservar por ventanilla.';
    ELSIF p_canal NOT IN ('Web','Ventanilla','Derivacion','Admin') THEN
        RAISE EXCEPTION 'Canal de reserva no válido.';
    ELSIF p_canal IN ('Derivacion','Admin') AND v_rol <> 'ROL004'
          AND NOT (p_canal='Derivacion' AND v_rol='ROL003') THEN
        RAISE EXCEPTION 'El actor no está autorizado para ese canal de reserva.';
    END IF;
    SELECT * INTO v_slot FROM public.slots_agenda WHERE cod_slot=p_cod_slot FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Slot no encontrado.'; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.v_agenda_disponibilidad
                   WHERE cod_slot=p_cod_slot AND estado_disponibilidad='Disponible') THEN
        RAISE EXCEPTION 'El slot ya no está disponible. Actualice la agenda y seleccione otro horario.';
    END IF;
    IF p_cod_der IS NOT NULL THEN
        SELECT * INTO v_der FROM public.derivaciones
        WHERE cod_der=p_cod_der AND estado='Pendiente' FOR UPDATE;
        IF NOT FOUND THEN RAISE EXCEPTION 'La derivación no existe o ya fue agendada.'; END IF;
        IF v_rol='ROL003' THEN
            SELECT cod_med INTO v_med_actor FROM public.medicos WHERE cod_usu=p_cod_actor AND estado='Activo';
            IF v_med_actor IS NULL OR v_der.cod_med_emisor <> v_med_actor THEN
                RAISE EXCEPTION 'Solo el médico emisor puede agendar desde su derivación.';
            END IF;
        END IF;
        IF v_der.cod_esp_destino <> v_slot.cod_esp THEN
            RAISE EXCEPTION 'El slot no corresponde a la especialidad destino de la derivación.';
        END IF;
        IF v_der.cod_med_destino IS NOT NULL AND v_der.cod_med_destino <> v_slot.cod_med THEN
            RAISE EXCEPTION 'La derivación exige un médico de destino específico.';
        END IF;
        IF (SELECT cod_pac FROM public.citas WHERE cod_cit=v_der.cod_cit_origen) <> p_cod_pac THEN
            RAISE EXCEPTION 'La cita hija debe pertenecer al paciente de la cita de origen.';
        END IF;
        p_canal := 'Derivacion';
    END IF;
    BEGIN
        INSERT INTO public.citas(cod_pac,cod_slot,estado,canal_reserva,cod_sec,creado_por_usu,cod_cit_padre)
        VALUES (p_cod_pac,p_cod_slot,'Confirmada',p_canal,
                (SELECT s.cod_sec FROM public.secretarias s WHERE s.cod_usu=p_cod_actor),
                p_cod_actor, CASE WHEN p_cod_der IS NULL THEN NULL ELSE v_der.cod_cit_origen END)
        RETURNING cod_cit INTO v_cod_cit;
    EXCEPTION WHEN exclusion_violation OR unique_violation THEN
        RAISE EXCEPTION 'No se pudo reservar: el médico, paciente o cupo ya se solapó con otra reserva. Actualice la agenda.';
    END;
    IF p_cod_der IS NOT NULL THEN
        UPDATE public.derivaciones SET cod_cit_hija=v_cod_cit, estado='Agendada' WHERE cod_der=p_cod_der;
    END IF;
    RETURN v_cod_cit;
END;
$$;

CREATE OR REPLACE FUNCTION public.cancelar_cita(
    p_cod_cit text, p_cod_actor text, p_motivo text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cita public.citas%ROWTYPE; v_rol text; v_pac_usu text;
BEGIN
    PERFORM set_config('app.cod_usu',p_cod_actor,true);
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=p_cod_cit FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Cita inexistente.'; END IF;
    IF v_cita.estado IN ('Cancelada','Reprogramada','Atendida','No Asistió') THEN
        RAISE EXCEPTION 'La cita no puede cancelarse desde el estado %.',v_cita.estado;
    END IF;
    SELECT cod_rol INTO v_rol FROM public.usuarios WHERE cod_usu=p_cod_actor AND estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'Actor no encontrado o inactivo.'; END IF;
    SELECT cod_usu INTO v_pac_usu FROM public.pacientes WHERE cod_pac=v_cita.cod_pac;
    IF NOT FOUND THEN RAISE EXCEPTION 'El perfil del paciente no existe.'; END IF;
    IF p_cod_actor <> v_pac_usu AND v_rol NOT IN ('ROL002','ROL004') THEN
        RAISE EXCEPTION 'Solo el paciente propietario, secretaría o administración puede cancelar.';
    END IF;
    UPDATE public.citas SET estado='Cancelada', cancelado_por_usu=p_cod_actor,
       motivo_cancelacion=COALESCE(NULLIF(trim(p_motivo),''),'Sin motivo especificado'), cancelado_en=now()
    WHERE cod_cit=p_cod_cit;
    RETURN true;
END;
$$;

CREATE OR REPLACE FUNCTION public.reprogramar_cita(
    p_cod_cit text, p_cod_slot_nuevo text, p_cod_actor text, p_motivo text
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_cita public.citas%ROWTYPE; v_rol text; v_pac_usu text;
    v_canal text; v_nueva_cita text;
BEGIN
    PERFORM set_config('app.cod_usu',p_cod_actor,true);
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=p_cod_cit FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Cita de origen inexistente.'; END IF;
    IF v_cita.estado NOT IN ('Confirmada','Afectada/Pendiente Reprogramación') THEN
        RAISE EXCEPTION 'Solo se puede reprogramar una cita confirmada o afectada.';
    END IF;
    SELECT u.cod_rol INTO v_rol FROM public.usuarios u
    WHERE u.cod_usu=p_cod_actor AND u.estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'Actor inexistente o inactivo.'; END IF;
    SELECT p.cod_usu INTO v_pac_usu FROM public.pacientes p WHERE p.cod_pac=v_cita.cod_pac;
    IF NOT FOUND THEN RAISE EXCEPTION 'El paciente de la cita no tiene perfil.'; END IF;
    IF p_cod_actor=v_pac_usu THEN
        v_canal := 'Web';
    ELSIF v_rol='ROL002' THEN
        v_canal := 'Ventanilla';
    ELSIF v_rol='ROL004' THEN
        v_canal := 'Admin';
    ELSE
        RAISE EXCEPTION 'Solo el paciente propietario, secretaría o administración puede reprogramar.';
    END IF;

    -- Se marca la cita anterior y se crea la nueva en la misma transacción.
    -- Si la nueva reserva falla, PostgreSQL revierte también este cambio.
    UPDATE public.citas SET estado='Reprogramada', motivo_reprogramacion=COALESCE(NULLIF(trim(p_motivo),''),'Reprogramación de cita')
    WHERE cod_cit=p_cod_cit;
    v_nueva_cita := public.reservar_cita(v_cita.cod_pac,p_cod_slot_nuevo,p_cod_actor,v_canal,NULL);
    UPDATE public.citas SET cod_cit_padre=p_cod_cit WHERE cod_cit=v_nueva_cita;
    RETURN v_nueva_cita;
END;
$$;

CREATE OR REPLACE FUNCTION public.registrar_checkin(p_token_qr text, p_cod_sec text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_secret bytea; v_payload text; v_parts text[]; v_firma bytea;
        v_cita public.citas%ROWTYPE; v_usuario text;
BEGIN
    SELECT s.cod_usu INTO v_usuario FROM public.secretarias s
    JOIN public.usuarios u ON u.cod_usu=s.cod_usu
    WHERE s.cod_sec=p_cod_sec AND s.estado='Activo' AND u.estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'Secretaría no válida o inactiva.'; END IF;
    PERFORM set_config('app.cod_usu',v_usuario,true);
    SELECT secreto INTO v_secret FROM public.configuracion_seguridad WHERE nombre='QR_HMAC';
    IF p_token_qr IS NULL OR position('.' in p_token_qr)=0 THEN RAISE EXCEPTION 'QR mal formado.'; END IF;
    BEGIN
        v_payload := convert_from(decode(split_part(p_token_qr,'.',1),'base64'),'UTF8');
        v_firma := decode(split_part(p_token_qr,'.',2),'hex');
    EXCEPTION WHEN OTHERS THEN RAISE EXCEPTION 'QR mal formado.'; END;
    IF hmac(convert_to(v_payload,'UTF8'),v_secret,'sha256') <> v_firma THEN
        RAISE EXCEPTION 'La firma del QR no es válida.';
    END IF;
    v_parts := string_to_array(v_payload,'|');
    IF array_length(v_parts,1) <> 3 THEN RAISE EXCEPTION 'Contenido QR inválido.'; END IF;
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=v_parts[1] AND token_qr=p_token_qr FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'El QR no corresponde a una cita registrada.'; END IF;
    IF v_parts[2]::date <> v_cita.fecha OR v_cita.fecha <> public.fecha_clinica() THEN
        RAISE EXCEPTION 'El QR no corresponde a una cita del día de hoy.';
    END IF;
    IF v_cita.estado <> 'Confirmada' THEN
        RAISE EXCEPTION 'La cita no es elegible para check-in (estado actual: %).',v_cita.estado;
    END IF;
    UPDATE public.citas SET estado='En Espera', hora_llegada_checkin=now(), checkin_por_usu=v_usuario
    WHERE cod_cit=v_cita.cod_cit;
    RETURN v_cita.cod_cit;
END;
$$;

CREATE OR REPLACE FUNCTION public.marcar_cita_atendida(p_cod_cit text, p_cod_usu_doctor text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cita public.citas%ROWTYPE; v_cod_med text;
BEGIN
    PERFORM set_config('app.cod_usu',p_cod_usu_doctor,true);
    SELECT m.cod_med INTO v_cod_med FROM public.medicos m
    JOIN public.usuarios u ON u.cod_usu=m.cod_usu
    WHERE m.cod_usu=p_cod_usu_doctor AND m.estado='Activo' AND u.estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'El usuario no es un médico activo.'; END IF;
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=p_cod_cit FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Cita inexistente.'; END IF;
    IF v_cita.cod_med <> v_cod_med THEN RAISE EXCEPTION 'El médico no es responsable de esta cita.'; END IF;
    IF v_cita.estado <> 'En Espera' THEN RAISE EXCEPTION 'Solo una cita en espera puede marcarse atendida.'; END IF;
    UPDATE public.citas SET estado='Atendida', fecha_atencion=now(), atendida_por_usu=p_cod_usu_doctor
    WHERE cod_cit=p_cod_cit;
    RETURN true;
END;
$$;

CREATE OR REPLACE FUNCTION public.crear_derivacion(
    p_cod_cit_origen text, p_cod_usu_doctor text, p_cod_esp_destino text,
    p_motivo text, p_cod_med_destino text DEFAULT NULL
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_med text; v_cita public.citas%ROWTYPE; v_cod_der text;
BEGIN
    PERFORM set_config('app.cod_usu',p_cod_usu_doctor,true);
    SELECT cod_med INTO v_med FROM public.medicos WHERE cod_usu=p_cod_usu_doctor AND estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'El actor no es médico activo.'; END IF;
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=p_cod_cit_origen FOR UPDATE;
    IF NOT FOUND OR v_cita.cod_med <> v_med OR v_cita.estado <> 'Atendida' THEN
        RAISE EXCEPTION 'La derivación debe originarse en una cita atendida por el médico autenticado.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.especialidades WHERE cod_esp=p_cod_esp_destino AND estado='Activo') THEN
        RAISE EXCEPTION 'La especialidad destino no está activa.';
    END IF;
    IF p_cod_med_destino IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM public.medico_especialidades
        WHERE cod_med=p_cod_med_destino AND cod_esp=p_cod_esp_destino AND estado='Activo'
    ) THEN RAISE EXCEPTION 'El médico destino no tiene esa especialidad habilitada.'; END IF;
    INSERT INTO public.derivaciones(cod_cit_origen,cod_med_emisor,cod_esp_destino,cod_med_destino,motivo_derivacion)
    VALUES (p_cod_cit_origen,v_med,p_cod_esp_destino,p_cod_med_destino,trim(p_motivo))
    RETURNING cod_der INTO v_cod_der;
    RETURN v_cod_der;
END;
$$;

CREATE OR REPLACE FUNCTION public.registrar_sancion(
    p_cod_pac text, p_cod_admin text, p_motivo text, p_dias integer
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cod_inc text;
BEGIN
    PERFORM set_config('app.cod_usu',p_cod_admin,true);
    IF NOT EXISTS (SELECT 1 FROM public.usuarios WHERE cod_usu=p_cod_admin AND cod_rol='ROL004' AND estado='Activo') THEN
        RAISE EXCEPTION 'Solo un administrador activo puede aplicar sanciones.';
    END IF;
    IF p_dias IS NULL OR p_dias < 1 OR p_dias > 365 THEN RAISE EXCEPTION 'La sanción debe durar entre 1 y 365 días.'; END IF;
    INSERT INTO public.incidencias_sanciones(cod_pac,registrado_por_usu,tipo_incidencia,motivo,fecha_fin_sancion)
    VALUES (p_cod_pac,p_cod_admin,'SANCION_BLOQUEO',trim(p_motivo),now()+make_interval(days=>p_dias))
    RETURNING cod_inc INTO v_cod_inc;
    RETURN v_cod_inc;
END;
$$;

CREATE OR REPLACE FUNCTION public.aplicar_sancion_paciente() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NEW.tipo_incidencia='SANCION_BLOQUEO' THEN
        UPDATE public.pacientes p SET estado_cuenta='Bloqueado_Temporal',
            bloqueado_hasta=GREATEST(COALESCE(p.bloqueado_hasta,now()),NEW.fecha_fin_sancion)
        WHERE p.cod_pac=NEW.cod_pac;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_aplicar_sancion_paciente
AFTER INSERT ON public.incidencias_sanciones
FOR EACH ROW EXECUTE FUNCTION public.aplicar_sancion_paciente();

CREATE OR REPLACE FUNCTION public.desbloquear_sanciones_vencidas() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cuantos integer;
BEGIN
    UPDATE public.pacientes SET estado_cuenta='Activo', bloqueado_hasta=NULL
    WHERE estado_cuenta='Bloqueado_Temporal' AND bloqueado_hasta <= now();
    GET DIAGNOSTICS v_cuantos = ROW_COUNT;
    RETURN v_cuantos;
END;
$$;

CREATE OR REPLACE FUNCTION public.marcar_inasistencias_vencidas() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cuantos integer;
BEGIN
    UPDATE public.citas SET estado='No Asistió'
    WHERE estado='Confirmada' AND hora_llegada_checkin IS NULL
      AND (fecha + hora_fin) < public.ahora_clinica();
    GET DIAGNOSTICS v_cuantos = ROW_COUNT;
    RETURN v_cuantos;
END;
$$;

-- 17. WORKER DE NOTIFICACIONES: la app/worker externo reclama y despacha mensajes.
CREATE OR REPLACE FUNCTION public.tomar_notificaciones(p_limite integer DEFAULT 50)
RETURNS TABLE(id_notificacion bigint, usuario text, cita text, canal_salida text,
              tipo text, destino text, mensaje text, numero_intento integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF p_limite < 1 OR p_limite > 500 THEN RAISE EXCEPTION 'El límite debe estar entre 1 y 500.'; END IF;
    -- Recupera mensajes cuyo worker cayó sin cerrar la entrega.
    UPDATE public.notificaciones
       SET estado=CASE WHEN intentos >= max_intentos THEN 'Fallida' ELSE 'Pendiente' END,
           en_proceso_desde=NULL,
           fecha_programada=LEAST(fecha_programada,now()),
           ultimo_error=COALESCE(ultimo_error,'Worker sin respuesta por más de 10 minutos')
     WHERE estado='EnProceso' AND en_proceso_desde < now()-interval '10 minutes';
    RETURN QUERY
    WITH lote AS (
        SELECT n.cod_not FROM public.notificaciones n
        WHERE n.estado='Pendiente' AND n.fecha_programada <= now()
        ORDER BY n.fecha_programada,n.cod_not
        FOR UPDATE SKIP LOCKED LIMIT p_limite
    ), actualizadas AS (
        UPDATE public.notificaciones n
        SET estado='EnProceso', intentos=n.intentos+1, en_proceso_desde=now()
        FROM lote l WHERE n.cod_not=l.cod_not
        RETURNING n.cod_not,n.cod_usu,n.cod_cit,n.canal,n.tipo_alerta,n.destinatario,n.mensaje,n.intentos
    )
    SELECT a.cod_not,a.cod_usu,a.cod_cit,a.canal,a.tipo_alerta,a.destinatario,a.mensaje,a.intentos
    FROM actualizadas a;
END;
$$;

CREATE OR REPLACE FUNCTION public.finalizar_notificacion(
    p_cod_not bigint, p_exito boolean, p_error text DEFAULT NULL
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_n public.notificaciones%ROWTYPE;
BEGIN
    SELECT * INTO v_n FROM public.notificaciones WHERE cod_not=p_cod_not FOR UPDATE;
    IF NOT FOUND OR v_n.estado <> 'EnProceso' THEN RETURN false; END IF;
    IF p_exito THEN
        UPDATE public.notificaciones SET estado='Enviada', enviado_en=now(), en_proceso_desde=NULL, ultimo_error=NULL WHERE cod_not=p_cod_not;
    ELSIF v_n.intentos < v_n.max_intentos THEN
        UPDATE public.notificaciones SET estado='Pendiente', en_proceso_desde=NULL, ultimo_error=left(COALESCE(p_error,'Fallo de entrega'),2000),
            fecha_programada=now()+make_interval(mins=>LEAST(60,power(2,LEAST(v_n.intentos,5))::integer))
        WHERE cod_not=p_cod_not;
    ELSE
        UPDATE public.notificaciones SET estado='Fallida', en_proceso_desde=NULL, ultimo_error=left(COALESCE(p_error,'Fallo de entrega'),2000)
        WHERE cod_not=p_cod_not;
    END IF;
    RETURN true;
END;
$$;

-- 18. VALIDACIÓN Y TRAZABILIDAD DE ANÁLISIS / HISTORIAL
CREATE OR REPLACE FUNCTION public.validar_analisis() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cod_med text;
BEGIN
    SELECT cod_med INTO v_cod_med FROM public.medicos WHERE cod_usu=NEW.solicitado_por_usu AND estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'Solo un médico activo puede solicitar análisis.'; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.citas WHERE cod_cit=NEW.cod_cit AND cod_med=v_cod_med
                   AND estado IN ('En Espera','Atendida')) THEN
        RAISE EXCEPTION 'El análisis debe asociarse a una cita del médico solicitante que esté en atención o atendida.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_validar_analisis
BEFORE INSERT OR UPDATE OF cod_cit, solicitado_por_usu ON public.analisis
FOR EACH ROW EXECUTE FUNCTION public.validar_analisis();

CREATE OR REPLACE VIEW public.v_historial_citas AS
SELECT c.cod_cit,c.cod_pac,c.fecha,c.hora_inicio,c.hora_fin,c.estado,c.canal_reserva,
       e.cod_esp,e.nombre_especialidad,m.cod_med,ud.nombre_completo AS nombre_medico,
       c.creado_en,c.cancelado_en,c.hora_llegada_checkin,c.fecha_atencion
FROM public.citas c
JOIN public.especialidades e ON e.cod_esp=c.cod_esp
JOIN public.medicos m ON m.cod_med=c.cod_med
JOIN public.usuarios ud ON ud.cod_usu=m.cod_usu;

-- 19. BITÁCORA Y REGISTROS HISTÓRICOS INMUTABLES
CREATE TRIGGER trg_auditar_usuarios
AFTER INSERT OR UPDATE OR DELETE ON public.usuarios
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_usu');
CREATE TRIGGER trg_auditar_pacientes
AFTER INSERT OR UPDATE OR DELETE ON public.pacientes
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_pac');
CREATE TRIGGER trg_auditar_secretarias
AFTER INSERT OR UPDATE OR DELETE ON public.secretarias
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_sec');
CREATE TRIGGER trg_auditar_medicos
AFTER INSERT OR UPDATE OR DELETE ON public.medicos
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_med');
CREATE TRIGGER trg_auditar_especialidades
AFTER INSERT OR UPDATE OR DELETE ON public.especialidades
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_esp');
CREATE TRIGGER trg_auditar_medico_especialidades
AFTER INSERT OR UPDATE OR DELETE ON public.medico_especialidades
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_med');
CREATE TRIGGER trg_auditar_horarios
AFTER INSERT OR UPDATE OR DELETE ON public.horarios
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_hor');
CREATE TRIGGER trg_auditar_bloqueos
AFTER INSERT OR UPDATE OR DELETE ON public.bloqueos_agenda
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_blo');
CREATE TRIGGER trg_auditar_derivaciones
AFTER INSERT OR UPDATE OR DELETE ON public.derivaciones
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_der');
CREATE TRIGGER trg_auditar_analisis
AFTER INSERT OR UPDATE OR DELETE ON public.analisis
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_ana');

CREATE TRIGGER trg_auditar_incidencias
AFTER INSERT ON public.incidencias_sanciones
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_inc');
CREATE TRIGGER trg_incidencias_inmutables
BEFORE UPDATE OR DELETE ON public.incidencias_sanciones
FOR EACH ROW EXECUTE FUNCTION public.impedir_edicion_historial();
CREATE TRIGGER trg_bitacora_inmutable
BEFORE UPDATE OR DELETE ON public.bitacora
FOR EACH ROW EXECUTE FUNCTION public.impedir_edicion_historial();
CREATE OR REPLACE FUNCTION public.impedir_borrado_citas() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Las citas forman parte del historial y no se borran. Cancele o marque la cita según corresponda.';
END;
$$;
CREATE TRIGGER trg_citas_no_delete
BEFORE DELETE ON public.citas
FOR EACH ROW EXECUTE FUNCTION public.impedir_borrado_citas();

-- 20. NOTAS DE OPERACIÓN / COMPROBACIÓN DE INSTALACIÓN
-- Crear usuarios de personal mediante una transacción del backend:
--   1) INSERT en usuarios con rol ROL002 / ROL003 / ROL004 y hash seguro.
--   2) INSERT en secretarias o medicos y en medico_especialidades según corresponda.
-- Crear horario: INSERT en horarios con dia_semana ISO, hora inicio/fin y pausa opcional.
-- Generar slots próximos: SELECT public.generar_slots_agenda();
-- Consultar cupos: SELECT * FROM public.v_agenda_disponibilidad
--                  WHERE estado_disponibilidad='Disponible' ORDER BY fecha,hora_inicio;
-- Reservar: SELECT public.reservar_cita('<COD_PAC>','<COD_SLOT>','<COD_USU_ACTOR>','Web',NULL);
-- Cancelar: SELECT public.cancelar_cita('<COD_CIT>','<COD_USU_ACTOR>','Motivo');
-- Reprogramar: SELECT public.reprogramar_cita('<COD_CIT>','<NUEVO_COD_SLOT>','<COD_USU_ACTOR>','Motivo');
-- OTP: SELECT public.crear_otp('<COD_USU>'); enviar el retorno por SMS;
--      SELECT public.validar_otp('<COD_USU>','123456');
-- QR check-in: SELECT public.registrar_checkin('<TOKEN_QR>','<COD_SEC>');
-- Cierre médico: SELECT public.marcar_cita_atendida('<COD_CIT>','<COD_USU_DOCTOR>');
-- Derivación: SELECT public.crear_derivacion('<COD_CIT_ATENDIDA>','<COD_USU_DOCTOR>',
--                 '<COD_ESP_DESTINO>','Motivo',NULL);
-- Sanción: SELECT public.registrar_sancion('<COD_PAC>','<COD_USU_ADMIN>','Motivo',7);
-- Procesos programados (cron/queue worker):
--   SELECT public.generar_slots_agenda();
--   SELECT public.marcar_inasistencias_vencidas();
--   SELECT public.desbloquear_sanciones_vencidas();
--   SELECT * FROM public.tomar_notificaciones(50); -- enviar cada mensaje y llamar finalizar_notificacion
-- Para notificaciones WhatsApp, configurar un proveedor externo y crear/encolar canal WhatsApp según consentimiento.

