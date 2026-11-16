param()
. "$PSScriptRoot/Common.ps1"
$null = Get-ClinicalState
$existing = & docker ps -a --format '{{.Names}}'
if ($existing -contains $ClinicalContainer) { Assert-ClinicalContainer }
$volumes = & docker volume ls --format '{{.Name}}'
if ($volumes -contains 'clinica_laravel_fase1_pg16_data') {
    $volume = @((& docker volume inspect clinica_laravel_fase1_pg16_data | ConvertFrom-Json))[0]
    if ($volume.Labels.'clinica.environment' -ne 'laravel-phase1') { throw 'Volumen ajeno: inicio rechazado.' }
}
& docker compose -f $ClinicalCompose up -d --no-recreate --wait --pull never postgres_clinical
if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL aislado no inició correctamente.' }
Assert-ClinicalContainer
