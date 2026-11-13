param()
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$pidPath = Join-Path $projectRoot 'backend_clinica/target/integration-fase12/processes.json'
if (!(Test-Path -LiteralPath $pidPath)) { Write-Output 'No owned application processes are registered.'; exit 0 }
$owned = Get-Content -LiteralPath $pidPath -Raw -Encoding UTF8 | ConvertFrom-Json
$started = [DateTime]::Parse($owned.startedAt).AddSeconds(-5)
foreach ($name in @('quarkus','laravel','faulty')) {
    $ownedId = [int]$owned.$name
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$ownedId"
    if (!$process) { continue }
    if ($process.CreationDate -lt $started -or ($name -eq 'quarkus' -and $process.CommandLine -notmatch 'mvnw.cmd -o quarkus:dev') -or ($name -ne 'quarkus' -and $process.CommandLine -notmatch 'tests[/\\]Integration[/\\]router.php')) {
        throw 'Process identity changed; refusing to terminate it.'
    }
    & taskkill /PID $ownedId /T /F | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not stop an owned process.' }
}
Remove-Item -LiteralPath $pidPath
Write-Output 'Only the registered integration application processes were stopped.'
