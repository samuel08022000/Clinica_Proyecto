param()
$ErrorActionPreference='Stop'
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$backendRoot=Join-Path $projectRoot 'backend_clinica'
$testRoot=Join-Path $backendRoot 'target/integration-fase12'
$statePath=Join-Path $testRoot 'runtime.json'
$state=Get-Content -LiteralPath $statePath -Raw -Encoding UTF8|ConvertFrom-Json
if($state.project -ne 'clinica-fase12' -or $state.port -ne 35441){throw 'Invalid isolated runtime.'}
$pidPath=Join-Path $testRoot 'processes.json'
if(Test-Path -LiteralPath $pidPath){$owned=Get-Content -LiteralPath $pidPath -Raw -Encoding UTF8|ConvertFrom-Json;if($owned.dataset -eq 'legacy'){throw 'Stop the legacy applications before replacing their dataset.'}}
$database='clinica_fase9_live_f12'+[Guid]::NewGuid().ToString('N').Substring(0,12)
$env:CLINICA_TEST_DB_PASSWORD=$state.password
$compose=@('compose','--env-file',(Join-Path $testRoot 'compose.env'),'-f',(Join-Path $projectRoot 'compose.integration.yml'),'-p','clinica-fase12','exec','-T','postgres_test','psql','-U','clinica_test','-d',$state.tests,'-v','ON_ERROR_STOP=1')
& docker @compose -c "CREATE DATABASE $database"
if($LASTEXITCODE -ne 0){throw 'Could not create a fresh exclusive legacy dataset.'}
& docker @compose -c "COMMENT ON DATABASE $database IS 'isolated-fase12:$($state.owner)'"
if($LASTEXITCODE -ne 0){throw 'Could not mark its owner.'}
$state.legacy=$database
[IO.File]::WriteAllText($statePath,($state|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$env:CLINICA_TEST_JDBC_URL='jdbc:postgresql://127.0.0.1:35441/'+$database
$env:CLINICA_TEST_DB_USER=$state.user
Push-Location $backendRoot
try{& java -cp 'target/test-classes;target/classes;target/quarkus-app/lib/main/*;target/quarkus-app/lib/boot/*' org.clinica.integration.IsolatedIntegrationFixture;if($LASTEXITCODE -ne 0){throw 'Legacy fixture creation failed.'}}finally{Pop-Location}
Write-Output 'A fresh legacy test database was prepared without deleting previous datasets.'
