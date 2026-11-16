-- Privilegios incrementales Fase 3. No modifica B9, V10 ni R.
BEGIN;
GRANT INSERT ON public.medicos, public.secretarias, public.especialidades,
    public.medico_especialidades, public.horarios, public.bloqueos_agenda TO clinica_app;
GRANT UPDATE (nombre_completo,email,password_hash,estado,actualizado_en) ON public.usuarios TO clinica_app;
GRANT UPDATE (ci,matricula_profesional,resumen_curricular,duracion_consulta_min,estado) ON public.medicos TO clinica_app;
GRANT UPDATE (ci,telefono,estado) ON public.secretarias TO clinica_app;
GRANT UPDATE (nombre_especialidad,descripcion,duracion_consulta_min,estado) ON public.especialidades TO clinica_app;
GRANT UPDATE (duracion_consulta_min,estado) ON public.medico_especialidades TO clinica_app;
GRANT UPDATE (dia_semana,hora_inicio,hora_fin,pausa_inicio,pausa_fin,estado) ON public.horarios TO clinica_app;
GRANT USAGE, SELECT ON SEQUENCE public.medicos_secuencial_seq, public.secretarias_secuencial_seq,
    public.especialidades_secuencial_seq, public.horarios_secuencial_seq,
    public.bloqueos_agenda_secuencial_seq TO clinica_app;
GRANT EXECUTE ON FUNCTION public.bloquear_agenda_medico(text),
    public.generar_slots_agenda(date,date), public.reservar_cita(text,text,text,text,text),
    public.cancelar_cita(text,text,text), public.reprogramar_cita(text,text,text,text),
    public.verificar_token_qr(text), public.cita_admite_checkin(text),
    public.registrar_checkin(text,text), public.registrar_inasistencia(text,text),
    public.registrar_sancion(text,text,text,integer), public.marcar_inasistencias_vencidas(),
    public.desbloquear_sanciones_vencidas() TO clinica_app;
COMMIT;
