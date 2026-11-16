param()
. "$PSScriptRoot/Common.ps1"
if ((Test-Path -LiteralPath (Join-Path $ClinicalPrivate 'runtime.json')) -or (Test-Path -LiteralPath (Join-Path $ClinicalPrivate 'bootstrap-password.txt'))) { throw 'El entorno privado ya existe; no se sobrescriben secretos.' }
& docker info --format '{{.ServerVersion}}'
if ($LASTEXITCODE -ne 0) { throw 'Inicie Docker Desktop.' }
& docker image inspect postgres:16-alpine --format '{{.Id}}'
if ($LASTEXITCODE -ne 0) { throw 'Se requiere una imagen PostgreSQL 16 disponible localmente. Este script no descarga imágenes.' }
$names = & docker ps -a --format '{{.Names}}'
$volumes = & docker volume ls --format '{{.Name}}'
if ($names -contains $ClinicalContainer -or $volumes -contains 'clinica_laravel_fase1_pg16_data') { throw 'Ya hay recursos con esos nombres; inspección manual requerida.' }
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,35443)
try { $listener.Start() } finally { $listener.Stop() }
New-Item -ItemType Directory -Path $ClinicalPrivate -Force | Out-Null
# Windows: solo el usuario actual y SYSTEM pueden acceder a secretos y respaldos.
$acl = Get-Acl -LiteralPath $ClinicalPrivate
$acl.SetAccessRuleProtection($true,$false)
foreach ($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User, [Security.Principal.SecurityIdentifier]::new('S-1-5-18'))) {
    $rule = [Security.AccessControl.FileSystemAccessRule]::new($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
    $acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $ClinicalPrivate -AclObject $acl
function New-PrivateSecret {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-','').ToLowerInvariant()
}
$state = [ordered]@{container=$ClinicalContainer; host='127.0.0.1'; port=35443; database='clinica_laravel'; tests='clinica_laravel_phase1_tests'; owner='clinica_owner'; ownerPassword=(New-PrivateSecret); username='clinica_app'; password=(New-PrivateSecret)}
[IO.File]::WriteAllText((Join-Path $ClinicalPrivate 'bootstrap-password.txt'), (New-PrivateSecret), [Text.UTF8Encoding]::new($false))
[IO.File]::WriteAllText((Join-Path $ClinicalPrivate 'runtime.json'), ($state | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
Write-Output 'Secretos nuevos creados en almacenamiento local ignorado y con ACL restringida. Ningún .env existente fue alterado.'
