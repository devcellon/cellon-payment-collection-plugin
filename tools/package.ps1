# Builds the installable zip: dist\cellon-payment-collection-<version>.zip
# Contains the plugin only (no tools). No build step: the browser code is the plain JavaScript in assets\js.
# Zip entry names use forward slashes (WordPress' unzip on Linux needs them). Run from anywhere:
#   powershell -ExecutionPolicy Bypass -File tools\package.ps1
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$plugin = Split-Path -Parent $PSScriptRoot
$main = Join-Path $plugin 'cellon-payment-collection.php'
$version = (Select-String -Path $main -Pattern "Version:\s+([0-9.]+)").Matches[0].Groups[1].Value

foreach ($required in @('assets\js\core.js', 'assets\js\sequence.js', 'assets\js\checkout.js')) {
    if (-not (Test-Path (Join-Path $plugin $required))) { throw "$required is missing." }
}

$out = Join-Path $plugin 'dist'
New-Item -ItemType Directory -Force $out | Out-Null
$zip = Join-Path $out "cellon-payment-collection-$version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

$archive = [System.IO.Compression.ZipFile]::Open($zip, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($item in @('cellon-payment-collection.php', 'uninstall.php', 'README.md', 'includes', 'assets', 'docs')) {
        $path = Join-Path $plugin $item
        $files = if (Test-Path $path -PathType Container) { Get-ChildItem -Recurse -File $path } else { Get-Item $path }
        foreach ($file in $files) {
            if ($file.Extension -eq '.map') { continue }
            $relative = $file.FullName.Substring($plugin.Length).TrimStart('\', '/') -replace '\\', '/'
            $entry = 'cellon-payment-collection/' + $relative
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    }
} finally {
    $archive.Dispose()
}
Write-Output "Wrote $zip"
