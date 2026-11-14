param()
$ErrorActionPreference='Stop'
$OutputEncoding=[Text.UTF8Encoding]::new($false)
$projectRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$testRoot=Join-Path $projectRoot 'backend_clinica/target/integration-fase12'
$state=Get-Content -LiteralPath (Join-Path $testRoot 'runtime.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$fixture=Get-Content -LiteralPath (Join-Path $testRoot 'browser-fixture.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$report=Get-Content -LiteralPath (Join-Path $testRoot 'browser-e2e.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if($state.project -ne 'clinica-fase12' -or $state.browser -ne $fixture.database -or $report.database -ne $fixture.database -or $fixture.database -notmatch '^clinica_fase12_e2e_[a-f0-9]{12}$'){throw 'Not an exclusively owned browser database.'}
$parameters=[ordered]@{doctor=$report.evidence.doctorCode;staff=$report.evidence.staffCode;specialty=$report.evidence.specialtyCode;schedule=$report.evidence.scheduleCode;reservation=$report.evidence.reservedAppointment;secretary=$fixture.users.ROL002.codSec;actor=$fixture.users.ROL002.id;qrAppointment=$fixture.codCitQr;absenceAppointment=$fixture.codCitAbsence;block=$report.evidence.blockCode;sanction=$report.evidence.sanctionCode;admin=$fixture.users.ROL004.id;staleSlot=$report.evidence.staleSlot;concurrentSlot=$report.evidence.concurrentSlot}
$arguments=@('compose','--env-file',(Join-Path $testRoot 'compose.env'),'-f',(Join-Path $projectRoot 'compose.integration.yml'),'-p','clinica-fase12','exec','-T','postgres_test','psql','-U','clinica_test','-d',$fixture.database,'-At','-v','ON_ERROR_STOP=1')
foreach($item in $parameters.GetEnumerator()){if($item.Value -notmatch '^[A-Z]+[0-9]+$'){throw 'Missing or invalid synthetic audit identifier.'};$arguments+=@('-v',($item.Key+'='+$item.Value))}
$env:CLINICA_TEST_DB_PASSWORD=$state.password
$sql=Get-Content -LiteralPath (Join-Path $projectRoot 'backend_clinica/src/test/resources/integration-fase12-integrity.sql') -Raw -Encoding UTF8
$auditOutput=$sql | & docker @arguments
if($LASTEXITCODE -ne 0){throw 'Read-only database audit failed.'}
$objects=@($auditOutput | Where-Object{$_ -match '^\{'} | ForEach-Object{$_|ConvertFrom-Json})
if($objects.Count -ne 2){throw 'Unexpected audit format.'}
[IO.File]::WriteAllText((Join-Path $testRoot 'database-audit.json'),($objects|ConvertTo-Json -Depth 5),[Text.UTF8Encoding]::new($false))
$metadata=$objects[0]
if($metadata.baselineWithoutDemo -ne 1 -or $metadata.emailOtpMigration -ne 1 -or $metadata.historicalDemoAccounts -ne 0){throw 'Expected B9 + R without historical demo accounts.'}
if($metadata.successfulMigrations -ne 2 -or $metadata.failedMigrations -ne 0 -or $metadata.unvalidatedConstraints -ne 0 -or $metadata.disabledTriggers -ne 0 -or $metadata.orphanAppointments -ne 0 -or $metadata.duplicateEffectiveSlots -ne 0 -or $metadata.invalidStoredQr -ne 0 -or !$metadata.clinicalDateCorrect -or $metadata.administrativeViews -ne 2){throw 'An integrity assertion failed.'}
$failed=@($objects[1].PSObject.Properties | Where-Object{$_.Value -ne $true})
if($failed.Count -gt 0){$failed.Name | Write-Output;throw 'A persisted workflow assertion failed.'}
Write-Output ('Read-only PostgreSQL audit passed: metadata and '+@($objects[1].PSObject.Properties).Count+' persisted workflow assertions.')
