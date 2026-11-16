$ErrorActionPreference = 'Stop'
$ClinicalRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$ClinicalPrivate = Join-Path $ClinicalRoot '.local/clinical-phase1'
$ClinicalCompose = Join-Path $ClinicalRoot 'compose.clinical.yml'
$ClinicalContainer = 'clinica-laravel-fase1-pg16'
$OutputEncoding = [Text.UTF8Encoding]::new($false)

function Get-ClinicalState {
    $path = Join-Path $ClinicalPrivate 'runtime.json'
    if (!(Test-Path -LiteralPath $path)) { throw 'Ejecute primero Initialize-Environment.ps1.' }
    $state = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($state.container -ne $ClinicalContainer -or $state.host -ne '127.0.0.1' -or $state.port -ne 35443 -or $state.database -ne 'clinica_laravel' -or $state.tests -ne 'clinica_laravel_phase1_tests' -or $state.username -ne 'clinica_app' -or $state.owner -ne 'clinica_owner') {
        throw 'El estado no corresponde al entorno aislado de esta fase.'
    }
    return $state
}

function Assert-ClinicalContainer {
    $raw = & docker inspect $ClinicalContainer 2>$null
    if ($LASTEXITCODE -ne 0) { throw 'Contenedor aislado no disponible.' }
    $info = @($raw | ConvertFrom-Json)[0]
    if ($info.Config.Labels.'clinica.environment' -ne 'laravel-phase1' -or $info.Config.Image -ne 'postgres:16-alpine' -or
        $info.HostConfig.PortBindings.'5432/tcp'[0].HostIp -ne '127.0.0.1' -or $info.HostConfig.PortBindings.'5432/tcp'[0].HostPort -ne '35443' -or
        @($info.Mounts | Where-Object { $_.Name -eq 'clinica_laravel_fase1_pg16_data' -and $_.Destination -eq '/var/lib/postgresql/data' }).Count -ne 1) {
        throw 'Contenedor o volumen ajeno: operación rechazada.'
    }
}

function Invoke-ClinicalSql([string]$Sql, [string]$Database = 'clinica_laravel') {
    if ($Database -notin @('clinica_laravel', 'clinica_laravel_phase1_tests')) { throw 'Base no autorizada.' }
    Assert-ClinicalContainer
    # El SQL no aparece en argumentos de procesos ni se registra en archivos.
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $result = $Sql | & docker exec -i $ClinicalContainer psql -X -q -U clinica_bootstrap -d $Database -v ON_ERROR_STOP=1 -At 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    if ($code -ne 0) { throw 'Falló SQL del entorno aislado. Se omite SQL y stderr para proteger credenciales.' }
    return $result
}
