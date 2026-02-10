param(
  [Parameter(Mandatory=$true)]
  [string]$Bucket
)

$ErrorActionPreference = 'Stop'

function Get-ContentType([string]$Path) {
  $ext = [System.IO.Path]::GetExtension($Path).ToLowerInvariant()
  switch ($ext) {
    '.webp' { return 'image/webp' }
    '.jpg' { return 'image/jpeg' }
    '.jpeg' { return 'image/jpeg' }
    '.png' { return 'image/png' }
    '.gif' { return 'image/gif' }
    default { return 'application/octet-stream' }
  }
}

function Upload-Folder([string]$Folder, [string]$Prefix) {
  $base = Resolve-Path $Folder
  $files = Get-ChildItem -Path $base -File -Recurse
  foreach ($f in $files) {
    $rel = $f.FullName.Substring($base.Path.Length).TrimStart('\','/')
    $key = ($Prefix + '/' + ($rel -replace '\\','/'))
    $ct = Get-ContentType $f.FullName

    Write-Host "Uploading $key" 
    npx wrangler r2 object put "$Bucket/$key" --file "$($f.FullName)" --content-type "$ct" --remote
    if ($LASTEXITCODE -ne 0) { throw "Upload failed: $key" }
  }
}

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
Upload-Folder (Join-Path $root 'portrait') 'portrait'
Upload-Folder (Join-Path $root 'landscape') 'landscape'

Write-Host 'Done.'
