-- Avisos de llegada dentro de la misma transacción del check-in. Sin cambiar estados ni tablas.
BEGIN;
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
        INSERT INTO public.notificaciones(cod_usu,cod_cit,canal,tipo_alerta,destinatario,mensaje,fecha_programada)
        VALUES (v_usu,NEW.cod_cit,'Web','Cita_En_Espera',v_usu,
                'Registramos tu llegada. La cita '||NEW.cod_cit||' pasó a En Espera. Aguarda el llamado de tu médico.',now()),
               (v_usu,NEW.cod_cit,'Email','Cita_En_Espera',v_email,
                'Registramos tu llegada. La cita '||NEW.cod_cit||' pasó a En Espera. Aguarda el llamado de tu médico.',now());
    ELSIF TG_OP='UPDATE' AND NEW.estado='Atendida' AND OLD.estado IS DISTINCT FROM NEW.estado THEN
        UPDATE public.citas SET fecha_atencion=COALESCE(NEW.fecha_atencion,now())
        WHERE cod_cit=NEW.cod_cit AND fecha_atencion IS NULL;
    END IF;
    IF TG_OP='UPDATE' AND NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF NEW.estado IN ('Reprogramada','En Espera','Atendida','Cancelada','No Asistió','Afectada/Pendiente Reprogramación') THEN
            UPDATE public.notificaciones SET estado='Cancelada', en_proceso_desde=NULL
            WHERE cod_cit=NEW.cod_cit AND tipo_alerta IN ('Confirmacion','Recordatorio_24h')
              AND estado IN ('Pendiente','EnProceso');
        END IF;
        IF NEW.estado IN ('Atendida','Cancelada') THEN
            UPDATE public.derivaciones
               SET estado=CASE WHEN NEW.estado='Atendida' THEN 'Completada' ELSE 'Cancelada' END
             WHERE cod_cit_hija=NEW.cod_cit AND estado='Agendada';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
COMMIT;
