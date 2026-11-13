param([Parameter(Mandatory=$true)][ValidateSet('Backend','Frontend','Legacy','Browser','Fault','Expiry','Contract','Visual')][string]$Mode, [switch]$Offline)
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$backendRoot = Join-Path $projectRoot 'backend_clinica'
$frontendRoot = Join-Path $projectRoot 'frontend_clinica'
$testRoot = Join-Path $backendRoot 'target/integration-fase12'
$state = Get-Content -LiteralPath (Join-Path $testRoot 'runtime.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if ($state.project -ne 'clinica-fase12' -or $state.port -ne 35441) { throw 'Invalid isolated runtime.' }
if ($Mode -eq 'Backend') {
    $env:CLINICA_TEST_JDBC_URL='jdbc:postgresql://127.0.0.1:35441/'+$state.tests
    $env:CLINICA_TEST_DB_USER=$state.user
    $env:CLINICA_TEST_DB_PASSWORD=$state.password
    $env:QUARKUS_DATASOURCE_JDBC_URL=$env:CLINICA_TEST_JDBC_URL
    $env:QUARKUS_DATASOURCE_USERNAME=$state.user
    $env:QUARKUS_DATASOURCE_PASSWORD=$state.password
    $env:QUARKUS_DATASOURCE_DEVSERVICES_ENABLED='false'
    $env:QUARKUS_COMPOSE_DEVSERVICES_ENABLED='false'
    $env:QUARKUS_FLYWAY_LOCATIONS='classpath:db/migration'
    $env:QUARKUS_FLYWAY_BASELINE_ON_MIGRATE='false'
    $env:QUARKUS_FLYWAY_OUT_OF_ORDER='false'
    $env:QUARKUS_SCHEDULER_ENABLED='false'
    $env:QUARKUS_HIBERNATE_ORM_LOG_SQL='false'
    Push-Location $backendRoot
    try {
        $arguments=@('-DskipITs=false','verify')
        if ($Offline) { $arguments=@('-o')+$arguments }
        & .\mvnw.cmd @arguments
        if ($LASTEXITCODE -ne 0) { throw 'Backend verification failed.' }
    } finally { Pop-Location }
} elseif ($Mode -in @('Browser','Fault','Expiry','Contract','Visual')) {
    $owned = Get-Content -LiteralPath (Join-Path $testRoot 'processes.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($owned.dataset -ne 'browser') { throw 'Start the exclusive browser dataset before its verification.' }
    $env:RUN_CLINICA_E2E='1'
    Push-Location $projectRoot
    try {
        $script = switch ($Mode) { 'Browser' { 'scripts/integration/browser-e2e.mjs' } 'Fault' { 'scripts/integration/fault-e2e.mjs' } 'Expiry' { 'scripts/integration/session-expiry.mjs' } 'Contract' { 'scripts/integration/contract-http.mjs' } 'Visual' { 'scripts/integration/visual-audit.mjs' } }
        & node $script
        if ($LASTEXITCODE -ne 0) { throw 'Browser verification failed; inspect the private report and its real/simulated classification.' }
    } finally { Pop-Location }
} else {
    if ($Mode -eq 'Legacy') {
        $owned = Get-Content -LiteralPath (Join-Path $testRoot 'processes.json') -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($owned.dataset -ne 'legacy') { throw 'Start the legacy dataset before its verification.' }
        $env:RUN_CLINICA_LIVE_INTEGRATION='1'
        $env:RUN_CLINICA_HTTP_STACK='1'
        $env:CLINICA_LIVE_FIXTURE=Join-Path $backendRoot 'target/integration-fase9/fixture.json'
    } else {
        Remove-Item Env:RUN_CLINICA_LIVE_INTEGRATION,Env:RUN_CLINICA_HTTP_STACK -ErrorAction SilentlyContinue
    }
    Push-Location $frontendRoot
    try {
        & php artisan test --compact --log-junit (Join-Path $testRoot ($Mode.ToLower()+'-laravel.xml'))
        if ($LASTEXITCODE -ne 0) { throw 'Laravel verification failed.' }
    } finally { Pop-Location }
}
