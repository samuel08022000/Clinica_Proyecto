param()
. "$PSScriptRoot/Common.ps1"
$null = Get-ClinicalState
$raw = & docker inspect clinica_postgres_nuevo
if ($LASTEXITCODE -ne 0) { throw 'No se puede comprobar el contenedor habitual.' }
$info = @($raw | ConvertFrom-Json)[0]
if ($info.State.Running) { throw 'La comparación física exige que el contenedor habitual siga detenido.' }
$backupDir = Join-Path $ClinicalPrivate 'backups'
$backup = Get-ChildItem -LiteralPath $backupDir -Filter 'db_clinica_pg15_cold_*.tar.gz' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (!$backup -or $backup.Name -notmatch '^db_clinica_pg15_cold_[0-9_]+[a-f0-9]{8}\.tar\.gz$') { throw 'No se encontró un respaldo propio.' }
$expected = (Get-Content -LiteralPath ($backup.FullName + '.sha256') -Raw).Split(' ')[0]
if ((Get-FileHash -LiteralPath $backup.FullName -Algorithm SHA256).Hash -ne $expected) { throw 'Cambió el respaldo.' }
# Comparación del archivo TAR descomprimido con un nuevo flujo TAR del volumen RO.
$archive = & docker run --rm --pull never --network none --mount "type=bind,source=$backupDir,target=/backup,readonly" postgres:15 sh -c ('gzip -dc /backup/' + $backup.Name + ' | sha256sum')
if ($LASTEXITCODE -ne 0) { throw 'No se pudo leer el respaldo.' }
$current = & docker run --rm --pull never --network none --mount 'type=volume,source=clinica_postgres_nuevo_data,target=/source,readonly' postgres:15 sh -c 'tar -cf - -C /source . | sha256sum'
if ($LASTEXITCODE -ne 0 -or $archive -cne $current) { throw 'El contenido o metadatos del volumen habitual difieren del respaldo; inspeccione antes de continuar.' }
Write-Output 'El volumen habitual coincide con el respaldo inicial: contenido y metadatos TAR sin cambios. Contenedor habitual detenido.'
