-- Conserva incidencias históricas (incluso inconsistencias anteriores), sin deduplicarlas.
CREATE INDEX ix_incidencias_cita_tipo ON public.incidencias_sanciones(cod_cit,tipo_incidencia);

CREATE OR REPLACE FUNCTION public.cancelar_cita(
    p_cod_cit text, p_cod_actor text, p_motivo text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cita public.citas%ROWTYPE; v_med text; v_rol text; v_motivo text;
BEGIN
    SELECT u.cod_rol INTO v_rol FROM public.usuarios u JOIN public.roles r USING(cod_rol)
    WHERE u.cod_usu=p_cod_actor AND u.estado='Activo' AND r.estado='Activo';
    IF NOT FOUND OR v_rol NOT IN ('ROL001','ROL002','ROL004') THEN
        RAISE EXCEPTION 'Actor no autorizado.' USING ERRCODE='P4032';
    END IF;
    IF v_rol='ROL002' AND NOT EXISTS (
        SELECT 1 FROM public.secretarias WHERE cod_usu=p_cod_actor AND estado='Activo'
    ) THEN RAISE EXCEPTION 'Actor no autorizado.' USING ERRCODE='P4032'; END IF;
    SELECT cod_med INTO v_med FROM public.citas WHERE cod_cit=p_cod_cit;
    IF NOT FOUND THEN RAISE EXCEPTION 'Cita inexistente.' USING ERRCODE='P4042'; END IF;
    PERFORM public.bloquear_agenda_medico(v_med);
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=p_cod_cit FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Cita inexistente.' USING ERRCODE='P4042'; END IF;
    IF v_rol='ROL001' AND NOT EXISTS (
        SELECT 1 FROM public.pacientes WHERE cod_pac=v_cita.cod_pac AND cod_usu=p_cod_actor
    ) THEN RAISE EXCEPTION 'Actor no autorizado.' USING ERRCODE='P4032'; END IF;
    v_motivo := btrim(p_motivo);
    IF v_motivo IS NULL OR v_motivo='' OR char_length(v_motivo)>2000 THEN
        RAISE EXCEPTION 'Motivo inválido.' USING ERRCODE='P4002';
    END IF;
    -- RF-22 no define cancelación después de la llegada: se rechaza conservadoramente.
    IF v_cita.estado NOT IN ('Confirmada','Afectada/Pendiente Reprogramación')
        OR v_cita.hora_llegada_checkin IS NOT NULL OR v_cita.checkin_por_usu IS NOT NULL
        OR EXISTS (SELECT 1 FROM public.incidencias_sanciones
                   WHERE cod_cit=p_cod_cit AND tipo_incidencia='CANCELACION') THEN
        RAISE EXCEPTION 'Estado incompatible.' USING ERRCODE='P4092';
    END IF;
    PERFORM set_config('app.cod_usu',p_cod_actor,true);
    UPDATE public.citas SET estado='Cancelada',cancelado_por_usu=p_cod_actor,
        motivo_cancelacion=v_motivo,cancelado_en=clock_timestamp() WHERE cod_cit=p_cod_cit;
    -- Las exclusiones/índice parcial y v_agenda_disponibilidad deciden si el cupo es reservable.
    -- No se modifican slots, ni se ignoran bloqueos, estados o versiones de agenda.
    RETURN true;
END;
$$;

-- Núcleo compartido. Solo las envolturas autorizadas lo invocan; no es una API SQL pública.
CREATE FUNCTION public.procesar_inasistencia_vencida(p_cod_cit text,p_cod_actor text,p_estricto boolean)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_med text; v_cita public.citas%ROWTYPE;
BEGIN
    SELECT cod_med INTO v_med FROM public.citas WHERE cod_cit=p_cod_cit;
    IF NOT FOUND THEN
        IF p_estricto THEN RAISE EXCEPTION 'Cita inexistente.' USING ERRCODE='P4042'; END IF;
        RETURN false;
    END IF;
    PERFORM public.bloquear_agenda_medico(v_med);
    SELECT * INTO v_cita FROM public.citas WHERE cod_cit=p_cod_cit FOR UPDATE;
    IF NOT FOUND THEN
        IF p_estricto THEN RAISE EXCEPTION 'Cita inexistente.' USING ERRCODE='P4042'; END IF;
        RETURN false;
    END IF;
    IF v_cita.estado<>'Confirmada' OR v_cita.hora_llegada_checkin IS NOT NULL
        OR v_cita.checkin_por_usu IS NOT NULL
        OR (v_cita.fecha+v_cita.hora_fin)>=public.ahora_clinica()
        OR EXISTS (SELECT 1 FROM public.incidencias_sanciones
                   WHERE cod_cit=p_cod_cit AND tipo_incidencia='INASISTENCIA_NO_SHOW') THEN
        IF p_estricto THEN RAISE EXCEPTION 'Estado o ventana incompatible.' USING ERRCODE='P4092'; END IF;
        RETURN false;
    END IF;
    PERFORM set_config('app.cod_usu',COALESCE(p_cod_actor,''),true);
    UPDATE public.citas SET estado='No Asistió' WHERE cod_cit=p_cod_cit;
    -- procesar_evento_cita registra la incidencia y auditar_fila registra la transición.
    RETURN true;
END;
$$;
REVOKE ALL ON FUNCTION public.procesar_inasistencia_vencida(text,text,boolean) FROM PUBLIC;

CREATE FUNCTION public.registrar_inasistencia(p_cod_cit text,p_cod_actor text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.usuarios u JOIN public.roles r USING(cod_rol)
        JOIN public.secretarias s ON s.cod_usu=u.cod_usu
        WHERE u.cod_usu=p_cod_actor AND u.cod_rol='ROL002' AND u.estado='Activo'
          AND r.estado='Activo' AND s.estado='Activo'
    ) THEN RAISE EXCEPTION 'Actor no autorizado.' USING ERRCODE='P4032'; END IF;
    RETURN public.procesar_inasistencia_vencida(p_cod_cit,p_cod_actor,true);
END;
$$;

CREATE OR REPLACE FUNCTION public.marcar_inasistencias_vencidas() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cita record; v_cuantos integer := 0;
BEGIN
    -- Un trabajo de sistema no debe heredar un actor de otra operación de la conexión.
    PERFORM set_config('app.cod_usu','',true);
    FOR v_cita IN SELECT cod_cit FROM public.citas
        WHERE estado='Confirmada' AND hora_llegada_checkin IS NULL AND checkin_por_usu IS NULL
          AND (fecha+hora_fin)<public.ahora_clinica() ORDER BY cod_med,cod_cit
    LOOP
        IF public.procesar_inasistencia_vencida(v_cita.cod_cit,NULL,false) THEN
            v_cuantos := v_cuantos+1;
        END IF;
    END LOOP;
    RETURN v_cuantos;
END;
$$;
