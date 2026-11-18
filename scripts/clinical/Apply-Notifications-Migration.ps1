param([switch]$TestDatabase, [switch]$Rollback)
. "$PSScriptRoot/Common.ps1"
Assert-ClinicalContainer
$state = Get-ClinicalState
$targetDatabase = if ($TestDatabase) { $state.tests } else { $state.database }
$dependencies = Invoke-ClinicalSql "SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname IN ('tomar_notificaciones','procesar_evento_cita','finalizar_notificacion')" $targetDatabase
if ($dependencies -ne '3') { throw 'Las funciones clínicas vigentes no corresponden al contrato esperado.' }
$baselineSql = Get-Content -LiteralPath (Join-Path $ClinicalRoot 'database_clinica/migration/B9__instalacion_sin_datos_demo.sql') -Raw -Encoding UTF8
$upgradeSql = Get-Content -LiteralPath (Join-Path $ClinicalRoot 'database_clinica/upgrade/V11__permisos_notificaciones.sql') -Raw -Encoding UTF8
foreach ($functionName in @('tomar_notificaciones', 'procesar_evento_cita', 'reprogramar_cita')) {
    $originalSql = if ($functionName -eq 'reprogramar_cita') { Get-Content -LiteralPath (Join-Path $ClinicalRoot 'database_clinica/migration/V10__derivaciones_recepcion_enlazadas.sql') -Raw -Encoding UTF8 } else { $baselineSql }
    $knownHashes = foreach ($sourceSql in @($originalSql, $upgradeSql)) {
        $body = [regex]::Match($sourceSql, "CREATE OR REPLACE FUNCTION public\.$functionName\(.*?AS \`$\`$(.*?)\`$\`$;", 'Singleline').Groups[1].Value.Replace("`r`n", "`n")
        if (!$body) { throw 'No se pudo verificar la definición SQL versionada.' }
        [Convert]::ToHexString([Security.Cryptography.MD5]::HashData([Text.Encoding]::UTF8.GetBytes($body))).ToLowerInvariant()
    }
    $actualHash = Invoke-ClinicalSql "SELECT md5(replace(prosrc,chr(13),'')) FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname='$functionName'" $targetDatabase
    if ($actualHash -notin $knownHashes) { throw "La función $functionName tiene cambios ajenos; no se sobrescribe." }
}
$fileName = if ($Rollback) { 'U11__permisos_notificaciones.sql' } else { 'V11__permisos_notificaciones.sql' }
$migrationSql = Get-Content -LiteralPath (Join-Path $ClinicalRoot "database_clinica/upgrade/$fileName") -Raw -Encoding UTF8
$null = Invoke-ClinicalSql $migrationSql $targetDatabase
Write-Output "${targetDatabase}: migración de notificaciones y derivaciones $(if ($Rollback) {'revertida'} else {'aplicada'}); historiales conservados."
