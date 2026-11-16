param([ValidateSet('legacy', 'browser')][string]$Dataset = 'browser')
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$backendRoot = Join-Path $projectRoot 'backend_clinica'
$frontendRoot = Join-Path $projectRoot 'frontend_clinica'
$testRoot = Join-Path $backendRoot 'target/integration-fase12'
$state = Get-Content -LiteralPath (Join-Path $testRoot 'runtime.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if ($state.project -ne 'clinica-fase12' -or $state.port -ne 35441) { throw 'Invalid isolated runtime.' }
$fixturePath = if ($Dataset -eq 'legacy') { Join-Path $backendRoot 'target/integration-fase9/fixture.json' } else { Join-Path $testRoot 'browser-fixture.json' }
$fixture = Get-Content -LiteralPath $fixturePath -Raw -Encoding UTF8 | ConvertFrom-Json
if ($fixture.database -ne $state.$Dataset) { throw 'Fixture/database mismatch.' }
$pidPath = Join-Path $testRoot 'processes.json'
if (Test-Path -LiteralPath $pidPath) { throw 'Stop the owned integration processes before starting another dataset.' }
foreach ($port in @(18090,18091,18092)) {
    $probe = [Net.Sockets.TcpClient]::new()
    try {
        $connection = $probe.ConnectAsync('127.0.0.1', $port)
        if ($connection.Wait(500) -and $probe.Connected) { throw "Test port $port is already in use." }
    } catch [AggregateException] { } finally { $probe.Dispose() }
}
$env:QUARKUS_DATASOURCE_JDBC_URL = 'jdbc:postgresql://127.0.0.1:35441/' + $fixture.database
$env:QUARKUS_DATASOURCE_USERNAME = $state.user
$env:QUARKUS_DATASOURCE_PASSWORD = $state.password
$env:QUARKUS_DATASOURCE_DEVSERVICES_ENABLED = 'false'
$env:QUARKUS_COMPOSE_DEVSERVICES_ENABLED = 'false'
$env:QUARKUS_FLYWAY_LOCATIONS = 'classpath:db/migration'
$env:QUARKUS_FLYWAY_BASELINE_ON_MIGRATE = 'false'
$env:QUARKUS_FLYWAY_OUT_OF_ORDER = 'false'
$env:QUARKUS_SCHEDULER_ENABLED = 'false'
$env:QUARKUS_HIBERNATE_ORM_LOG_SQL = 'false'
$env:OTP_DELIVERY_PROVIDER = 'demo'
$env:OTP_DEMO_ACCESS_KEY = $fixture.demoKey
$env:GOOGLE_OAUTH_ENABLED = 'false'
$env:AUTH_FRONTEND_BASE_URL = 'http://127.0.0.1:18091'
$startedAt = (Get-Date).ToString('o')
$quarkus = Start-Process -FilePath 'cmd.exe' -ArgumentList '/c mvnw.cmd -o quarkus:dev -Dquarkus.profile=integration -Dquarkus.http.port=18090 -Dquarkus.http.host=127.0.0.1 -Dquarkus.console.enabled=false -Ddebug=false -Dquarkus.analytics.disabled=true' -WorkingDirectory $backendRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot "$Dataset-quarkus.stdout.log") -RedirectStandardError (Join-Path $testRoot "$Dataset-quarkus.stderr.log")
$laravelStorage = Join-Path $testRoot "$Dataset-laravel"
foreach ($path in @('framework/sessions','framework/views','framework/cache/data','logs')) { New-Item -ItemType Directory -Path (Join-Path $laravelStorage $path) -Force | Out-Null }
$env:CLINICA_ISOLATED_HTTP = '1'
$env:CLINICA_RUNTIME_STORAGE = $laravelStorage
$env:APP_ENV = 'production'
$env:APP_DEBUG = 'false'
$env:APP_KEY = $fixture.laravelKey
$env:APP_URL = 'http://127.0.0.1:18091'
$env:SESSION_DRIVER = 'file'
$env:SESSION_ENCRYPT = 'true'
$env:SESSION_COOKIE = 'clinica_fase12_session'
$env:SESSION_SECURE_COOKIE = 'false'
$env:CACHE_STORE = 'file'
$env:LOG_CHANNEL = 'single'
$env:QUARKUS_API_URL = 'http://127.0.0.1:18090/api/v1'
$php = (Get-Command php).Source
$router = Join-Path $frontendRoot 'tests/Integration/router.php'
$arguments = '-S 127.0.0.1:18091 -t "' + (Join-Path $frontendRoot 'public') + '" "' + $router + '"'
$healthy = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $frontendRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot "$Dataset-laravel.stdout.log") -RedirectStandardError (Join-Path $testRoot "$Dataset-laravel.stderr.log")
# Shares only the test session to verify actual connection failure and recovery.
$env:QUARKUS_API_URL = 'http://127.0.0.1:18099/api/v1'
$arguments = '-S 127.0.0.1:18092 -t "' + (Join-Path $frontendRoot 'public') + '" "' + $router + '"'
$faulty = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $frontendRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot "$Dataset-fault.stdout.log") -RedirectStandardError (Join-Path $testRoot "$Dataset-fault.stderr.log")
$processes = @{ dataset=$Dataset; quarkus=$quarkus.Id; laravel=$healthy.Id; faulty=$faulty.Id; startedAt=$startedAt }
[IO.File]::WriteAllText($pidPath, ($processes | ConvertTo-Json))
# Return only when the real HTTP boundaries are ready, including a cold Maven bootstrap.
# This prevents the caller from starting live tests while the processes merely exist.
$readyDeadline = [DateTime]::UtcNow.AddMinutes(3)
$ready = $false
while ([DateTime]::UtcNow -lt $readyDeadline) {
    $statuses = @()
    foreach ($url in @('http://127.0.0.1:18090/api/v1/auth/me','http://127.0.0.1:18091/login')) {
        $response = $null
        try {
            $probeRequest = [Net.HttpWebRequest]::Create($url)
            $probeRequest.Timeout = 2000
            $probeRequest.AllowAutoRedirect = $false
            $response = $probeRequest.GetResponse()
            $statuses += [int]$response.StatusCode
        } catch [Net.WebException] {
            $response = $_.Exception.Response
            $statuses += $(if ($response) { [int]$response.StatusCode } else { 0 })
        } finally { if ($response) { $response.Dispose() } }
    }
    if ($statuses[0] -eq 401 -and $statuses[1] -eq 200) { $ready = $true; break }
    Start-Sleep -Milliseconds 500
}
if (-not $ready) {
    & (Join-Path $PSScriptRoot 'Stop-Applications.ps1')
    throw 'Owned applications failed readiness checks; inspect their private logs.'
}
Write-Output 'Owned Quarkus/Laravel ready on 18090/18091/18092 (401 auth boundary, 200 login). Credentials are not printed.'
