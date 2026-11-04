param(
    [string]$QuarkusApiUrl = 'http://127.0.0.1:8080/api/v1'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..\..')).Path
$frontendRoot = Join-Path $projectRoot 'frontend_clinica'
$artisanPath = Join-Path $frontendRoot 'artisan'

if (-not (Test-Path -LiteralPath $artisanPath -PathType Leaf)) {
    throw "No se encontro artisan en la ruta esperada: $artisanPath"
}

$parsedApiUrl = $null
if (-not [Uri]::TryCreate($QuarkusApiUrl, [UriKind]::Absolute, [ref]$parsedApiUrl) -or
    $parsedApiUrl.Scheme -ne 'http' -or
    $parsedApiUrl.Host -notin @('127.0.0.1', 'localhost') -or
    $parsedApiUrl.AbsolutePath.TrimEnd('/') -ne '/api/v1') {
    throw 'QuarkusApiUrl debe apuntar a http://127.0.0.1:<puerto>/api/v1 o http://localhost:<puerto>/api/v1.'
}

$secure = Read-Host 'Contrasena comun de los cuatro usuarios demo' -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)

try {
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    $env:CLINICA_DEMO_PASSWORD_ONCE = $plain
    $env:RUN_DEMO_LOGIN_INTEGRATION = '1'
    $env:QUARKUS_API_URL = $QuarkusApiUrl.TrimEnd('/')

    Push-Location $frontendRoot
    try {
        & php artisan test --filter=DemoLoginIntegrationTest
        if ($LASTEXITCODE -ne 0) {
            throw 'Fallo la verificacion real de los usuarios demo.'
        }
    } finally {
        Pop-Location
    }

    Write-Output 'REAL_LOGIN_VERIFICATION=PASSED'
    Write-Output 'VALID_USERS=4/4'
    Write-Output 'WRONG_PASSWORD_REJECTED=YES'
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    Remove-Item Env:CLINICA_DEMO_PASSWORD_ONCE,Env:RUN_DEMO_LOGIN_INTEGRATION,Env:QUARKUS_API_URL -ErrorAction SilentlyContinue
    Remove-Variable plain,secure -ErrorAction SilentlyContinue
}
