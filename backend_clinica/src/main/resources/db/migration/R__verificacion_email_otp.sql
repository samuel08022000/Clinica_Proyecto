-- Verificacion de correo para pacientes.
-- Repetible para no colisionar con la V3 local de credenciales demo.
-- Los pacientes existentes se consideran verificados al crear la columna; los nuevos
-- registros reciben false mediante el valor por defecto establecido inmediatamente despues.

ALTER TABLE public.pacientes
    ADD COLUMN IF NOT EXISTS email_verificado boolean NOT NULL DEFAULT true;

ALTER TABLE public.pacientes
    ALTER COLUMN email_verificado SET DEFAULT false;

ALTER TABLE public.pacientes
    ADD COLUMN IF NOT EXISTS email_verificado_en timestamptz;

UPDATE public.pacientes
SET email_verificado_en = COALESCE(email_verificado_en, creado_en)
WHERE email_verificado = true
  AND email_verificado_en IS NULL;

ALTER TABLE public.verificaciones_otp
    ADD COLUMN IF NOT EXISTS email_destino varchar(254);

ALTER TABLE public.verificaciones_otp
    ALTER COLUMN telefono_destino DROP NOT NULL;

ALTER TABLE public.verificaciones_otp
    DROP CONSTRAINT IF EXISTS verificaciones_otp_tipo_token_check;

ALTER TABLE public.verificaciones_otp
    ADD CONSTRAINT verificaciones_otp_tipo_token_check
    CHECK (tipo_token IN ('VERIFICACION_TELEFONO', 'VERIFICACION_EMAIL'));

ALTER TABLE public.verificaciones_otp
    DROP CONSTRAINT IF EXISTS ck_verificaciones_otp_destino;

ALTER TABLE public.verificaciones_otp
    ADD CONSTRAINT ck_verificaciones_otp_destino CHECK (
        (tipo_token = 'VERIFICACION_TELEFONO' AND telefono_destino IS NOT NULL AND email_destino IS NULL)
        OR
        (tipo_token = 'VERIFICACION_EMAIL' AND email_destino IS NOT NULL AND telefono_destino IS NULL)
    );

CREATE INDEX IF NOT EXISTS ix_otp_usuario_tipo_fecha
    ON public.verificaciones_otp(cod_usu, tipo_token, creado_en DESC);

CREATE OR REPLACE FUNCTION public.crear_otp_email(p_cod_usu text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_email text;
    v_rand bytea;
    v_num bigint;
    v_codigo text;
BEGIN
    SELECT lower(trim(u.email)) INTO v_email
    FROM public.pacientes p
    JOIN public.usuarios u ON u.cod_usu = p.cod_usu
    WHERE p.cod_usu = p_cod_usu
      AND u.estado = 'Activo'
      AND p.estado_cuenta = 'Activo'
      AND p.email_verificado = false
    FOR UPDATE OF p;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'No existe un paciente activo pendiente de verificar su correo.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.verificaciones_otp
        WHERE cod_usu = p_cod_usu
          AND tipo_token = 'VERIFICACION_EMAIL'
          AND creado_en > now() - interval '60 seconds'
          AND consumido_en IS NULL
    ) THEN
        RAISE EXCEPTION 'Espere al menos 60 segundos antes de solicitar otro codigo.';
    END IF;

    IF (
        SELECT count(*)
        FROM public.verificaciones_otp
        WHERE cod_usu = p_cod_usu
          AND tipo_token = 'VERIFICACION_EMAIL'
          AND creado_en > now() - interval '1 hour'
    ) >= 5 THEN
        RAISE EXCEPTION 'Se alcanzo el limite de solicitudes OTP por hora.';
    END IF;

    UPDATE public.verificaciones_otp
    SET consumido_en = now()
    WHERE cod_usu = p_cod_usu
      AND tipo_token = 'VERIFICACION_EMAIL'
      AND consumido_en IS NULL;

    v_rand := gen_random_bytes(4);
    v_num := ((get_byte(v_rand, 0)::bigint << 24)
              + (get_byte(v_rand, 1)::bigint << 16)
              + (get_byte(v_rand, 2)::bigint << 8)
              + get_byte(v_rand, 3)::bigint) % 1000000;
    v_codigo := lpad(v_num::text, 6, '0');

    INSERT INTO public.verificaciones_otp(
        cod_usu,
        telefono_destino,
        email_destino,
        codigo_hash,
        tipo_token
    ) VALUES (
        p_cod_usu,
        NULL,
        v_email,
        digest(v_codigo, 'sha256'),
        'VERIFICACION_EMAIL'
    );

    RETURN v_codigo;
END;
$$;

CREATE OR REPLACE FUNCTION public.validar_otp_email(
    p_cod_usu text,
    p_codigo text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
    v_otp public.verificaciones_otp%ROWTYPE;
BEGIN
    SELECT * INTO v_otp
    FROM public.verificaciones_otp
    WHERE cod_usu = p_cod_usu
      AND tipo_token = 'VERIFICACION_EMAIL'
      AND consumido_en IS NULL
    ORDER BY creado_en DESC
    LIMIT 1
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN false;
    END IF;

    IF v_otp.expira_en <= now() OR v_otp.intentos >= v_otp.max_intentos THEN
        UPDATE public.verificaciones_otp
        SET consumido_en = now()
        WHERE cod_otp = v_otp.cod_otp;
        RETURN false;
    END IF;

    UPDATE public.verificaciones_otp
    SET intentos = intentos + 1
    WHERE cod_otp = v_otp.cod_otp;

    IF digest(COALESCE(p_codigo, ''), 'sha256') = v_otp.codigo_hash THEN
        UPDATE public.verificaciones_otp
        SET consumido_en = now()
        WHERE cod_otp = v_otp.cod_otp;

        UPDATE public.pacientes
        SET email_verificado = true,
            email_verificado_en = COALESCE(email_verificado_en, now())
        WHERE cod_usu = p_cod_usu;

        RETURN true;
    END IF;

    IF v_otp.intentos + 1 >= v_otp.max_intentos THEN
        UPDATE public.verificaciones_otp
        SET consumido_en = now()
        WHERE cod_otp = v_otp.cod_otp;
    END IF;

    RETURN false;
END;
$$;

REVOKE ALL ON FUNCTION public.crear_otp_email(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.validar_otp_email(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.crear_otp_email(text) TO CURRENT_USER;
GRANT EXECUTE ON FUNCTION public.validar_otp_email(text, text) TO CURRENT_USER;
