BEGIN;

ALTER TABLE public.citas ADD COLUMN IF NOT EXISTS hora_inicio_atencion timestamptz;

CREATE OR REPLACE TRIGGER trg_auditar_citas
AFTER INSERT OR UPDATE OF estado, cod_slot, cod_pac, cod_med, cod_esp, fecha,
    hora_inicio, hora_fin, canal_reserva, cancelado_por_usu, motivo_cancelacion,
    checkin_por_usu, atendida_por_usu, hora_inicio_atencion ON public.citas
FOR EACH ROW EXECUTE FUNCTION public.auditar_fila('cod_cit');

CREATE OR REPLACE FUNCTION public.iniciar_atencion_cita(p_cod_cit text, p_cod_usu_doctor text)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cod_med text; v_inicio timestamptz;
BEGIN
    SELECT m.cod_med INTO v_cod_med FROM public.medicos m
    JOIN public.usuarios u USING (cod_usu) JOIN public.roles r USING (cod_rol)
    WHERE m.cod_usu=p_cod_usu_doctor AND m.estado='Activo' AND u.estado='Activo'
      AND u.cod_rol='ROL003' AND r.estado='Activo';
    IF NOT FOUND THEN RETURN false; END IF;

    PERFORM public.bloquear_agenda_medico(v_cod_med);
    PERFORM set_config('app.cod_usu',p_cod_usu_doctor,true);
    v_inicio := clock_timestamp();
    UPDATE public.citas SET hora_inicio_atencion=v_inicio
    WHERE cod_cit=p_cod_cit AND cod_med=v_cod_med AND estado='En Espera'
      AND hora_inicio_atencion IS NULL AND fecha_atencion IS NULL AND atendida_por_usu IS NULL
      AND hora_llegada_checkin <= v_inicio AND isfinite(hora_llegada_checkin) AND checkin_por_usu IS NOT NULL;
    RETURN FOUND;
END;
$$;

CREATE OR REPLACE FUNCTION public.marcar_cita_atendida(p_cod_cit text, p_cod_usu_doctor text)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_cod_med text; v_cierre timestamptz;
BEGIN
    SELECT m.cod_med INTO v_cod_med FROM public.medicos m
    JOIN public.usuarios u USING (cod_usu) JOIN public.roles r USING (cod_rol)
    WHERE m.cod_usu=p_cod_usu_doctor AND m.estado='Activo' AND u.estado='Activo'
      AND u.cod_rol='ROL003' AND r.estado='Activo';
    IF NOT FOUND THEN RETURN false; END IF;

    PERFORM public.bloquear_agenda_medico(v_cod_med);
    PERFORM set_config('app.cod_usu',p_cod_usu_doctor,true);
    v_cierre := clock_timestamp();
    UPDATE public.citas SET estado='Atendida', fecha_atencion=v_cierre, atendida_por_usu=p_cod_usu_doctor
    WHERE cod_cit=p_cod_cit AND cod_med=v_cod_med AND estado='En Espera'
      AND hora_llegada_checkin <= hora_inicio_atencion AND checkin_por_usu IS NOT NULL
      AND hora_inicio_atencion <= v_cierre AND fecha_atencion IS NULL AND atendida_por_usu IS NULL;
    RETURN FOUND;
END;
$$;

REVOKE ALL ON FUNCTION public.iniciar_atencion_cita(text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.iniciar_atencion_cita(text,text), public.marcar_cita_atendida(text,text) TO clinica_app;
COMMIT;
