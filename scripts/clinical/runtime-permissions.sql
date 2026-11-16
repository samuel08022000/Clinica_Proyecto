-- Política de acceso Fase 1, separada de las migraciones históricas inmutables.
-- El propietario de esquema NO es el usuario de Laravel.
BEGIN;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO clinica_app;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM clinica_app;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM clinica_app;
-- Funciones de negocio con SECURITY DEFINER no deben ser invocables por PUBLIC.
-- Se restringen solo las funciones clínicas, conservando las de las extensiones.
DO $$ DECLARE f record; BEGIN
    FOR f IN SELECT p.oid::regprocedure AS signature FROM pg_proc p
        JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname='public' AND NOT EXISTS (
            SELECT 1 FROM pg_depend d WHERE d.classid='pg_proc'::regclass
            AND d.objid=p.oid AND d.deptype='e'
        )
    LOOP
        EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, clinica_app', f.signature);
    END LOOP;
END $$;
GRANT SELECT ON public.roles, public.usuarios, public.pacientes, public.secretarias,
    public.medicos, public.especialidades, public.medico_especialidades, public.horarios,
    public.bloqueos_agenda, public.slots_agenda, public.bloqueos_citas_afectadas,
    public.citas, public.derivaciones, public.analisis, public.incidencias_sanciones,
    public.usuarios_identidades_externas, public.bitacora,
    public.v_agenda_disponibilidad, public.v_historial_citas TO clinica_app;
GRANT EXECUTE ON FUNCTION public.fecha_clinica(), public.ahora_clinica(),
    public.actor_actual(), public.estado_reserva_paciente(text) TO clinica_app;
-- Única escritura habilitada en esta fase, usada para demostrar auditoría real.
GRANT UPDATE (descripcion) ON public.especialidades TO clinica_app;
-- OTP, recuperación, QR_HMAC y cola de notificaciones siguen privados.
-- Cada fase posterior añadirá privilegios explícitos junto con su autorización PHP.
ALTER DEFAULT PRIVILEGES FOR ROLE clinica_owner IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
COMMIT;
