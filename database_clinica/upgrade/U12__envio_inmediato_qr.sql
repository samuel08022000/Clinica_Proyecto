BEGIN;
REVOKE UPDATE (intentos) ON public.notificaciones FROM clinica_app;
COMMIT;
