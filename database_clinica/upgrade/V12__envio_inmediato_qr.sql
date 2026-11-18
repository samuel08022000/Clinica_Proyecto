-- Reclamo atómico por cita del mismo aviso existente. Sin cambios de credenciales o datos.
BEGIN;
GRANT UPDATE (intentos) ON public.notificaciones TO clinica_app;
COMMIT;
