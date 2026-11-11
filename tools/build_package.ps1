<#
    Rebuild dist/ and the release zip from the source tree.

    Output: dist\stamps4zencart_vX.Y.Z\ and, one level above the repository,
    stamps4zencart_vX.Y.Z.zip.

    Nothing outside the lists below goes in: no .git, no tests, no dist, and
    never anything from the folder above the repository (it holds the private
    Stamps.com credentials). The zip is also scanned for secret-looking names
    before it is kept.
#>

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent

$pluginDir = @(Get-ChildItem (Join-Path $root 'zc_plugins\Stamps4ZenCart\v*') -Directory |
               Where-Object { Test-Path (Join-Path $_.FullName 'manifest.php') })
if ($pluginDir.Count -ne 1) {
    throw "expected one plugin version directory under $root\zc_plugins\Stamps4ZenCart, found $($pluginDir.Count)"
}

$version = $pluginDir[0].Name
$name    = "stamps4zencart_$version"
"building : $name"
"from     : $root"
$dist    = Join-Path $root "dist\$name"
$zip     = Join-Path (Split-Path $root -Parent) "$name.zip"

# What goes in the package, and nothing else.
$topLevel = @('CHANGELOG.md', 'LICENSE', 'README.md')
$docs     = @('COMPATIBILITY.md', 'CONFIGURATION.md', 'CUSTOMIZING.md', 'INSTALL.md')

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Path $dist -Force | Out-Null

foreach ($f in $topLevel) { Copy-Item (Join-Path $root $f) (Join-Path $dist $f) }

New-Item -ItemType Directory -Path (Join-Path $dist 'docs') -Force | Out-Null
foreach ($f in $docs) { Copy-Item (Join-Path $root "docs\$f") (Join-Path $dist "docs\$f") }

# The whole plugin, in its zc_plugins path, exactly as it must be uploaded.
Copy-Item (Join-Path $root 'zc_plugins') (Join-Path $dist 'zc_plugins') -Recurse

# README.md is at the package root (above) and inside the plugin (in zc_plugins); the two
# must be the same file, which tests/manifest_check.php also checks.
if ((Get-FileHash (Join-Path $dist 'README.md')).Hash -ne (Get-FileHash (Join-Path $dist "zc_plugins\Stamps4ZenCart\$version\README.md")).Hash) {
    throw 'README.md at the root and in the plugin differ'
}

# Never ship a credential: no .env files, and none of the SERA_ key names.
$files = Get-ChildItem $dist -Recurse -File | Sort-Object FullName
$bad = @($files | Where-Object { $_.Extension -eq '.env' -or (Select-String -Path $_.FullName -Pattern 'SERA_CLIENT_(ID|SECRET)' -Quiet) })
if ($bad.Count -gt 0) { throw "refusing to package: $($bad.FullName -join ', ')" }

if (Test-Path $zip) { Remove-Item $zip -Force }
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

# Built entry by entry rather than with Compress-Archive, so the count is checked
# and every entry uses forward slashes.
$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
foreach ($f in $files) {
    $entryName = "$name/" + $f.FullName.Substring($dist.Length + 1).Replace('\', '/')
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $archive, $f.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
}
$archive.Dispose()

$verify = [System.IO.Compression.ZipFile]::OpenRead($zip)
$entryCount = $verify.Entries.Count
$verify.Dispose()

"dist files : $($files.Count)"
"zip entries: $entryCount"
if ($entryCount -ne $files.Count) { throw "zip is short $($files.Count - $entryCount) file(s)" }
"zip bytes  : $((Get-Item $zip).Length)"
"sha256     : $((Get-FileHash $zip -Algorithm SHA256).Hash.ToLower())"
"zip        : $zip"
