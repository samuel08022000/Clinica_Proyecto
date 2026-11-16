param()
. "$PSScriptRoot/Common.ps1"
$state = Get-ClinicalState
Assert-ClinicalContainer
$sql = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'phase2-permissions.sql') -Raw -Encoding UTF8
foreach ($database in @($state.database, $state.tests)) {
    $marker = Invoke-ClinicalSql "SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()" $database
    if ($marker -ne 'laravel-phase1-isolated') { throw 'Base ajena: permisos Fase 2 rechazados.' }
    $null = Invoke-ClinicalSql $sql $database
}
Write-Output 'Permisos mínimos de autenticación Fase 2 aplicados a las dos bases aisladas.'
