-- Lectura de vigencia sin escrituras ni espera al scheduler. Conserva bloqueos e incidencias anteriores.
CREATE INDEX ix_incidencias_sancion_paciente_fin
ON public.incidencias_sanciones(cod_pac,fecha_fin_sancion) WHERE tipo_incidencia='SANCION_BLOQUEO';

CREATE FUNCTION public.estado_reserva_paciente(p_cod_pac text) RETURNS text
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT CASE WHEN p.estado_cuenta='Inactivo' THEN 'Inactivo'
        WHEN p.bloqueado_hasta>clock_timestamp() OR EXISTS (
            SELECT 1 FROM public.incidencias_sanciones i WHERE i.cod_pac=p.cod_pac
              AND i.tipo_incidencia='SANCION_BLOQUEO' AND i.fecha_fin_sancion>clock_timestamp()
        ) THEN 'Bloqueado_Temporal'
        WHEN p.estado_cuenta='Bloqueado_Temporal' AND p.bloqueado_hasta<=clock_timestamp() THEN 'Activo'
        ELSE p.estado_cuenta END
    FROM public.pacientes p WHERE p.cod_pac=p_cod_pac;
$$;

CREATE OR REPLACE FUNCTION public.registrar_sancion(
    p_cod_pac text,p_cod_admin text,p_motivo text,p_dias integer
) RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_paciente public.pacientes%ROWTYPE; v_inicio timestamptz; v_fin timestamptz; v_cod_inc text;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.usuarios u JOIN public.roles r USING(cod_rol)
        WHERE u.cod_usu=p_cod_admin AND u.cod_rol='ROL004' AND u.estado='Activo' AND r.estado='Activo') THEN
        RAISE EXCEPTION 'Administrador no autorizado.' USING ERRCODE='P4033';
    END IF;
    IF p_dias IS NULL OR p_dias<1 OR p_dias>365 OR p_motivo IS NULL
        OR btrim(p_motivo)='' OR p_motivo !~ '[^[:space:]]' OR char_length(btrim(p_motivo))>2000 THEN
        RAISE EXCEPTION 'Datos de sanción inválidos.' USING ERRCODE='P4003';
    END IF;
    -- reservar_cita también bloquea esta fila antes de decidir la habilitación.
    SELECT * INTO v_paciente FROM public.pacientes WHERE cod_pac=p_cod_pac FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente.' USING ERRCODE='P4043'; END IF;
    IF public.estado_reserva_paciente(p_cod_pac)<>'Activo' THEN
        RAISE EXCEPTION 'Estado incompatible o sanción vigente.' USING ERRCODE='P4093';
    END IF;
    v_inicio := clock_timestamp();
    v_fin := ((v_inicio AT TIME ZONE 'America/La_Paz')+make_interval(days=>p_dias)) AT TIME ZONE 'America/La_Paz';
    PERFORM set_config('app.cod_usu',p_cod_admin,true);
    INSERT INTO public.incidencias_sanciones(cod_pac,registrado_por_usu,tipo_incidencia,motivo,fecha_registro,fecha_fin_sancion)
    VALUES(p_cod_pac,p_cod_admin,'SANCION_BLOQUEO',btrim(p_motivo),v_inicio,v_fin) RETURNING cod_inc INTO v_cod_inc;
    RETURN v_cod_inc;
END;
$$;

CREATE OR REPLACE FUNCTION public.aplicar_sancion_paciente() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_paciente public.pacientes%ROWTYPE;
BEGIN
    IF NEW.tipo_incidencia<>'SANCION_BLOQUEO' THEN RETURN NEW; END IF;
    IF NOT EXISTS (SELECT 1 FROM public.usuarios u JOIN public.roles r USING(cod_rol)
        WHERE u.cod_usu=NEW.registrado_por_usu AND u.cod_rol='ROL004' AND u.estado='Activo' AND r.estado='Activo') THEN
        RAISE EXCEPTION 'Administrador no autorizado.' USING ERRCODE='P4033';
    END IF;
    SELECT * INTO v_paciente FROM public.pacientes WHERE cod_pac=NEW.cod_pac FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente.' USING ERRCODE='P4043'; END IF;
    IF NEW.fecha_fin_sancion IS NULL OR NEW.fecha_fin_sancion<=NEW.fecha_registro
        OR NEW.fecha_fin_sancion<=clock_timestamp() OR btrim(NEW.motivo)=''
        OR NEW.motivo !~ '[^[:space:]]' OR char_length(btrim(NEW.motivo))>2000 THEN
        RAISE EXCEPTION 'Datos de sanción inválidos.' USING ERRCODE='P4003';
    END IF;
    IF v_paciente.estado_cuenta='Inactivo'
        OR (v_paciente.estado_cuenta='Bloqueado_Temporal' AND v_paciente.bloqueado_hasta IS NULL)
        OR v_paciente.bloqueado_hasta>clock_timestamp() OR EXISTS (
            SELECT 1 FROM public.incidencias_sanciones i WHERE i.cod_pac=NEW.cod_pac
                AND i.cod_inc<>NEW.cod_inc AND i.tipo_incidencia='SANCION_BLOQUEO'
                AND i.fecha_fin_sancion>clock_timestamp()
        ) THEN RAISE EXCEPTION 'Estado incompatible o sanción vigente.' USING ERRCODE='P4093'; END IF;
    PERFORM set_config('app.cod_usu',NEW.registrado_por_usu,true);
    UPDATE public.pacientes SET estado_cuenta='Bloqueado_Temporal',
        bloqueado_hasta=GREATEST(COALESCE(bloqueado_hasta,clock_timestamp()),NEW.fecha_fin_sancion)
    WHERE cod_pac=NEW.cod_pac;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.desbloquear_sanciones_vencidas() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cuantos integer; v_actor text := public.actor_actual();
BEGIN
    PERFORM set_config('app.cod_usu','',true);
    UPDATE public.pacientes p SET estado_cuenta='Activo',bloqueado_hasta=NULL
    WHERE p.estado_cuenta='Bloqueado_Temporal' AND p.bloqueado_hasta<=clock_timestamp()
      AND NOT EXISTS(SELECT 1 FROM public.incidencias_sanciones i WHERE i.cod_pac=p.cod_pac
          AND i.tipo_incidencia='SANCION_BLOQUEO' AND i.fecha_fin_sancion>clock_timestamp());
    GET DIAGNOSTICS v_cuantos = ROW_COUNT;
    PERFORM set_config('app.cod_usu',COALESCE(v_actor,''),true);
    RETURN v_cuantos;
END;
$$;

-- Mantiene el único motor de V6. Solo cambia la comprobación de vigencia bajo su bloqueo de paciente.
-- Conserva el motor y los bloqueos de V5. Añade errores tipados y habilitación del paciente.
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
    v_pac public.pacientes%ROWTYPE;
BEGIN
    IF p_cod_der IS NOT NULL THEN p_canal := 'Derivacion'; END IF;
    IF p_canal='Derivacion' AND p_cod_der IS NULL THEN
        RAISE EXCEPTION 'Una reserva por derivación requiere cod_der.';
    END IF;
    PERFORM set_config('app.cod_usu',p_cod_actor,true);
    SELECT u.cod_rol INTO v_rol FROM public.usuarios u WHERE u.cod_usu=p_cod_actor AND u.estado='Activo';
    IF NOT FOUND THEN RAISE EXCEPTION 'El actor no existe o está inactivo.' USING ERRCODE = 'P4030'; END IF;
    IF v_rol='ROL002' AND NOT EXISTS (
        SELECT 1 FROM public.secretarias WHERE cod_usu=p_cod_actor AND estado='Activo'
    ) THEN
        RAISE EXCEPTION 'El perfil de secretaría no está habilitado.' USING ERRCODE = 'P4030';
    END IF;
    SELECT cod_usu INTO v_pac_usu FROM public.pacientes WHERE cod_pac=p_cod_pac;
    IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente.' USING ERRCODE = 'P4040'; END IF;
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
    IF NOT FOUND THEN RAISE EXCEPTION 'Slot no encontrado.' USING ERRCODE = 'P4040'; END IF;
    IF v_slot.fecha < public.fecha_clinica() OR v_slot.fecha > public.fecha_clinica()+14
       OR v_slot.inicio_at <= public.ahora_clinica() THEN
        RAISE EXCEPTION 'El turno debe ser futuro y estar entre hoy y D+14.' USING ERRCODE = 'P4000';
    END IF;
    SELECT * INTO v_pac FROM public.pacientes WHERE cod_pac=p_cod_pac FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Paciente inexistente.' USING ERRCODE = 'P4040'; END IF;
    IF public.estado_reserva_paciente(p_cod_pac) <> 'Activo' OR v_pac.bloqueado_hasta > clock_timestamp()
       OR NOT v_pac.email_verificado OR NOT EXISTS (
           SELECT 1 FROM public.usuarios u JOIN public.roles r ON r.cod_rol=u.cod_rol
           WHERE u.cod_usu=v_pac.cod_usu AND u.cod_rol='ROL001' AND u.estado='Activo' AND r.estado='Activo'
       ) THEN
        RAISE EXCEPTION 'El paciente no está habilitado para nuevas reservas.' USING ERRCODE = 'P4030';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.v_agenda_disponibilidad
                   WHERE cod_slot=p_cod_slot AND estado_disponibilidad='Disponible') THEN
        RAISE EXCEPTION 'El slot ya no está disponible. Actualice la agenda y seleccione otro horario.' USING ERRCODE = 'P4090';
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
        RAISE EXCEPTION 'No se pudo reservar: el médico, paciente o cupo ya se solapó con otra reserva. Actualice la agenda.' USING ERRCODE = 'P4090';
    END;
    IF p_cod_der IS NOT NULL THEN
        UPDATE public.derivaciones SET cod_cit_hija=v_cod_cit, estado='Agendada' WHERE cod_der=p_cod_der;
    END IF;
    RETURN v_cod_cit;
END;
$$;

