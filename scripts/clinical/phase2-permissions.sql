BEGIN;
GRANT EXECUTE ON FUNCTION public.registrar_paciente(text,text,text,text,date,text,text) TO clinica_app;
GRANT EXECUTE ON FUNCTION public.crear_otp_email(text) TO clinica_app;
GRANT EXECUTE ON FUNCTION public.validar_otp_email(text,text) TO clinica_app;
GRANT EXECUTE ON FUNCTION public.crear_token_recuperacion(text) TO clinica_app;
GRANT EXECUTE ON FUNCTION public.restablecer_contrasena(text,text,text) TO clinica_app;

-- Registro Google validado: columnas mínimas, sin capacidad de cambiar roles existentes.
GRANT INSERT (nombre_completo,email,password_hash,cod_rol,estado) ON public.usuarios TO clinica_app;
GRANT INSERT (cod_usu,ci,fecha_nacimiento,telefono,telefono_verificado,email_verificado,email_verificado_en,ciudad_residencia,estado_cuenta)
    ON public.pacientes TO clinica_app;
GRANT INSERT (cod_usu,proveedor,identificador_externo,email_proveedor,email_verificado)
    ON public.usuarios_identidades_externas TO clinica_app;
GRANT UPDATE (email_proveedor,email_verificado,actualizado_en)
    ON public.usuarios_identidades_externas TO clinica_app;
GRANT USAGE, SELECT ON SEQUENCE public.usuarios_secuencial_seq,
    public.pacientes_secuencial_seq, public.usuarios_identidades_externas_secuencial_seq TO clinica_app;
COMMIT;
