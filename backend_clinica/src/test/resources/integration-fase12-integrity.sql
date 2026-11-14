-- Read-only assertions. Parameters contain synthetic identifiers only, never passwords/Bearer/QR.
BEGIN READ ONLY;
SELECT json_build_object(
    'database',current_database(),
    'postgres',current_setting('server_version'),
    'clinicalDateCorrect',public.fecha_clinica()=(clock_timestamp() AT TIME ZONE 'America/La_Paz')::date,
    'successfulMigrations',(SELECT count(*) FROM public.flyway_schema_history WHERE success),
    'baselineWithoutDemo',(SELECT count(*) FROM public.flyway_schema_history WHERE success AND version='9' AND type='SQL_BASELINE'),
    'emailOtpMigration',(SELECT count(*) FROM public.flyway_schema_history WHERE success AND script='R__verificacion_email_otp.sql'),
    'historicalDemoAccounts',(SELECT count(*) FROM public.usuarios WHERE lower(trim(email)) IN ('samuel.paciente@unifranz.edu.bo','angela.secretaria@clinica.bo','dilan.doctor@clinica.bo','yesid.admin@clinica.bo')),
    'failedMigrations',(SELECT count(*) FROM public.flyway_schema_history WHERE NOT success),
    'unvalidatedConstraints',(SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated),
    'disabledTriggers',(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal AND t.tgenabled='D'),
    'orphanAppointments',(SELECT count(*) FROM public.citas c LEFT JOIN public.pacientes p USING(cod_pac) LEFT JOIN public.slots_agenda s USING(cod_slot) LEFT JOIN public.medicos m ON m.cod_med=c.cod_med LEFT JOIN public.especialidades e ON e.cod_esp=c.cod_esp WHERE p.cod_pac IS NULL OR s.cod_slot IS NULL OR m.cod_med IS NULL OR e.cod_esp IS NULL),
    'duplicateEffectiveSlots',(SELECT count(*) FROM (SELECT cod_slot FROM public.citas WHERE estado IN ('Confirmada','En Espera') GROUP BY cod_slot HAVING count(*)>1) duplicates),
    'invalidStoredQr',(SELECT count(*) FROM public.citas c WHERE token_qr IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.verificar_token_qr(c.token_qr) q WHERE q.cod_cit=c.cod_cit AND q.fecha=c.fecha)),
    'administrativeViews',(SELECT count(*) FROM pg_views WHERE schemaname='public' AND viewname IN ('v_agenda_disponibilidad','v_historial_citas'))
);
SELECT json_build_object(
    'doctorEditedAndActive',EXISTS(SELECT 1 FROM public.medicos m JOIN public.usuarios u USING(cod_usu) WHERE m.cod_med=:'doctor' AND m.estado='Activo' AND u.nombre_completo='Doctor Editado E2E Doce' AND m.duracion_consulta_min=20),
    'doctorTwoActiveLinks',(SELECT count(*) FROM public.medico_especialidades WHERE cod_med=:'doctor' AND estado='Activo')=2,
    'secretaryEditedAndActive',EXISTS(SELECT 1 FROM public.secretarias s JOIN public.usuarios u USING(cod_usu) WHERE s.cod_sec=:'staff' AND s.estado='Activo' AND s.telefono='70000002' AND u.nombre_completo='Secretario Editado E2E Doce'),
    'specialtyEditedAndActive',EXISTS(SELECT 1 FROM public.especialidades WHERE cod_esp=:'specialty' AND estado='Activo' AND nombre_especialidad='Especialidad E2E Doce Editada'),
    'schedulePreservedAfterConflicts',EXISTS(SELECT 1 FROM public.horarios WHERE cod_hor=:'schedule' AND cod_med=:'doctor' AND hora_inicio=TIME '08:00' AND hora_fin=TIME '13:00'),
    'reservationChannelAndActor',EXISTS(SELECT 1 FROM public.citas WHERE cod_cit=:'reservation' AND canal_reserva='Ventanilla' AND cod_sec=:'secretary' AND creado_por_usu=:'actor' AND estado='Cancelada'),
    'cancellationAudit',EXISTS(SELECT 1 FROM public.bitacora WHERE nombre_tabla='citas' AND datos_nuevos->>'cod_cit'=:'reservation' AND datos_nuevos->>'estado'='Cancelada' AND cod_usu=:'actor'),
    'arrivalAndActor',EXISTS(SELECT 1 FROM public.citas WHERE cod_cit=:'qrAppointment' AND estado='En Espera' AND checkin_por_usu=:'actor' AND hora_llegada_checkin IS NOT NULL),
    'arrivalAudit',EXISTS(SELECT 1 FROM public.bitacora WHERE nombre_tabla='citas' AND datos_nuevos->>'cod_cit'=:'qrAppointment' AND datos_nuevos->>'estado'='En Espera' AND cod_usu=:'actor'),
    'absenceAndSingleIncident',(SELECT count(*) FROM public.incidencias_sanciones WHERE cod_cit=:'absenceAppointment' AND tipo_incidencia='INASISTENCIA_NO_SHOW' AND registrado_por_usu=:'actor')=1,
    'blockTraceability',EXISTS(SELECT 1 FROM public.bloqueos_agenda WHERE cod_blo=:'block'),
    'blockAffectedAppointments',EXISTS(SELECT 1 FROM public.citas WHERE cod_med=:'doctor' AND estado='Afectada/Pendiente Reprogramación'),
    'activeSanction',EXISTS(SELECT 1 FROM public.incidencias_sanciones WHERE cod_inc=:'sanction' AND tipo_incidencia='SANCION_BLOQUEO' AND registrado_por_usu=:'admin' AND now()<fecha_fin_sancion),
    'sanctionAudit',EXISTS(SELECT 1 FROM public.bitacora WHERE nombre_tabla='incidencias_sanciones' AND datos_nuevos->>'cod_inc'=:'sanction' AND cod_usu=:'admin'),
    'staleSlotSingleEffectiveReservation',(SELECT count(*) FROM public.citas WHERE cod_slot=:'staleSlot' AND estado IN ('Confirmada','En Espera','Afectada/Pendiente Reprogramación'))=1,
    'concurrentSlotSingleEffectiveReservation',(SELECT count(*) FROM public.citas WHERE cod_slot=:'concurrentSlot' AND estado IN ('Confirmada','En Espera'))=1
);
COMMIT;
