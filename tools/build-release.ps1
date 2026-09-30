<#
  Erzeugt die Update-Pakete in .\dist :
    zeiterfassung-client-<ver>.zip   -> im Webpanel unter "Updates" hochladen (Client-Release)
    webpanel-<ver>.zip               -> auf einem HTTPS-Server bereitstellen ODER im Panel manuell hochladen
    manifest.json                    -> neben das Panel-ZIP legen; dessen URL als PANEL_UPDATE_URL in die .env

  Versionen: Client = <Version> in client\Zeiterfassung.csproj, Panel = webpanel\VERSION (vorher hochzÃ¤hlen!)
  Beispiel:  .\tools\build-release.ps1 -BaseUrl https://updates.example.com/zeiterfassung -Notes "Dienstplan-Fixes"
#>
param(
    [string]$BaseUrl = "https://DEIN-SERVER/zeiterfassung",
    [string]$Notes = ""
)
$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$dist = Join-Path $root "dist"
$work = Join-Path $env:TEMP ("zeit-build-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force $dist, $work | Out-Null

$clientVer = ([xml](Get-Content (Join-Path $root "client\Zeiterfassung.csproj"))).Project.PropertyGroup.Version | Where-Object { $_ } | Select-Object -First 1
$panelVer = (Get-Content (Join-Path $root "webpanel\VERSION") -Raw).Trim()
if ($clientVer -notmatch '^\d+\.\d+\.\d+$') { throw "UngÃ¼ltige Client-Version: $clientVer" }
if ($panelVer -notmatch '^\d+\.\d+\.\d+$') { throw "UngÃ¼ltige Panel-Version: $panelVer" }

function New-Zip($sourceDir, $zipPath) {
    if (Test-Path $zipPath) { Remove-Item $zipPath }
    # Eintragsnamen mit '/' schreiben (ZipFile.CreateFromDirectory/Compress-Archive nutzen in PS 5.1 '\' -> Probleme mit PHP)
    $base = (Resolve-Path $sourceDir).Path.TrimEnd('\')
    $fs = [IO.File]::Create($zipPath)
    $zip = New-Object IO.Compression.ZipArchive($fs, [IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem $base -Recurse -File -Force | ForEach-Object {
            $rel = $_.FullName.Substring($base.Length + 1).Replace('\', '/')
            [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $rel, [IO.Compression.CompressionLevel]::Optimal)
        }
    } finally { $zip.Dispose(); $fs.Dispose() }
}

# --- Client
Write-Host "Client $clientVer ..."
$clientOut = Join-Path $work "client"
dotnet publish (Join-Path $root "client\Zeiterfassung.csproj") -c Release -r win-x64 --self-contained false -o $clientOut -nologo | Out-Null
if ($LASTEXITCODE -ne 0) { throw "dotnet publish fehlgeschlagen" }
Get-ChildItem $clientOut -Filter *.pdb | Remove-Item
$clientZip = Join-Path $dist "zeiterfassung-client-$clientVer.zip"
New-Zip $clientOut $clientZip

# --- Webpanel (ohne .env, storage, install.php, Lock)
Write-Host "Webpanel $panelVer ..."
$panelOut = Join-Path $work "webpanel"
robocopy (Join-Path $root "webpanel") $panelOut /E /XD storage .git /XF .env installed.lock install.php .gitignore /NFL /NDL /NJH /NJS | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy fehlgeschlagen ($LASTEXITCODE)" }
$panelZip = Join-Path $dist "webpanel-$panelVer.zip"
New-Zip $panelOut $panelZip

# --- Manifest
$manifest = [ordered]@{
    version = $panelVer
    url     = "$($BaseUrl.TrimEnd('/'))/webpanel-$panelVer.zip"
    sha256  = (Get-FileHash $panelZip -Algorithm SHA256).Hash.ToLower()
    notes   = $Notes
}
[IO.File]::WriteAllText((Join-Path $dist "manifest.json"), ($manifest | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))  # ohne BOM

Remove-Item $work -Recurse -Force
Write-Host ""
Write-Host "Fertig in $dist :"
Get-ChildItem $dist | Format-Table Name, @{n="KB";e={[math]::Round($_.Length/1KB)}} -AutoSize
Write-Host "Client-SHA256: $((Get-FileHash $clientZip -Algorithm SHA256).Hash.ToLower())"
