param([switch]$TestDatabase, [Parameter(ValueFromRemainingArguments=$true)][string[]]$CommandArgs = @('artisan','clinical:check'))
. "$PSScriptRoot/Common.ps1"
$state = Get-ClinicalState
$variables = @{
    CLINICAL_DB_HOST=$state.host; CLINICAL_DB_PORT=[string]$state.port;
    CLINICAL_DB_DATABASE=$(if ($TestDatabase) {$state.tests} else {$state.database});
    CLINICAL_DB_USERNAME=$state.username; CLINICAL_DB_PASSWORD=$state.password;
    CLINICAL_DB_SSLMODE='prefer'
}
if ($TestDatabase) {
    $variables.CLINICAL_PG_TESTS='1'
    $variables.CLINICAL_OWNER_PASSWORD=$state.ownerPassword
}
$previous = @{}
foreach ($key in $variables.Keys) {
    $previous[$key] = [Environment]::GetEnvironmentVariable($key,'Process')
    [Environment]::SetEnvironmentVariable($key,$variables[$key],'Process')
}
Push-Location (Join-Path $ClinicalRoot 'frontend_clinica')
try {
    if (Test-Path -LiteralPath 'bootstrap/cache/config.php') { throw 'Hay configuración cacheada. Revísela antes de usar este entorno; el script no la sobrescribe.' }
    & php @CommandArgs
    if ($LASTEXITCODE -ne 0) { throw 'La operación Laravel terminó con error.' }
} finally {
    Pop-Location
    foreach ($key in $variables.Keys) { [Environment]::SetEnvironmentVariable($key,$previous[$key],'Process') }
}
