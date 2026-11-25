# Optional wrapper around the PHP release builder. Does not publish or deploy.
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string] $ComposerPhar,
    [string] $Php = 'php',
    [string] $OutputDirectory = (Join-Path (Split-Path -Parent $PSScriptRoot) 'target/release'),
    [Parameter(Mandatory)][string] $ExpectedVersion,
    [switch] $ValidateOnly
)
$ErrorActionPreference = 'Continue' # Windows PowerShell 5 can convert native stderr to ErrorRecords.
$repo = (Resolve-Path -LiteralPath (Split-Path -Parent $PSScriptRoot)).Path
$composer = (Resolve-Path -LiteralPath $ComposerPhar -ErrorAction Stop).Path
$output = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutputDirectory)
$arguments = @((Join-Path $PSScriptRoot 'release.php'), 'build', $repo, $output, $ExpectedVersion, $composer)
if ($ValidateOnly) { $arguments += '--validate-only' }
& $Php @arguments
exit $LASTEXITCODE
