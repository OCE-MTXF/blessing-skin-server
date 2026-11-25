# Run the synthetic browser flow against source or an authenticated release ZIP on loopback.
[CmdletBinding(DefaultParameterSetName='Source')]
param(
    [Parameter(Mandatory, ParameterSetName='Source')][string] $AppPath,
    [Parameter(Mandatory, ParameterSetName='Release')][string] $ArchivePath,
    [Parameter(Mandatory, ParameterSetName='Release')][ValidatePattern('^[a-fA-F0-9]{64}$')][string] $ExpectedSha256,
    [Parameter(Mandatory)][string] $EvidencePath,
    [Parameter(Mandatory)][string] $PhpPath,
    [Parameter(Mandatory)][string] $NginxPath,
    [Parameter(Mandatory)][string] $OpenSslPath,
    [Parameter(Mandatory)][string] $PlaywrightPath,
    [Parameter(Mandatory)][string] $BrowserPath,
    [ValidateRange(1024,65535)][int] $HttpsPort = 18487,
    [ValidateRange(1024,65535)][int] $FastCgiPort = 19487
)
$ErrorActionPreference = 'Stop'
$parent = Join-Path $env:LOCALAPPDATA ('blessing-browser-' + [Guid]::NewGuid().ToString('N'))
$lab = Join-Path $parent 'lab'
$extract = Join-Path $parent 'extracted'
$evidence = [IO.Path]::GetFullPath($EvidencePath)
if ($HttpsPort -eq $FastCgiPort -or (Test-Path -LiteralPath $evidence)) { throw 'Use distinct unused ports and a new evidence directory.' }
$playwright = (Get-Item -LiteralPath $PlaywrightPath).FullName
$version = [IO.File]::ReadAllText((Join-Path $playwright 'package.json')) | ConvertFrom-Json
if ($version.name -cne 'playwright-core' -or $version.version -cne '1.65.1') { throw 'Expected Playwright Core 1.65.1.' }
$browser = (Get-Item -LiteralPath $BrowserPath).FullName
$node = (Get-Command node.exe).Source
$curl = (Get-Command curl.exe).Source
$release = $PSCmdlet.ParameterSetName -eq 'Release'
$archiveHash = $null
if ($release) {
    $archive = (Get-Item -LiteralPath $ArchivePath).FullName
    $archiveHash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($archiveHash -cne $ExpectedSha256.ToLowerInvariant()) { throw 'Release ZIP does not match its independently authenticated checksum.' }
    [IO.Directory]::CreateDirectory($parent) | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($archive, $extract)
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -cne $archiveHash) { throw 'Release ZIP changed during extraction.' }
    $AppPath = $extract
}
$source = (Get-Item -LiteralPath $AppPath).FullName
$labScript = Join-Path $source 'deploy\windows\Lab.ps1'
if (!(Test-Path -LiteralPath $labScript -PathType Leaf)) { throw 'Expected staged application with its Windows lab script.' }

function Get-TrustedHealthStatus {
    $arguments = @('--silent', '--show-error', '--noproxy', '*', '--max-time', '105', '--resolve', "localhost:${HttpsPort}:127.0.0.1", '--cacert', (Join-Path $lab 'edge\cert.pem'), '--output', 'NUL', '--write-out', '%{http_code}', "https://localhost:$HttpsPort/healthz")
    $statusCode = & $curl @arguments
    if ($LASTEXITCODE -ne 0) { throw 'The isolated TLS health request failed.' }
    return [int] $statusCode
}

$random = [Security.Cryptography.RandomNumberGenerator]::Create()
$passwordBytes = [byte[]]::new(24)
try { $random.GetBytes($passwordBytes) } finally { $random.Dispose() }
$password = [Convert]::ToBase64String($passwordBytes)
[Array]::Clear($passwordBytes, 0, $passwordBytes.Length)
$fixtures = @{
    BS_LAB_PASSWORD = $password
    BS_LAB_EMAIL = 'browser-lab@example.test'
    BS_LAB_URL = "https://localhost:$HttpsPort"
    BS_PLAYWRIGHT_PATH = $playwright
    BS_BROWSER_EXECUTABLE = $browser
}
$previous = @{}
try {
    & $labScript Prepare -RunRoot $lab -AppPath $source -PhpPath $PhpPath -NginxPath $NginxPath -OpenSslPath $OpenSslPath `
        -HttpsPort $HttpsPort -FastCgiPort $FastCgiPort -UseShippedManifests:$release
    & $labScript Start -RunRoot $lab
    if ((Get-TrustedHealthStatus) -ne 503) { throw 'A fresh protected TLS lab did not report 503 before installation.' }
    $password | & $labScript Install -RunRoot $lab -Email $fixtures.BS_LAB_EMAIL -Nickname 'Browser Lab' -PasswordFromPipeline
    if ((Get-TrustedHealthStatus) -ne 200) { throw 'The installed lab did not report 200 over verified TLS.' }
    & $labScript Upgrade -RunRoot $lab
    if ((Get-TrustedHealthStatus) -ne 200) { throw 'The same-version upgrade did not preserve verified readiness.' }
    [IO.Directory]::CreateDirectory($evidence) | Out-Null
    @{ mode = $(if ($release) {'release ZIP'} else {'source'}); archiveSha256 = $archiveHash; httpsReadiness = @(503, 200, 200) } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $evidence 'windows-lab.json') -Encoding UTF8
    foreach ($key in $fixtures.Keys) {
        $previous[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $fixtures[$key], 'Process')
    }
    & $node (Join-Path $PSScriptRoot 'browser.cjs') $evidence
    if ($LASTEXITCODE -ne 0) { throw 'Browser verification failed; see the redacted result.' }
} finally {
    $fixtures.Clear()
    $password = $null
    foreach ($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process') }
    if (Test-Path -LiteralPath (Join-Path $lab 'lab-settings.json')) { & $labScript Stop -RunRoot $lab }
    Write-Output "Private disposable lab retained at $parent. Protect or remove it after verification."
}
