param()
$ErrorActionPreference='Stop'
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$backendRoot=Join-Path $projectRoot 'backend_clinica'
$testRoot=Join-Path $backendRoot 'target/integration-fase12'
$statePath=Join-Path $testRoot 'runtime.json'
$state=Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
if($state.project -ne 'clinica-fase12' -or $state.port -ne 35441 -or (Test-Path -LiteralPath (Join-Path $testRoot 'processes.json'))){throw 'Stop only the owned applications before creating a fresh browser dataset.'}
$database='clinica_fase12_e2e_'+[Guid]::NewGuid().ToString('N').Substring(0,12)
$env:CLINICA_TEST_DB_PASSWORD=$state.password
$compose=@('compose','--env-file',(Join-Path $testRoot 'compose.env'),'-f',(Join-Path $projectRoot 'compose.integration.yml'),'-p','clinica-fase12','exec','-T','postgres_test','psql','-U','clinica_test','-d',$state.tests,'-v','ON_ERROR_STOP=1')
& docker @compose -c "CREATE DATABASE $database"
if($LASTEXITCODE -ne 0){throw 'Could not create fresh isolated dataset.'}
& docker @compose -c "COMMENT ON DATABASE $database IS 'isolated-fase12:$($state.owner)'"
if($LASTEXITCODE -ne 0){throw 'Could not mark its owner.'}
$state.browser=$database
[IO.File]::WriteAllText($statePath,($state|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$env:CLINICA_TEST_JDBC_URL='jdbc:postgresql://127.0.0.1:35441/'+$database
$env:CLINICA_TEST_DB_USER=$state.user
$env:CLINICA_INTEGRATION_OWNER=$state.owner
Push-Location $backendRoot
try{
    $classpath='target/test-classes;target/classes;target/quarkus-app/lib/main/*;target/quarkus-app/lib/boot/*'
    & javac -cp $classpath -d target/test-classes src/test/java/org/clinica/integration/Phase12IntegrationFixture.java
    if($LASTEXITCODE -ne 0){throw 'Fixture compilation failed.'}
    & java -cp $classpath org.clinica.integration.Phase12IntegrationFixture
    if($LASTEXITCODE -ne 0){throw 'Fixture creation failed.'}
}finally{Pop-Location}
Write-Output 'A fresh owned database was created; previous datasets were not deleted.'
