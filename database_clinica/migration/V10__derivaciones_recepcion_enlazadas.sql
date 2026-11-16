-- Habilita Secretaría en el motor existente; conserva sus bloqueos y validaciones.
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
          AND NOT (p_canal='Derivacion' AND v_rol IN ('ROL002','ROL003')) THEN
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
        WHERE cod_der=p_cod_der AND estado='Pendiente' AND cod_cit_hija IS NULL FOR UPDATE;
        IF NOT FOUND THEN RAISE EXCEPTION 'La derivación no está disponible.' USING ERRCODE='P4094'; END IF;
        IF v_rol='ROL003' THEN
            SELECT cod_med INTO v_med_actor FROM public.medicos WHERE cod_usu=p_cod_actor AND estado='Activo';
            IF v_med_actor IS NULL OR v_der.cod_med_emisor <> v_med_actor THEN
                RAISE EXCEPTION 'Solo el médico emisor puede agendar desde su derivación.';
            END IF;
        END IF;
        IF v_der.cod_esp_destino <> v_slot.cod_esp THEN
            RAISE EXCEPTION 'Destino incompatible.' USING ERRCODE='P4094';
        END IF;
        IF v_der.cod_med_destino IS NOT NULL AND v_der.cod_med_destino <> v_slot.cod_med THEN
            RAISE EXCEPTION 'Destino incompatible.' USING ERRCODE='P4094';
        END IF;
        IF (SELECT cod_pac FROM public.citas WHERE cod_cit=v_der.cod_cit_origen) <> p_cod_pac THEN
            RAISE EXCEPTION 'Paciente incompatible.' USING ERRCODE='P4094';
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

-- Conserva la reprogramación vigente y su cadena de citas. Una cita derivada
-- mantiene destino, canal y ticket al cambiar de turno, sin reabrir su uso único.
CREATE OR REPLACE FUNCTION public.reprogramar_cita(
    p_cod_cit text, p_cod_slot_nuevo text, p_cod_actor text, p_motivo text
) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_cita public.citas%ROWTYPE; v_rol text; v_pac_usu text;
    v_canal text; v_nueva_cita text; v_der public.derivaciones%ROWTYPE;
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
    IF p_cod_actor=v_pac_usu THEN v_canal := 'Web';
    ELSIF v_rol='ROL002' THEN v_canal := 'Ventanilla';
    ELSIF v_rol='ROL004' THEN v_canal := 'Admin';
    ELSE RAISE EXCEPTION 'Solo el paciente propietario, secretaría o administración puede reprogramar.';
    END IF;
    SELECT * INTO v_der FROM public.derivaciones WHERE cod_cit_hija=p_cod_cit FOR UPDATE;
    IF FOUND THEN
        IF v_der.estado <> 'Agendada' OR NOT EXISTS (
            SELECT 1 FROM public.slots_agenda s WHERE s.cod_slot=p_cod_slot_nuevo
            AND s.cod_esp=v_der.cod_esp_destino
            AND (v_der.cod_med_destino IS NULL OR s.cod_med=v_der.cod_med_destino)
        ) THEN RAISE EXCEPTION 'Destino incompatible.' USING ERRCODE='P4094'; END IF;
    END IF;
    UPDATE public.citas SET estado='Reprogramada', motivo_reprogramacion=COALESCE(NULLIF(trim(p_motivo),''),'Reprogramación de cita')
    WHERE cod_cit=p_cod_cit;
    v_nueva_cita := public.reservar_cita(v_cita.cod_pac,p_cod_slot_nuevo,p_cod_actor,v_canal,NULL);
    UPDATE public.citas SET cod_cit_padre=p_cod_cit,
        canal_reserva=CASE WHEN v_der.cod_der IS NULL THEN v_canal ELSE 'Derivacion' END
    WHERE cod_cit=v_nueva_cita;
    IF v_der.cod_der IS NOT NULL THEN
        UPDATE public.derivaciones SET cod_cit_hija=v_nueva_cita WHERE cod_der=v_der.cod_der;
    END IF;
    RETURN v_nueva_cita;
END;
$$;

