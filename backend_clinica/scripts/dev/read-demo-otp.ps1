param(
    [string]$QuarkusApiUrl = 'http://127.0.0.1:8080/api/v1'
)

$ErrorActionPreference = 'Stop'
$uri = [Uri]$QuarkusApiUrl
if ($uri.Scheme -ne 'http' -or $uri.Host -notin @('127.0.0.1', 'localhost')) {
    throw 'La consulta DEMO solo se permite contra Quarkus local por HTTP.'
}

$destination = Read-Host 'Correo registrado'
$secureKey = Read-Host 'OTP_DEMO_ACCESS_KEY' -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)

try {
    $plainKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    $endpoint = $QuarkusApiUrl.TrimEnd('/') + '/auth/otp/demo-code'
    $body = @{ destination = $destination } | ConvertTo-Json -Compress
    $response = Invoke-RestMethod -Method Post -Uri $endpoint -Headers @{ 'X-Demo-Otp-Key' = $plainKey } -ContentType 'application/json' -Body $body
    if (-not $response.data.code) {
        throw 'Quarkus no devolvio un codigo DEMO valido.'
    }
    Write-Output ('Codigo OTP DEMO: ' + $response.data.code)
    Write-Output ('Expira en: ' + $response.data.expiresAt)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    Remove-Variable plainKey,secureKey -ErrorAction SilentlyContinue
}
