param()
. "$PSScriptRoot/Common.ps1"
$state = Get-ClinicalState
Assert-ClinicalContainer
# Solo hashes en memoria, nunca se muestra la clave QR ni se vuelve a generarla.
$fingerprint = @'
SELECT md5(
    (SELECT system_identifier::text FROM pg_control_system()) ||
    (SELECT string_agg(nombre||encode(secreto,'hex'),',' ORDER BY nombre) FROM configuracion_seguridad) ||
    (SELECT string_agg(row_to_json(r)::text,',' ORDER BY cod_rol) FROM roles r) ||
    (SELECT string_agg(script||checksum::text,',' ORDER BY installed_rank) FROM flyway_schema_history) ||
    (SELECT count(*)::text FROM usuarios) ||
    (SELECT count(*)::text FROM bitacora)
);
'@
$before = @{}
foreach ($db in @($state.database,$state.tests)) { $before[$db] = Invoke-ClinicalSql $fingerprint $db }
& "$PSScriptRoot/Stop-Database.ps1"
& "$PSScriptRoot/Start-Database.ps1"
foreach ($db in @($state.database,$state.tests)) {
    $after = Invoke-ClinicalSql $fingerprint $db
    if ($before[$db] -cne $after) { throw "Cambió la evidencia de persistencia de $db." }
}
Write-Output 'Persistencia comprobada en ambas bases después de stop/start: identidad del clúster, clave QR, roles, historial, usuarios y auditoría conservados.'
