-- Privilegios incrementales Fase 4. No modifica B9, V10 ni R.
BEGIN;
GRANT EXECUTE ON FUNCTION public.marcar_cita_atendida(text,text),
    public.crear_derivacion(text,text,text,text,text) TO clinica_app;

-- Las solicitudes y resultados se escriben bajo autorización ROL003 y bloqueo transaccional.
GRANT INSERT (cod_cit,solicitado_por_usu,tipo_analisis) ON public.analisis TO clinica_app;
GRANT UPDATE (estado,resultado) ON public.analisis TO clinica_app;
GRANT USAGE, SELECT ON SEQUENCE public.analisis_secuencial_seq TO clinica_app;
COMMIT;
