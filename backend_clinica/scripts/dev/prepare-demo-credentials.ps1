param()

$ErrorActionPreference = 'Stop'
$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpTool = Join-Path $scriptDirectory 'prepare_demo_credentials.php'

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP no esta disponible en PATH.'
}

$first = Read-Host 'Nueva contrasena comun para los cuatro usuarios demo (minimo 12 caracteres)' -AsSecureString
$second = Read-Host 'Confirma la contrasena demo' -AsSecureString
$firstBstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($first)
$secondBstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($second)

try {
    $firstPlain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($firstBstr)
    $secondPlain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($secondBstr)

    if ($firstPlain -cne $secondPlain) {
        throw 'Las contrasenas no coinciden.'
    }

    $env:CLINICA_DEMO_PASSWORD_ONCE = $firstPlain
    & php $phpTool
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo preparar la migracion V3 local.'
    }
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($firstBstr)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($secondBstr)
    Remove-Item Env:CLINICA_DEMO_PASSWORD_ONCE -ErrorAction SilentlyContinue
    Remove-Variable firstPlain,secondPlain,first,second -ErrorAction SilentlyContinue
}
