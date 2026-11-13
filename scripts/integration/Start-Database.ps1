param()
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$testRoot = Join-Path $projectRoot 'backend_clinica/target/integration-fase12'
$statePath = Join-Path $testRoot 'runtime.json'
if (Test-Path -LiteralPath $statePath) { throw 'An isolated runtime already exists. Inspect it before starting another run.' }
$probe = [Net.Sockets.TcpClient]::new()
try {
    $connection = $probe.ConnectAsync('127.0.0.1', 35441)
    if ($connection.Wait(600) -and $probe.Connected) { throw 'Port 35441 is in use; no database was modified.' }
} catch [AggregateException] { } finally { $probe.Dispose() }
New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
$random = New-Object byte[] 32
$generator = [Security.Cryptography.RandomNumberGenerator]::Create()
$generator.GetBytes($random)
$password = [Convert]::ToBase64String($random)
$generator.Dispose()
$suffix = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$owner = [Guid]::NewGuid().ToString()
$emptyEnv = Join-Path $testRoot 'compose.env'
[IO.File]::WriteAllText($emptyEnv, '')
$env:CLINICA_TEST_DB_PASSWORD = $password
$composeFile = Join-Path $projectRoot 'compose.integration.yml'
try {
    & docker compose --env-file $emptyEnv -f $composeFile -p clinica-fase12 up -d --wait postgres_test
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the exclusive PostgreSQL container.' }
    $databases = @('clinica_fase9_live_f12', ('clinica_fase12_e2e_' + $suffix))
    foreach ($database in $databases) {
        & docker compose --env-file $emptyEnv -f $composeFile -p clinica-fase12 exec -T postgres_test psql -U clinica_test -d clinica_fase12_tests -v ON_ERROR_STOP=1 -c "CREATE DATABASE $database"
        if ($LASTEXITCODE -ne 0) { throw 'Could not create an exclusive fixture database.' }
        & docker compose --env-file $emptyEnv -f $composeFile -p clinica-fase12 exec -T postgres_test psql -U clinica_test -d clinica_fase12_tests -v ON_ERROR_STOP=1 -c "COMMENT ON DATABASE $database IS 'isolated-fase12:$owner'"
        if ($LASTEXITCODE -ne 0) { throw 'Could not mark the fixture database owner.' }
    }
    $state = [ordered]@{ project = 'clinica-fase12'; host = '127.0.0.1'; port = 35441; user = 'clinica_test'; password = $password; owner = $owner; tests = 'clinica_fase12_tests'; legacy = $databases[0]; browser = $databases[1] }
    [IO.File]::WriteAllText($statePath, ($state | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
    & docker compose --env-file $emptyEnv -f $composeFile -p clinica-fase12 exec -T postgres_test psql -U clinica_test -d clinica_fase12_tests -At -c 'SELECT version()'
    Write-Output 'Exclusive PostgreSQL initialized. Credentials remain in ignored target storage.'
} finally {
    Remove-Item Env:CLINICA_TEST_DB_PASSWORD -ErrorAction SilentlyContinue
}
