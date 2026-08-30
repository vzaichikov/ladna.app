$ErrorActionPreference = 'Stop'
$project = Join-Path $PSScriptRoot 'Ladna.BattleMeter\Ladna.BattleMeter.csproj'
$localSdk = Join-Path $env:LOCALAPPDATA 'Ladna\Dotnet10Sdk\dotnet.exe'
$dotnet = if (Test-Path $localSdk) { $localSdk } else { 'dotnet' }

$publish = Start-Process -FilePath $dotnet -ArgumentList @(
    'publish',
    $project,
    '--configuration',
    'Release',
    '--runtime',
    'win-x64',
    '--self-contained',
    'true'
) -Wait -PassThru -NoNewWindow

if ($publish.ExitCode -ne 0) {
    throw "dotnet publish failed with exit code $($publish.ExitCode)."
}

$output = Join-Path $PSScriptRoot 'Ladna.BattleMeter\bin\Release\net10.0-windows10.0.17763.0\win-x64\publish\Ladna.BattleMeter.exe'
Write-Host "Published: $output"
