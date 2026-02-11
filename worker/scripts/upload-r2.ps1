param(
  [Parameter(Mandatory=$true)]
  [string]$Bucket,

  # 仓库根目录（默认：脚本所在位置往上两级，即 repo root）
  [Parameter(Mandatory=$false)]
  [string]$Root,

  # Wrangler API 超时/网络波动时重试次数
  [Parameter(Mandatory=$false)]
  [int]$MaxRetries = 5,

  # 初始重试等待秒数（之后会指数退避：*2）
  [Parameter(Mandatory=$false)]
  [int]$InitialDelaySeconds = 2,

  # 遇到单个文件失败时是否继续（默认：失败就停）
  [Parameter(Mandatory=$false)]
  [switch]$ContinueOnError,

  # 关闭去重/断点续传（默认开启）：不读取/不写入 state 文件，每次都尝试上传
  [Parameter(Mandatory=$false)]
  [switch]$NoSkipIfInState,

  # state 文件路径（默认放在仓库根目录下：r2-upload-state-<bucket>.txt）
  [Parameter(Mandatory=$false)]
  [string]$StateFile,

  # 可选：真正检查远端对象是否存在（会把对象下载到临时文件，因此很慢/耗流量；一般不建议开）
  [Parameter(Mandatory=$false)]
  [switch]$CheckRemoteExists
)

$ErrorActionPreference = 'Stop'

$SkipIfInState = -not $NoSkipIfInState

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

function Invoke-R2PutWithRetry([string]$ObjectPath, [string]$FilePath, [string]$ContentType) {
  $attempt = 0
  $delay = [Math]::Max(1, $InitialDelaySeconds)

  while ($true) {
    $attempt++

    Write-Host "[${attempt}/${MaxRetries}] put $ObjectPath" 
    npx wrangler r2 object put "$ObjectPath" --file "$FilePath" --content-type "$ContentType" --remote

    if ($LASTEXITCODE -eq 0) {
      return $true
    }

    if ($attempt -ge $MaxRetries) {
      return $false
    }

    Write-Warning "wrangler failed (exit=$LASTEXITCODE). Retrying in ${delay}s..."
    Start-Sleep -Seconds $delay
    $delay = [Math]::Min(120, $delay * 2)
  }
}

function Test-R2ObjectExistsRemote([string]$ObjectPath) {
  # 注意：wrangler 目前没有 HEAD，只能 get；这里会下载到临时文件。
  $tmp = Join-Path $env:TEMP ("r2-exists-" + [guid]::NewGuid().ToString("N") + ".tmp")
  try {
    npx wrangler r2 object get "$ObjectPath" --file "$tmp" --remote | Out-Null
    return ($LASTEXITCODE -eq 0)
  } finally {
    if (Test-Path $tmp) { Remove-Item -Force $tmp | Out-Null }
  }
}

function Publish-R2Folder([string]$Folder, [string]$Prefix) {
  $base = Resolve-Path $Folder
  $files = Get-ChildItem -Path $base -File -Recurse

  $failed = @()

  # 读取 state：已上传的 key 直接跳过
  $uploaded = @{}
  if ($SkipIfInState -and $StateFile -and (Test-Path $StateFile)) {
    foreach ($line in (Get-Content -Path $StateFile -ErrorAction SilentlyContinue)) {
      $k = $line.Trim()
      if ($k) { $uploaded[$k] = $true }
    }
  }

  foreach ($f in $files) {
    $rel = $f.FullName.Substring($base.Path.Length).TrimStart('\','/')
    $key = ($Prefix + '/' + ($rel -replace '\\','/'))
    $ct = Get-ContentType $f.FullName

    $objectPath = "$Bucket/$key"

    if ($SkipIfInState -and $uploaded.ContainsKey($key)) {
      Write-Host "Skipping (in state): $key"
      continue
    }

    if ($CheckRemoteExists) {
      Write-Host "Checking remote exists: $key"
      if (Test-R2ObjectExistsRemote $objectPath) {
        Write-Host "Skipping (already in R2): $key"
        if ($SkipIfInState -and $StateFile) {
          $uploaded[$key] = $true
          Add-Content -Path $StateFile -Value $key
        }
        continue
      }
    }

    $ok = Invoke-R2PutWithRetry $objectPath $f.FullName $ct
    if (-not $ok) {
      $failed += $key
      $msg = "Upload failed after ${MaxRetries} attempts: $key"
      if ($ContinueOnError) {
        Write-Error $msg
        continue
      }
      throw $msg
    }

    if ($SkipIfInState -and $StateFile) {
      $uploaded[$key] = $true
      Add-Content -Path $StateFile -Value $key
    }
  }

  if ($failed.Count -gt 0) {
    # 聚合失败列表，最后统一写入 data/ 下的 txt
    $script:FailedKeys += $failed
  }
}

if (-not $Root -or $Root.Trim() -eq '') {
  $Root = Join-Path $PSScriptRoot '..\..'
}

$root = Resolve-Path $Root

$dataDir = Join-Path $root 'data'
if (-not (Test-Path $dataDir)) {
  New-Item -Path $dataDir -ItemType Directory | Out-Null
}

if (-not $StateFile -or $StateFile.Trim() -eq '') {
  $StateFile = Join-Path $dataDir ("r2-upload-state-" + $Bucket + ".txt")
}

$failedPath = Join-Path $dataDir ("upload-failed-" + $Bucket + ".txt")
$script:FailedKeys = @()

Write-Host "State file: $StateFile"
Publish-R2Folder (Join-Path $root 'data\image\portrait') 'portrait'
Publish-R2Folder (Join-Path $root 'data\image\landscape') 'landscape'

if ($script:FailedKeys.Count -gt 0) {
  $script:FailedKeys | Sort-Object | Out-File -FilePath $failedPath -Encoding utf8
  Write-Warning "Some uploads failed. See $failedPath"
}

Write-Host 'Done.'
