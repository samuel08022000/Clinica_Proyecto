-- Rollback de permisos V11; conserva todos los avisos e historiales.
BEGIN;
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
REVOKE SELECT ON public.notificaciones FROM clinica_app;
REVOKE UPDATE (estado, fecha_programada, ultimo_error, en_proceso_desde) ON public.notificaciones FROM clinica_app;
REVOKE EXECUTE ON FUNCTION public.tomar_notificaciones(integer), public.finalizar_notificacion(bigint,boolean,text) FROM clinica_app;
COMMIT;
