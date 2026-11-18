param(
    [switch]$ValidateOnly
)
. "$PSScriptRoot/Common.ps1"
$state = Get-ClinicalState
Assert-ClinicalContainer

if (!$ValidateOnly) {
    $count = Invoke-ClinicalSql "SELECT count(*) FROM pg_roles WHERE rolname IN ('clinica_owner','clinica_app')"
    if ($count -eq '0') {
        if ($state.ownerPassword -notmatch '^[a-f0-9]{64}$' -or $state.password -notmatch '^[a-f0-9]{64}$') { throw 'Formato privado inesperado.' }
        # Sin secretos en argumentos, logs ni archivos SQL versionados.
        $sql = "BEGIN; SET LOCAL log_statement='none'; SET LOCAL log_min_error_statement='panic'; CREATE ROLE clinica_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '$($state.ownerPassword)'; CREATE ROLE clinica_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '$($state.password)'; ALTER DATABASE clinica_laravel OWNER TO clinica_owner; COMMENT ON DATABASE clinica_laravel IS 'laravel-phase1-isolated'; REVOKE ALL ON DATABASE clinica_laravel FROM PUBLIC; GRANT CONNECT ON DATABASE clinica_laravel TO clinica_app; COMMIT;"
        $null = Invoke-ClinicalSql $sql
    } elseif ($count -ne '2') { throw 'Estado parcial de roles: requiere inspección, no se cambian contraseñas.' }
    $testExists = Invoke-ClinicalSql "SELECT count(*) FROM pg_database WHERE datname='clinica_laravel_phase1_tests'"
    if ($testExists -eq '0') {
        $null = Invoke-ClinicalSql 'CREATE DATABASE clinica_laravel_phase1_tests OWNER clinica_owner'
        $null = Invoke-ClinicalSql "COMMENT ON DATABASE clinica_laravel_phase1_tests IS 'laravel-phase1-isolated'; REVOKE ALL ON DATABASE clinica_laravel_phase1_tests FROM PUBLIC; GRANT CONNECT ON DATABASE clinica_laravel_phase1_tests TO clinica_app;"
    }
}

$previous = [Environment]::GetEnvironmentVariable('CLINICAL_OWNER_PASSWORD','Process')
$env:CLINICAL_OWNER_PASSWORD = $state.ownerPassword
try {
    $migrationPath = Join-Path $ClinicalRoot 'database_clinica/migration'
    foreach ($database in @($state.database,$state.tests)) {
        $mode = if ($ValidateOnly) { 'validate' } else { 'install' }
        & php (Join-Path $PSScriptRoot 'ClinicalSchemaPure.php') $database $migrationPath $mode
        if ($LASTEXITCODE -ne 0) { throw 'El instalador de esquema no completó instalación/validación.' }
        if (!$ValidateOnly) {
            foreach ($permFile in @('runtime-permissions.sql','phase2-permissions.sql','phase3-permissions.sql','phase4-permissions.sql')) {
                $sql = Get-Content -LiteralPath (Join-Path $PSScriptRoot $permFile) -Raw -Encoding UTF8
                $null = Invoke-ClinicalSql $sql $database
            }
            if ($database -eq $state.tests) {
                & (Join-Path $PSScriptRoot 'Apply-Notifications-Migration.ps1') -TestDatabase
            } else {
                & (Join-Path $PSScriptRoot 'Apply-Notifications-Migration.ps1')
            }
        }
    }
} finally { [Environment]::SetEnvironmentVariable('CLINICAL_OWNER_PASSWORD',$previous,'Process') }
