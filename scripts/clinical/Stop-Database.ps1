param()
. "$PSScriptRoot/Common.ps1"
Assert-ClinicalContainer
& docker compose -f $ClinicalCompose stop postgres_clinical
if ($LASTEXITCODE -ne 0) { throw 'No se pudo detener PostgreSQL aislado.' }
