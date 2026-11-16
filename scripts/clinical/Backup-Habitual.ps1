param()
. "$PSScriptRoot/Common.ps1"
$null = Get-ClinicalState
$raw = & docker inspect clinica_postgres_nuevo
if ($LASTEXITCODE -ne 0) { throw 'No se encontró el contenedor habitual esperado.' }
$info = @($raw | ConvertFrom-Json)[0]
if ($info.State.Running -or @($info.Mounts | Where-Object {$_.Name -eq 'clinica_postgres_nuevo_data'}).Count -ne 1) {
    throw 'El respaldo físico requiere el volumen habitual identificado y el contenedor detenido. No se detendrá automáticamente.'
}
$backupDir = Join-Path $ClinicalPrivate 'backups'
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
$name = 'db_clinica_pg15_cold_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '_' + [guid]::NewGuid().ToString('N').Substring(0,8) + '.tar.gz'
$destination = Join-Path $backupDir $name
if (Test-Path -LiteralPath $destination) { throw 'No se sobrescriben respaldos.' }
# El auxiliar no inicia PostgreSQL ni tiene red; monta el origen SOLO LECTURA.
& docker run --rm --pull never --network none --mount 'type=volume,source=clinica_postgres_nuevo_data,target=/source,readonly' --mount "type=bind,source=$backupDir,target=/backup" postgres:15 tar -czf "/backup/$name" -C /source .
if ($LASTEXITCODE -ne 0) { throw 'Falló el respaldo físico.' }
& docker run --rm --pull never --network none --mount "type=bind,source=$backupDir,target=/backup,readonly" postgres:15 gzip -t "/backup/$name"
if ($LASTEXITCODE -ne 0) { throw 'El respaldo no superó gzip -t.' }
$entries = & docker run --rm --pull never --network none --mount "type=bind,source=$backupDir,target=/backup,readonly" postgres:15 tar -tzf "/backup/$name"
if ($LASTEXITCODE -ne 0 -or $entries -notcontains './PG_VERSION' -or $entries -notcontains './global/pg_control') { throw 'Faltan archivos esenciales en el respaldo.' }
$hash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash
[IO.File]::WriteAllText(($destination + '.sha256'), "$hash  $name`n", [Text.UTF8Encoding]::new($false))
Write-Output "Respaldo frío íntegro: $name; $(@($entries).Count) entradas; SHA-256 $hash. Restauración de arranque no ejecutada. Solo compatible con PostgreSQL 15."
