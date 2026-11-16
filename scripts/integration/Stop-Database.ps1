param()
$ErrorActionPreference='Stop'
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$testRoot=Join-Path $projectRoot 'backend_clinica/target/integration-fase12'
if(Test-Path -LiteralPath (Join-Path $testRoot 'processes.json')){throw 'Stop the registered integration applications first.'}
$state=Get-Content -LiteralPath (Join-Path $testRoot 'runtime.json') -Raw -Encoding UTF8|ConvertFrom-Json
if($state.project -ne 'clinica-fase12' -or $state.port -ne 35441){throw 'Invalid integration project.'}
$container=(& docker inspect clinica-fase12-postgres_test-1 --format '{{json .Config.Labels}}')|ConvertFrom-Json
if($LASTEXITCODE -ne 0 -or $container.'com.docker.compose.project' -ne 'clinica-fase12' -or $container.'clinica.environment' -ne 'isolated-integration-fase12'){throw 'Refusing to stop a container without the exclusive integration labels.'}
$temporary=(& docker inspect clinica-fase12-postgres_test-1 --format '{{json .HostConfig.Tmpfs}}')|ConvertFrom-Json
if(!$temporary.PSObject.Properties['/var/lib/postgresql/data']){throw 'Expected ephemeral PostgreSQL storage is missing.'}
$env:CLINICA_TEST_DB_PASSWORD=$state.password
& docker compose --env-file (Join-Path $testRoot 'compose.env') -f (Join-Path $projectRoot 'compose.integration.yml') -p clinica-fase12 stop postgres_test
if($LASTEXITCODE -ne 0){throw 'Could not stop the exclusive database container.'}
Write-Output 'Only the exclusive tmpfs PostgreSQL was stopped. No shared volumes were removed.'
