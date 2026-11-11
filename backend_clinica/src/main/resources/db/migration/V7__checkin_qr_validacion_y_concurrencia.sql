-- Misma firma y formato de V1. Esta función no emite ni modifica los QR existentes.
CREATE FUNCTION public.verificar_token_qr(p_token_qr text)
RETURNS TABLE(cod_cit text, fecha date)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_secret bytea; v_segmentos text[]; v_payload text; v_parts text[];
    v_firma bytea; v_fecha date;
BEGIN
    IF p_token_qr IS NULL OR length(p_token_qr) = 0 OR octet_length(p_token_qr) > 4096 THEN RETURN; END IF;
    v_segmentos := string_to_array(p_token_qr, '.');
    IF cardinality(v_segmentos) <> 2 OR v_segmentos[1] = ''
        OR v_segmentos[2] !~ '^[0-9a-f]{64}$' THEN RETURN; END IF;
    BEGIN
        v_payload := convert_from(decode(v_segmentos[1], 'base64'), 'UTF8');
        v_firma := decode(v_segmentos[2], 'hex');
        v_parts := string_to_array(v_payload, '|');
        IF cardinality(v_parts) <> 3 OR v_parts[1] !~ '^CIT[0-9]+$'
            OR v_parts[2] !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            OR v_parts[3] !~ '^[0-9a-f]{32}$' THEN RETURN; END IF;
        v_fecha := v_parts[2]::date;
    EXCEPTION WHEN data_exception THEN
        RETURN;
    END;
    SELECT secreto INTO v_secret FROM public.configuracion_seguridad WHERE nombre = 'QR_HMAC';
    IF v_secret IS NULL OR octet_length(v_secret) = 0 THEN
        RAISE EXCEPTION 'La verificación QR no está disponible.' USING ERRCODE = 'P5001';
    END IF;
    IF hmac(convert_to(v_payload, 'UTF8'), v_secret, 'sha256') <> v_firma THEN RETURN; END IF;
    RETURN QUERY SELECT v_parts[1], v_fecha;
END;
$$;

-- Consulta sin efectos laterales. La escritura vuelve a evaluarla bajo los bloqueos de V5.
CREATE FUNCTION public.cita_admite_checkin(p_cod_cit text) RETURNS boolean
LANGUAGE sql VOLATILE AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.citas c
        WHERE c.cod_cit = p_cod_cit AND c.estado = 'Confirmada'
          AND c.fecha = (clock_timestamp() AT TIME ZONE 'America/La_Paz')::date
          AND c.hora_llegada_checkin IS NULL AND c.checkin_por_usu IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM public.bloqueos_agenda b
              WHERE b.cod_med = c.cod_med AND b.fecha_bloqueo = c.fecha
                AND (b.hora_inicio IS NULL OR
                     tsrange(c.fecha+c.hora_inicio,c.fecha+c.hora_fin,'[)') &&
                     tsrange(b.fecha_bloqueo+b.hora_inicio,b.fecha_bloqueo+b.hora_fin,'[)'))
          )
    );
$$;

CREATE OR REPLACE FUNCTION public.registrar_checkin(p_token_qr text, p_cod_sec text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_cod_cit text; v_fecha date; v_med text; v_usuario text;
    v_cita public.citas%ROWTYPE; v_llegada timestamptz;
BEGIN
    SELECT s.cod_usu INTO v_usuario FROM public.secretarias s
    JOIN public.usuarios u ON u.cod_usu = s.cod_usu JOIN public.roles r ON r.cod_rol = u.cod_rol
    WHERE s.cod_sec = p_cod_sec AND s.estado = 'Activo' AND u.estado = 'Activo'
      AND u.cod_rol = 'ROL002' AND r.estado = 'Activo';
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Secretaría no habilitada.' USING ERRCODE = 'P4031';
    END IF;
    PERFORM set_config('app.cod_usu', v_usuario, true);
    SELECT q.cod_cit, q.fecha INTO v_cod_cit, v_fecha FROM public.verificar_token_qr(p_token_qr) q;
    IF NOT FOUND THEN RAISE EXCEPTION 'QR inválido.' USING ERRCODE = 'P4001'; END IF;

    SELECT c.cod_med INTO v_med FROM public.citas c WHERE c.cod_cit = v_cod_cit AND c.token_qr = p_token_qr;
    IF NOT FOUND THEN RAISE EXCEPTION 'Acreditación no encontrada.' USING ERRCODE = 'P4041'; END IF;
    PERFORM public.bloquear_agenda_medico(v_med);
    SELECT * INTO v_cita FROM public.citas c
    WHERE c.cod_cit = v_cod_cit AND c.token_qr = p_token_qr FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Acreditación no encontrada.' USING ERRCODE = 'P4041'; END IF;
    IF v_fecha <> v_cita.fecha THEN RAISE EXCEPTION 'QR inválido.' USING ERRCODE = 'P4001'; END IF;
    IF NOT public.cita_admite_checkin(v_cod_cit) THEN
        RAISE EXCEPTION 'La cita no admite registro de llegada.' USING ERRCODE = 'P4091';
    END IF;
    v_llegada := clock_timestamp();
    UPDATE public.citas
    SET estado = 'En Espera', hora_llegada_checkin = v_llegada, checkin_por_usu = v_usuario
    WHERE cod_cit = v_cod_cit;
    RETURN v_cod_cit;
END;
$$;
