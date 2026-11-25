# Disposable loopback-only deployment check. Never pointed at a deployed DB, storage or key directory.
[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('Prepare','Start','Install','Upgrade','Stop')][string] $Action,
    [Parameter(Mandatory)][string] $RunRoot,
    [string] $AppPath,
    [string] $PhpPath,
    [string] $NginxPath,
    [string] $OpenSslPath,
    [switch] $UseShippedManifests,
    [ValidateRange(1024,65535)][int] $HttpsPort = 18486,
    [ValidateRange(1024,65535)][int] $FastCgiPort = 19486,
    [string] $Email,
    [string] $Nickname,
    [switch] $PasswordFromPipeline,
    [switch] $AdoptLegacy,
    [Parameter(ValueFromPipeline, DontShow)][object] $LabInput
)
begin {
    if ($PSBoundParameters.ContainsKey('LabInput')) { throw 'Never put a password in an argument; use protected standard input.' }
    $pipelineInput = [Collections.Generic.List[object]]::new()
}
process {
    if ($null -ne $LabInput) {
        if (!$PasswordFromPipeline -or $Action -ne 'Install' -or $pipelineInput.Count -ne 0 -or $LabInput -isnot [string]) {
            throw 'Provide exactly one protected password line to Install -PasswordFromPipeline.'
        }
        $pipelineInput.Add($LabInput)
    }
}
end {
$ErrorActionPreference = 'Stop'
if ($UseShippedManifests -and $Action -ne 'Prepare') { throw 'Use -UseShippedManifests only with Prepare and an independently verified release archive.' }
$run = [IO.Path]::GetFullPath($RunRoot).TrimEnd('\')
# nginx/Windows + CGI native filename conversion is kept outside Unicode worktrees.
if ($run -notmatch '^[A-Za-z]:\\(?:[A-Za-z0-9._ -]+\\)*[A-Za-z0-9._ -]+$') { throw 'Choose a fresh, ASCII-only local lab path.' }
$root = Join-Path $run 'app'
$state = Join-Path $run 'state'
$secrets = Join-Path $run 'identity'
$edge = Join-Path $run 'edge'
$metadata = Join-Path $run 'lab-settings.json'
$utf8 = [Text.UTF8Encoding]::new($false)

function Assert-File([string] $Path) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing required file: $Path" }
    return (Get-Item -LiteralPath $Path).FullName
}
function Invoke-LabTool([string] $Executable, [string[]] $Arguments, [string] $Failure) {
    # Windows PowerShell 5.1 turns normal diagnostic stderr into ErrorRecords when a
    # caller captures streams. Classify native success by exit status, not by stderr.
    $ErrorActionPreference = 'Continue'
    $lines = & $Executable @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "$Failure $lines" }
}
function Test-LoopbackPort([int] $Port) {
    $socket = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, $Port)
    try { $socket.Start() } finally { $socket.Stop() }
}
function Get-LabProcess([string] $pidFile, [string] $exe) {
    if (-not (Test-Path -LiteralPath $pidFile -PathType Leaf)) { return }
    $record = [IO.File]::ReadAllText($pidFile) | ConvertFrom-Json
    $process = Get-Process -Id $record.id -ErrorAction SilentlyContinue
    if ($process -and $process.Path -eq $exe -and $process.StartTime.ToUniversalTime().Ticks.ToString() -eq $record.start) {
        return $process
    }
}
function Stop-Lab([string] $pidFile, [string] $exe) {
    $process = Get-LabProcess $pidFile $exe
    if ($process) {
        Stop-Process -InputObject $process
        if (!$process.WaitForExit(5000)) { throw 'The recorded lab process did not stop.' }
    }
}
function Wait-LabListener([int] $Port, [Diagnostics.Process] $Process, [string] $Service) {
    $timer = [Diagnostics.Stopwatch]::StartNew()
    do {
        if ($Process.HasExited) { throw "$Service exited before its loopback listener was ready (exit $($Process.ExitCode))." }
        $client = [Net.Sockets.TcpClient]::new()
        try {
            $pending = $client.BeginConnect([Net.IPAddress]::Loopback, $Port, $null, $null)
            if ($pending.AsyncWaitHandle.WaitOne(250)) {
                $client.EndConnect($pending)
                return
            }
        } catch [Net.Sockets.SocketException] {} finally { $client.Dispose() }
        Start-Sleep -Milliseconds 200
    } while ($timer.Elapsed.TotalSeconds -lt 15)
    throw "$Service did not open its private loopback listener. See edge/logs in the lab directory."
}
function Start-LabProcess([string] $exe, [string] $argsLine, [string] $cwd, [string] $logFile, [string] $pidFile) {
    $process = Start-Process -FilePath $exe -ArgumentList $argsLine -WorkingDirectory $cwd -WindowStyle Hidden -PassThru -RedirectStandardOutput "$logFile.out" -RedirectStandardError "$logFile.err"
    $record = @{ id = $process.Id; start = $process.StartTime.ToUniversalTime().Ticks.ToString() } | ConvertTo-Json -Compress
    [IO.File]::WriteAllText($pidFile, $record, $utf8)
    return $process
}

if ($Action -eq 'Prepare') {
    if ($HttpsPort -eq $FastCgiPort -or (Test-Path -LiteralPath $run)) { throw 'Prepare requires a new lab directory and distinct ports.' }
    $source = (Get-Item -LiteralPath $AppPath).FullName.TrimEnd('\')
    if ($run.StartsWith($source + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Choose a lab path outside the code tree.' }
    $php = Assert-File $PhpPath
    $phpCgi = Assert-File (Join-Path (Split-Path -Parent $php) 'php-cgi.exe')
    $phpIni = Assert-File (Join-Path (Split-Path -Parent $php) 'php.ini')
    $nginx = Assert-File $NginxPath
    $openssl = Assert-File $OpenSslPath
    $mime = Assert-File (Join-Path (Split-Path -Parent $nginx) 'conf\mime.types')
    Assert-File (Join-Path $source 'vendor\autoload.php') | Out-Null
    Assert-File (Join-Path $source 'public\app\favicon.ico') | Out-Null
    $manifestPaths = @()
    if ($UseShippedManifests) {
        # Only for an authenticated release extracted to a clean directory; never reuse caches from a deployed site.
        $bootstrapPath = Get-Item -LiteralPath (Join-Path $source 'bootstrap')
        $cachePath = Get-Item -LiteralPath (Join-Path $source 'bootstrap\cache')
        foreach ($item in @((Get-Item -LiteralPath $source), $bootstrapPath, $cachePath)) {
            if (!$item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Expected extracted, local release directories.' }
        }
        foreach ($name in @('packages.php', 'services.php')) {
            $file = Get-Item -LiteralPath (Assert-File (Join-Path $cachePath.FullName $name))
            if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Refusing a redirected shipped manifest.' }
            $manifestPaths += $file.FullName
        }
    }
    foreach ($folder in @($root, $secrets, "$state\app", "$state\framework\cache\data", "$state\framework\sessions", "$state\framework\views", "$state\logs", "$state\textures", "$edge\conf", "$edge\logs", "$edge\temp")) {
        [IO.Directory]::CreateDirectory($folder) | Out-Null
    }
    # Only the shipped code/assets may be copied, never a source .env, uploads, keys or runtime caches.
    foreach ($folder in @('app','config','routes','vendor')) {
        & robocopy.exe (Join-Path $source $folder) (Join-Path $root $folder) /E /XJ /R:0 /W:0 /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "Could not copy staged $folder." }
    }
    foreach ($folder in @('bootstrap\cache','database','public','resources')) { [IO.Directory]::CreateDirectory((Join-Path $root $folder)) | Out-Null }
    foreach ($manifest in $manifestPaths) {
        $stagedManifest = Join-Path $root ('bootstrap\cache\' + [IO.Path]::GetFileName($manifest))
        Copy-Item -LiteralPath $manifest -Destination $stagedManifest
        if ((Get-FileHash -LiteralPath $manifest).Hash -cne (Get-FileHash -LiteralPath $stagedManifest).Hash) { throw 'A copied package manifest differs from the release.' }
    }
    foreach ($folder in @('database\migrations','public\app','resources\lang','resources\views','resources\misc')) {
        Copy-Item -LiteralPath (Join-Path $source $folder) -Destination (Join-Path $root (Split-Path -Parent $folder)) -Recurse
    }
    foreach ($file in @('artisan','bootstrap\app.php','public\index.php','composer.json','composer.lock')) {
        Copy-Item -LiteralPath (Join-Path $source $file) -Destination (Join-Path $root $file)
    }
    Copy-Item -LiteralPath $mime -Destination "$edge\conf\mime.types"
    $config = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'nginx.conf.template')).Replace('@PUBLIC_ROOT@', "$root\public".Replace('\','/')).Replace('@EDGE_ROOT@', $edge.Replace('\','/')).Replace('@HTTPS_PORT@', "$HttpsPort").Replace('@FASTCGI_PORT@', "$FastCgiPort")
    [IO.File]::WriteAllText("$edge\conf\nginx.conf", $config, $utf8)
    [IO.File]::WriteAllBytes("$state\lab.sqlite", [byte[]] @())
    $key = [byte[]]::new(32)
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($key) } finally { $random.Dispose() }
    [IO.File]::WriteAllText("$secrets\app-key", 'base64:' + [Convert]::ToBase64String($key), $utf8)
    $oldMsysSetting = $env:MSYS2_ARG_CONV_EXCL
    try {
        $env:MSYS2_ARG_CONV_EXCL = '*'
        Push-Location -LiteralPath $secrets
        try {
            Invoke-LabTool $openssl @('genrsa', '-out', 'oauth-private.key', '2048') 'Could not generate lab Passport private key.'
            Invoke-LabTool $openssl @('pkey', '-in', 'oauth-private.key', '-pubout', '-out', 'oauth-public.key') 'Could not generate lab Passport public key.'
        } finally { Pop-Location }
        Push-Location -LiteralPath $edge
        try {
            Invoke-LabTool $openssl @('genrsa', '-out', 'cert-key.pem', '2048') 'Could not create the temporary lab HTTPS key.'
            Invoke-LabTool $openssl @('req', '-x509', '-batch', '-key', 'cert-key.pem', '-out', 'cert.pem', '-days', '3', '-sha256', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost') 'Could not create the temporary lab HTTPS certificate.'
        } finally { Pop-Location }
    } finally { $env:MSYS2_ARG_CONV_EXCL = $oldMsysSetting }
    $settings = @{ php = $php; phpCgi = $phpCgi; phpIni = $phpIni; nginx = $nginx; tls = $HttpsPort; fcgi = $FastCgiPort }
    [IO.File]::WriteAllText($metadata, ($settings | ConvertTo-Json -Compress), $utf8)
    Write-Output "Prepared lab at $run; no application state initialized."
    return
}

$settings = [IO.File]::ReadAllText((Assert-File $metadata)) | ConvertFrom-Json
$php = Assert-File $settings.php
$phpCgi = Assert-File $settings.phpCgi
$phpIni = Assert-File $settings.phpIni
$nginx = Assert-File $settings.nginx
$edgePrefix = $edge.Replace('\','/') + '/'
if ($Action -eq 'Stop') {
    $master = "$edge\logs\nginx.pid"
    $runningWeb = Get-LabProcess "$run\nginx-process.json" $nginx
    if ($runningWeb) {
        if (!(Test-Path -LiteralPath $master -PathType Leaf) -or [IO.File]::ReadAllText($master).Trim() -ne $runningWeb.Id.ToString()) {
            throw 'Nginx PID does not match the recorded lab master; refusing to signal it.'
        }
        # The process identity and private prefix both match. Wait before stopping CGI or copying state.
        Invoke-LabTool $nginx @('-p', $edgePrefix, '-c', 'conf/nginx.conf', '-s', 'quit') 'Could not stop this lab nginx process.'
        if (!$runningWeb.WaitForExit(15000)) { throw 'The recorded nginx lab process did not stop.' }
    }
    Stop-Lab "$run\cgi-process.json" $phpCgi
    return
}

# Discard inherited application/database/cache/proxy inputs; use only lab-local settings.
$inherited = @{}
$clear = '^(APP_|BLSS_|CACHE_|DATABASE_|DB_|PHP_INI_SCAN_DIR$|LARAVEL_|PASSPORT_|SESSION_|QUEUE_|MAIL_|LOG_|REDIS_|FILESYSTEM_|PLUGINS_|TEXTURES_|AWS_|PWD_METHOD$|PHP_FCGI_|CGI_)'
$vars = @{
    APP_ENV='production'; APP_DEBUG='false'; APP_URL="https://localhost:$($settings.tls)"; APP_TRUSTED_PROXIES='';
    APP_KEY=[IO.File]::ReadAllText((Join-Path $secrets 'app-key'));
    PASSPORT_PRIVATE_KEY=[IO.File]::ReadAllText((Join-Path $secrets 'oauth-private.key'));
    PASSPORT_PUBLIC_KEY=[IO.File]::ReadAllText((Join-Path $secrets 'oauth-public.key'));
    DB_CONNECTION='sqlite'; DB_DATABASE=(Join-Path $state 'lab.sqlite'); DB_PREFIX='';
    LARAVEL_STORAGE_PATH=$state; CACHE_DRIVER='file'; SESSION_DRIVER='file'; LOG_CHANNEL='daily'; LOG_LEVEL='warning';
    MAIL_MAILER='array'; QUEUE_CONNECTION='database'; TEXTURES_DIR=(Join-Path $state 'textures'); FS_DRIVER='local';
    PHP_FCGI_MAX_REQUESTS='0'; PHP_INI_SCAN_DIR='';
}
try {
    Get-ChildItem Env: | Where-Object { $_.Name -match $clear -or $vars.ContainsKey($_.Name) } | ForEach-Object {
        $inherited[$_.Name] = $_.Value
        [Environment]::SetEnvironmentVariable($_.Name, $null, 'Process')
    }
    foreach ($name in $vars.Keys) { [Environment]::SetEnvironmentVariable($name, [string] $vars[$name], 'Process') }
    if ($Action -eq 'Start') {
        Test-LoopbackPort $settings.tls
        Test-LoopbackPort $settings.fcgi
        Invoke-LabTool $nginx @('-p', $edgePrefix, '-c', 'conf/nginx.conf', '-t') 'nginx configuration check failed.'
        try {
            $cgiArgs = '-c "{0}" -d variables_order=EGPCS -d cgi.force_redirect=0 -d cgi.fix_pathinfo=0 -d expose_php=0 -d display_errors=0 -d max_execution_time=90 -d upload_max_filesize=6M -d post_max_size=8M -b 127.0.0.1:{1}' -f $phpIni, $settings.fcgi
            $cgi = Start-LabProcess $phpCgi $cgiArgs $root "$edge\logs\cgi" "$run\cgi-process.json"
            Wait-LabListener $settings.fcgi $cgi 'PHP CGI'
            $webArgs = '-p "{0}" -c conf/nginx.conf' -f $edgePrefix
            $web = Start-LabProcess $nginx $webArgs $edge "$edge\logs\nginx-process" "$run\nginx-process.json"
            Wait-LabListener $settings.tls $web 'nginx'
        } catch {
            Stop-Lab "$run\nginx-process.json" $nginx
            Stop-Lab "$run\cgi-process.json" $phpCgi
            throw
        }
        Write-Output "Lab listening on https://localhost:$($settings.tls) (temporary localhost certificate at $edge\cert.pem)."
    } else {
        Push-Location -LiteralPath $root
        try {
            $phpArgs = @('-c', $phpIni, '-d', 'variables_order=EGPCS', '-d', 'display_errors=0', 'artisan')
            if ($Action -eq 'Install') {
                if (!$Email -or !$Nickname) { throw 'Specify -Email and -Nickname (never pass a password on argv).'; }
                $phpArgs += @('bs:install', $Email, $Nickname)
                if ($PasswordFromPipeline) {
                    if ($pipelineInput.Count -ne 1 -or $pipelineInput[0] -isnot [string]) { throw 'Provide exactly one protected password line.' }
                    $phpArgs += '--password-stdin'
                    $pipelineInput[0] | & $php @phpArgs
                } else { & $php @phpArgs }
            } else {
                $phpArgs += 'bs:upgrade'
                if ($AdoptLegacy) { $phpArgs += '--adopt-legacy' }
                & $php @phpArgs
            }
            if ($LASTEXITCODE) { throw "Offline CLI action $Action failed." }
        } finally { Pop-Location }
    }
} finally {
    foreach ($name in $vars.Keys) { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
    foreach ($name in $inherited.Keys) { [Environment]::SetEnvironmentVariable($name, [string] $inherited[$name], 'Process') }
}
}
