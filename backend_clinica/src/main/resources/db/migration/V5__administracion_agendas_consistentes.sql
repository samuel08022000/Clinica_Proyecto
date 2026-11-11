-- Conserva cada versión de un slot referenciado al cambiar horario o duración.
ALTER TABLE public.slots_agenda
    DROP CONSTRAINT slots_agenda_cod_hor_cod_esp_inicio_at_key,
    DROP CONSTRAINT slots_agenda_cod_med_cod_esp_inicio_at_key,
    ADD CONSTRAINT uq_slots_agenda_version UNIQUE (cod_hor, cod_esp, inicio_at, fin_at);

-- La versión anterior no registra la relación causal entre bloqueos y citas.
ALTER TABLE public.bloqueos_agenda ADD COLUMN trazabilidad_completa boolean NOT NULL DEFAULT false;
CREATE TABLE public.bloqueos_citas_afectadas (
    cod_blo text NOT NULL REFERENCES public.bloqueos_agenda(cod_blo) ON DELETE RESTRICT,
    cod_cit text NOT NULL REFERENCES public.citas(cod_cit) ON DELETE RESTRICT,
    PRIMARY KEY (cod_blo, cod_cit)
);
CREATE TRIGGER trg_bloqueos_citas_inmutables
BEFORE UPDATE OR DELETE ON public.bloqueos_citas_afectadas
FOR EACH ROW EXECUTE FUNCTION public.impedir_edicion_historial();
CREATE TRIGGER trg_auditar_bloqueos_citas
AFTER INSERT ON public.bloqueos_citas_afectadas
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_cit');

CREATE FUNCTION public.bloquear_agenda_medico(p_cod_med text) RETURNS void
LANGUAGE sql VOLATILE AS $$
    SELECT pg_advisory_xact_lock(hashtextextended('agenda-medico:' || p_cod_med, 0));
$$;

CREATE OR REPLACE FUNCTION public.validar_horario_semanal() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE v_afectadas bigint;
BEGIN
    PERFORM public.bloquear_agenda_medico(NEW.cod_med);
    IF TG_OP = 'UPDATE' AND NEW.cod_med IS DISTINCT FROM OLD.cod_med THEN
        RAISE EXCEPTION 'El médico de un horario no puede modificarse.';
    END IF;
    IF NEW.estado = 'Activo' AND EXISTS (
        SELECT 1 FROM public.horarios h
        WHERE h.cod_med = NEW.cod_med AND h.dia_semana = NEW.dia_semana
          AND h.estado = 'Activo' AND h.secuencial IS DISTINCT FROM NEW.secuencial
          AND NEW.hora_inicio < h.hora_fin AND NEW.hora_fin > h.hora_inicio
    ) THEN
        RAISE EXCEPTION 'El horario se superpone con otro rango activo del mismo médico en ese día.' USING ERRCODE = 'P4090';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        SELECT count(*) INTO v_afectadas
        FROM public.citas c JOIN public.slots_agenda s ON s.cod_slot = c.cod_slot
        WHERE s.cod_hor = OLD.cod_hor
          AND c.estado IN ('Confirmada','En Espera','Afectada/Pendiente Reprogramación')
          AND c.fecha + c.hora_fin > public.ahora_clinica()
          AND (NEW.estado <> 'Activo' OR extract(isodow FROM c.fecha)::integer <> NEW.dia_semana
            OR c.hora_inicio < NEW.hora_inicio OR c.hora_fin > NEW.hora_fin
            OR mod(extract(epoch FROM (s.inicio_at - (s.fecha + NEW.hora_inicio))),
                   extract(epoch FROM (s.fin_at - s.inicio_at))) <> 0
            OR (NEW.pausa_inicio IS NOT NULL AND
                tsrange(s.inicio_at,s.fin_at,'[)') &&
                tsrange(c.fecha+NEW.pausa_inicio,c.fecha+NEW.pausa_fin,'[)')));
        IF v_afectadas > 0 THEN
            RAISE EXCEPTION 'El cambio dejaría % citas vigentes incompatibles con el horario. Resuelva sus reservas antes de editarlo.',
                v_afectadas USING ERRCODE = 'P4090';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.validar_duracion_agenda() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE v_med text; v_afectadas bigint;
BEGIN
    IF NEW.duracion_consulta_min IS NOT DISTINCT FROM OLD.duracion_consulta_min THEN RETURN NEW; END IF;
    FOR v_med IN
        SELECT m.cod_med FROM public.medicos m
        WHERE (TG_TABLE_NAME = 'medicos' AND m.cod_med = to_jsonb(NEW)->>'cod_med')
           OR (TG_TABLE_NAME = 'medico_especialidades' AND m.cod_med = to_jsonb(NEW)->>'cod_med')
           OR (TG_TABLE_NAME = 'especialidades' AND EXISTS (
               SELECT 1 FROM public.medico_especialidades me
               WHERE me.cod_med = m.cod_med AND me.cod_esp = to_jsonb(NEW)->>'cod_esp'))
        ORDER BY m.cod_med
    LOOP
        PERFORM public.bloquear_agenda_medico(v_med);
        SELECT count(*) INTO v_afectadas
        FROM public.citas c
        JOIN public.medicos m ON m.cod_med = c.cod_med
        JOIN public.medico_especialidades me ON me.cod_med = c.cod_med AND me.cod_esp = c.cod_esp
        JOIN public.especialidades e ON e.cod_esp = c.cod_esp
        WHERE c.cod_med = v_med AND c.fecha+c.hora_fin > public.ahora_clinica()
          AND c.estado IN ('Confirmada','En Espera','Afectada/Pendiente Reprogramación')
          AND (TG_TABLE_NAME = 'medicos' OR c.cod_esp = to_jsonb(NEW)->>'cod_esp')
          AND c.hora_fin-c.hora_inicio <> make_interval(mins => CASE TG_TABLE_NAME
            WHEN 'medicos' THEN COALESCE(me.duracion_consulta_min,NEW.duracion_consulta_min,e.duracion_consulta_min)
            WHEN 'medico_especialidades' THEN COALESCE(NEW.duracion_consulta_min,m.duracion_consulta_min,e.duracion_consulta_min)
            ELSE COALESCE(me.duracion_consulta_min,m.duracion_consulta_min,NEW.duracion_consulta_min) END);
        IF v_afectadas > 0 THEN
            RAISE EXCEPTION 'La duración propuesta es incompatible con % citas vigentes. Resuelva sus reservas antes de cambiarla.',
                v_afectadas USING ERRCODE = 'P4090';
        END IF;
    END LOOP;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_validar_duracion_medico BEFORE UPDATE OF duracion_consulta_min ON public.medicos
FOR EACH ROW EXECUTE FUNCTION public.validar_duracion_agenda();
CREATE TRIGGER trg_validar_duracion_vinculo BEFORE UPDATE OF duracion_consulta_min ON public.medico_especialidades
FOR EACH ROW EXECUTE FUNCTION public.validar_duracion_agenda();
CREATE TRIGGER trg_validar_duracion_especialidad BEFORE UPDATE OF duracion_consulta_min ON public.especialidades
FOR EACH ROW EXECUTE FUNCTION public.validar_duracion_agenda();

CREATE FUNCTION public.serializar_bloqueo_agenda() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM public.bloquear_agenda_medico(NEW.cod_med);
    IF TG_OP = 'INSERT' THEN NEW.trazabilidad_completa := true; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_serializar_bloqueo BEFORE INSERT OR UPDATE ON public.bloqueos_agenda
FOR EACH ROW EXECUTE FUNCTION public.serializar_bloqueo_agenda();

CREATE OR REPLACE FUNCTION public.procesar_bloqueo_agenda() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    PERFORM set_config('app.cod_usu',NEW.cod_usu_admin,true);
    WITH afectadas AS (
        UPDATE public.citas c SET estado = 'Afectada/Pendiente Reprogramación'
        WHERE c.cod_med = NEW.cod_med AND c.fecha = NEW.fecha_bloqueo AND c.estado = 'Confirmada'
          AND (NEW.hora_inicio IS NULL OR
              tsrange(c.fecha+c.hora_inicio,c.fecha+c.hora_fin,'[)') &&
              tsrange(c.fecha+NEW.hora_inicio,c.fecha+NEW.hora_fin,'[)'))
        RETURNING c.cod_cit
    )
    INSERT INTO public.bloqueos_citas_afectadas(cod_blo,cod_cit)
    SELECT NEW.cod_blo,cod_cit FROM afectadas ON CONFLICT DO NOTHING;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.serializar_reserva_agenda() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE v_slot public.slots_agenda%ROWTYPE;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NOT (NEW.cod_slot IS DISTINCT FROM OLD.cod_slot
            OR (OLD.estado IN ('Cancelada','Reprogramada') AND NEW.estado NOT IN ('Cancelada','Reprogramada'))) THEN
            RETURN NEW;
        END IF;
    END IF;
    SELECT * INTO v_slot FROM public.slots_agenda WHERE cod_slot = NEW.cod_slot;
    IF NOT FOUND THEN RETURN NEW; END IF;
    PERFORM public.bloquear_agenda_medico(v_slot.cod_med);
    IF NOT EXISTS (SELECT 1 FROM public.medicos m JOIN public.usuarios u ON u.cod_usu=m.cod_usu
        JOIN public.roles r ON r.cod_rol=u.cod_rol
        WHERE m.cod_med=v_slot.cod_med AND m.estado='Activo' AND u.estado='Activo'
          AND u.cod_rol='ROL003' AND r.estado='Activo') THEN
        RAISE EXCEPTION 'El profesional no está habilitado para nuevas reservas.';
    END IF;
    IF EXISTS (SELECT 1 FROM public.horarios h WHERE h.cod_hor=v_slot.cod_hor
        AND mod(extract(epoch FROM (v_slot.inicio_at-(v_slot.fecha+h.hora_inicio))),
            extract(epoch FROM (v_slot.fin_at-v_slot.inicio_at))) <> 0) THEN
        RAISE EXCEPTION 'El slot ya no coincide con el inicio de la agenda vigente.';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_00_serializar_reserva BEFORE INSERT OR UPDATE ON public.citas
FOR EACH ROW EXECUTE FUNCTION public.serializar_reserva_agenda();


-- Misma reserva central, con exclusión mutua antes de bloquear el slot.
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
    PERFORM public.bloquear_agenda_medico((SELECT cod_med FROM public.slots_agenda WHERE cod_slot=p_cod_slot));
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

CREATE OR REPLACE VIEW public.v_agenda_disponibilidad AS
SELECT s.cod_slot, s.cod_med, m.cod_usu AS cod_usu_medico,
       u.nombre_completo AS nombre_medico,
       s.cod_esp, e.nombre_especialidad,
       s.fecha, s.hora_inicio, s.hora_fin,
       CASE
         WHEN s.inicio_at <= public.ahora_clinica() THEN 'Expirado'
         WHEN u.estado <> 'Activo' OR u.cod_rol <> 'ROL003' OR r.estado <> 'Activo'
              OR m.estado <> 'Activo' OR e.estado <> 'Activo' OR h.estado <> 'Activo'
              OR me.estado <> 'Activo' THEN 'No Disponible'
         WHEN mod(extract(epoch FROM (s.inicio_at-(s.fecha+h.hora_inicio))),
                  COALESCE(me.duracion_consulta_min,m.duracion_consulta_min,e.duracion_consulta_min)*60) <> 0
              OR h.dia_semana <> extract(isodow FROM s.fecha)::integer
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
JOIN public.roles r ON r.cod_rol=u.cod_rol
JOIN public.especialidades e ON e.cod_esp=s.cod_esp
JOIN public.horarios h ON h.cod_hor=s.cod_hor
JOIN public.medico_especialidades me ON me.cod_med=s.cod_med AND me.cod_esp=s.cod_esp
WHERE s.fecha BETWEEN public.fecha_clinica() AND public.fecha_clinica()+14;
