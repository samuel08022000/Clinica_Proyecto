param()
. "$PSScriptRoot/Common.ps1"
$null = Get-ClinicalState
$sql = @'
BEGIN READ ONLY;
SELECT json_build_object(
  'database',current_database(),
  'postgresql',current_setting('server_version'),
  'timezone',current_setting('TimeZone'),
  'domainTables',(SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename<>'flyway_schema_history'),
  'views',(SELECT count(*) FROM pg_views WHERE schemaname='public'),
  'clinicalFunctions',(SELECT count(*) FROM pg_proc p WHERE pronamespace='public'::regnamespace AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid='pg_proc'::regclass AND d.objid=p.oid AND d.deptype='e')),
  'triggers',(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal),
  'foreignKeys',(SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND contype='f'),
  'indexes',(SELECT count(*) FROM pg_indexes WHERE schemaname='public'),
  'exclusionConstraints',(SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND contype='x'),
  'invalidConstraints',(SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated),
  'disabledTriggers',(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND t.tgenabled='D'),
  'users',(SELECT count(*) FROM usuarios),
  'qrInitialized',(SELECT count(*)=1 AND min(octet_length(secreto))=32 FROM configuracion_seguridad WHERE nombre='QR_HMAC'),
  'extensions',(SELECT json_agg(extname ORDER BY extname) FROM pg_extension WHERE extname IN ('pgcrypto','btree_gist')),
  'migrations',(SELECT json_agg(json_build_object('version',version,'type',type,'script',script,'success',success) ORDER BY installed_rank) FROM flyway_schema_history)
);
COMMIT;
'@
$json = Invoke-ClinicalSql $sql
$report = $json | ConvertFrom-Json
if ($report.database -ne 'clinica_laravel' -or $report.postgresql -notmatch '^16\.' -or $report.domainTables -ne 21 -or $report.views -ne 2 -or $report.clinicalFunctions -ne 42 -or $report.triggers -ne 32 -or $report.exclusionConstraints -ne 2 -or $report.invalidConstraints -ne 0 -or $report.disabledTriggers -ne 0 -or !$report.qrInitialized -or $report.users -ne 0) { throw 'El esquema/base principal no cumple las invariantes de instalación Fase 1.' }
if (($report.migrations.script -join ',') -ne 'B9__instalacion_sin_datos_demo.sql,V10__derivaciones_recepcion_enlazadas.sql,R__verificacion_email_otp.sql' -or @($report.migrations | Where-Object {!$_.success}).Count -ne 0) { throw 'Historial inesperado.' }
[IO.File]::WriteAllText((Join-Path $ClinicalPrivate 'schema-report.json'), ($report | ConvertTo-Json -Depth 6), [Text.UTF8Encoding]::new($false))
$report | ConvertTo-Json -Depth 6
