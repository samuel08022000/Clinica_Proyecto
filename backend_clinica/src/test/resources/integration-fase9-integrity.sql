-- Solo lectura: metadatos y conteos, nunca hashes, QR, correo ni credenciales.
BEGIN READ ONLY;
SELECT current_setting('server_version') AS postgres_version,
       current_database() AS database,
       public.fecha_clinica() = (now() AT TIME ZONE 'America/La_Paz')::date AS fecha_clinica_correcta;
SELECT installed_rank, version, description, success
FROM public.flyway_schema_history ORDER BY installed_rank;
SELECT count(*) AS restricciones_no_validadas
FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated;
SELECT count(*) AS perfiles_paciente_huerfanos
FROM public.pacientes p LEFT JOIN public.usuarios u USING (cod_usu) WHERE u.cod_usu IS NULL;
SELECT count(*) AS citas_con_relaciones_huerfanas
FROM public.citas c
LEFT JOIN public.pacientes p USING (cod_pac)
LEFT JOIN public.slots_agenda s USING (cod_slot)
LEFT JOIN public.medicos m ON m.cod_med=c.cod_med
LEFT JOIN public.especialidades e ON e.cod_esp=c.cod_esp
WHERE p.cod_pac IS NULL OR s.cod_slot IS NULL OR m.cod_med IS NULL OR e.cod_esp IS NULL;
SELECT count(*) AS slots_con_mas_de_una_cita_confirmada
FROM (SELECT cod_slot FROM public.citas WHERE estado IN ('Confirmada','En Espera')
      GROUP BY cod_slot HAVING count(*)>1) duplicados;
SELECT count(*) AS qr_almacenados,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM public.verificar_token_qr(c.token_qr) q
                        WHERE q.cod_cit=c.cod_cit AND q.fecha=c.fecha)) AS qr_autenticos
FROM public.citas c WHERE token_qr IS NOT NULL;
SELECT count(*) FILTER (WHERE nombre_tabla='citas' AND datos_nuevos->>'estado'='Cancelada'
                        AND cod_usu=datos_nuevos->>'cancelado_por_usu') AS cancelaciones_auditadas,
       count(*) FILTER (WHERE nombre_tabla='citas' AND datos_nuevos->>'estado'='En Espera'
                        AND cod_usu=datos_nuevos->>'checkin_por_usu') AS llegadas_auditadas,
       count(*) FILTER (WHERE nombre_tabla='incidencias_sanciones'
                        AND datos_nuevos->>'tipo_incidencia'='SANCION_BLOQUEO'
                        AND cod_usu=datos_nuevos->>'registrado_por_usu') AS sanciones_auditadas
FROM public.bitacora;
SELECT count(*) AS vistas_administrativas
FROM pg_views WHERE schemaname='public' AND viewname IN ('v_agenda_disponibilidad','v_historial_citas');
SELECT count(*) AS triggers_no_internos,
       count(*) FILTER (WHERE tgenabled='D') AS triggers_deshabilitados
FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal;
COMMIT;
