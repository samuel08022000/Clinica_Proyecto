param()
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$backendRoot = Join-Path $projectRoot 'backend_clinica'
$state = Get-Content -LiteralPath (Join-Path $backendRoot 'target/integration-fase12/runtime.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if ($state.project -ne 'clinica-fase12' -or $state.port -ne 35441) { throw 'Invalid isolated runtime.' }
$env:CLINICA_TEST_DB_USER=$state.user
$env:CLINICA_TEST_DB_PASSWORD=$state.password
$env:CLINICA_INTEGRATION_OWNER=$state.owner
Push-Location $backendRoot
try {
    & .\mvnw.cmd -DskipTests test-compile
    if ($LASTEXITCODE -ne 0) { throw 'Fixture compilation failed.' }
    $classpath = 'target/test-classes;target/classes;target/quarkus-app/lib/main/*;target/quarkus-app/lib/boot/*'
    $env:CLINICA_TEST_JDBC_URL='jdbc:postgresql://127.0.0.1:35441/'+$state.browser
    & java -cp $classpath org.clinica.integration.Phase12IntegrationFixture
    if ($LASTEXITCODE -ne 0) { throw 'Browser fixture initialization failed.' }
    $env:CLINICA_TEST_JDBC_URL='jdbc:postgresql://127.0.0.1:35441/'+$state.legacy
    & java -cp $classpath org.clinica.integration.IsolatedIntegrationFixture
    if ($LASTEXITCODE -ne 0) { throw 'Legacy fixture initialization failed.' }
} finally { Pop-Location }
