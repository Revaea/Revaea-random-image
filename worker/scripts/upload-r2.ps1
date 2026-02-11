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

  # 遇到单个文件失败时是否继续（默认：继续，把失败写入 upload-failed 文件）
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
  [switch]$CheckRemoteExists,

  # 并发上传（PowerShell 5.1 下使用 RunspacePool）。默认 1=串行。
  # 注意：并发会增加本机/网络压力，也可能更容易触发 wrangler 的瞬时失败；建议从 4~8 起。
  [Parameter(Mandatory=$false)]
  [ValidateRange(1, 64)]
  [int]$Concurrency = 1
)

$ErrorActionPreference = 'Stop'

$SkipIfInState = -not $NoSkipIfInState

# Switch 参数不建议设置默认值（PSScriptAnalyzer: PSAvoidDefaultValueSwitchParameter）。
# 这里实现“默认继续；显式传 -ContinueOnError:$false 才停止”。
$ContinueOnErrorEnabled = $true
if ($PSBoundParameters.ContainsKey('ContinueOnError')) {
  $ContinueOnErrorEnabled = [bool]$ContinueOnError
}

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

function Get-R2UploadItems([string]$Folder, [string]$Prefix) {
  $base = Resolve-Path $Folder
  $files = Get-ChildItem -Path $base -File -Recurse

  foreach ($f in $files) {
    $rel = $f.FullName.Substring($base.Path.Length).TrimStart('\','/')
    $key = ($Prefix + '/' + ($rel -replace '\\','/'))

    [pscustomobject]@{
      Key         = $key
      ObjectPath  = "$Bucket/$key"
      FilePath    = $f.FullName
      ContentType = (Get-ContentType $f.FullName)
    }
  }
}

function Invoke-R2UploadsParallel(
  [Parameter(Mandatory=$true)][array]$Items,
  [Parameter(Mandatory=$true)][int]$Throttle,
  [Parameter(Mandatory=$true)][int]$MaxRetriesLocal,
  [Parameter(Mandatory=$true)][int]$InitialDelaySecondsLocal,
  [Parameter(Mandatory=$true)][switch]$CheckRemoteExistsLocal,
  [Parameter(Mandatory=$true)][bool]$ContinueOnErrorLocal,
  [Parameter(Mandatory=$true)][hashtable]$UploadedLocal,
  [Parameter(Mandatory=$true)][string]$StateFileLocal,
  [Parameter(Mandatory=$true)][switch]$SkipIfInStateLocal,
  [Parameter(Mandatory=$true)][string]$FailedPathLocal
) {
  if (-not $Items -or $Items.Count -eq 0) {
    return @()
  }

  $scriptBlock = {
    param(
      [string]$ObjectPath,
      [string]$FilePath,
      [string]$ContentType,
      [string]$Key,
      [int]$MaxRetries,
      [int]$InitialDelaySeconds,
      [bool]$CheckRemoteExists
    )

    $ErrorActionPreference = 'Stop'

    function Invoke-R2PutWithRetryLocal([string]$ObjectPathLocal, [string]$FilePathLocal, [string]$ContentTypeLocal) {
      $attempt = 0
      $delay = [Math]::Max(1, $InitialDelaySeconds)

      while ($true) {
        $attempt++

        npx wrangler r2 object put "$ObjectPathLocal" --file "$FilePathLocal" --content-type "$ContentTypeLocal" --remote | Out-Null
        $exit = $LASTEXITCODE

        if ($exit -eq 0) {
          return [pscustomobject]@{ Ok = $true; Attempts = $attempt }
        }

        if ($attempt -ge $MaxRetries) {
          return [pscustomobject]@{ Ok = $false; Attempts = $attempt }
        }

        Start-Sleep -Seconds $delay
        $delay = [Math]::Min(120, $delay * 2)
      }
    }

    function Test-R2ObjectExistsRemoteLocal([string]$ObjectPathLocal) {
      $tmp = Join-Path $env:TEMP ("r2-exists-" + [guid]::NewGuid().ToString("N") + ".tmp")
      try {
        npx wrangler r2 object get "$ObjectPathLocal" --file "$tmp" --remote | Out-Null
        return ($LASTEXITCODE -eq 0)
      } finally {
        if (Test-Path $tmp) { Remove-Item -Force $tmp | Out-Null }
      }
    }

    try {
      if ($CheckRemoteExists) {
        if (Test-R2ObjectExistsRemoteLocal $ObjectPath) {
          return [pscustomobject]@{
            Key = $Key
            Success = $true
            WasUploaded = $false
            SkippedRemoteExists = $true
            Attempts = 0
            ObjectPath = $ObjectPath
            MaxRetries = $MaxRetries
            ErrorMessage = $null
          }
        }
      }

      $putRes = Invoke-R2PutWithRetryLocal $ObjectPath $FilePath $ContentType
      if (-not $putRes.Ok) {
        return [pscustomobject]@{
          Key = $Key
          Success = $false
          WasUploaded = $false
          SkippedRemoteExists = $false
          Attempts = $putRes.Attempts
          ObjectPath = $ObjectPath
          MaxRetries = $MaxRetries
          ErrorMessage = "Upload failed after ${MaxRetries} attempts"
        }
      }

      return [pscustomobject]@{
        Key = $Key
        Success = $true
        WasUploaded = $true
        SkippedRemoteExists = $false
        Attempts = $putRes.Attempts
        ObjectPath = $ObjectPath
        MaxRetries = $MaxRetries
        ErrorMessage = $null
      }
    } catch {
      return [pscustomobject]@{
        Key = $Key
        Success = $false
        WasUploaded = $false
        SkippedRemoteExists = $false
        Attempts = 0
        ObjectPath = $ObjectPath
        MaxRetries = $MaxRetries
        ErrorMessage = $_.Exception.Message
      }
    }
  }

  $pool = [runspacefactory]::CreateRunspacePool(1, $Throttle)
  $pool.Open()

  $inFlight = New-Object System.Collections.ArrayList
  $results = New-Object System.Collections.Generic.List[object]

  $queueState = [pscustomobject]@{ Stop = $false }
  $maxQueued = [Math]::Max($Throttle * 4, $Throttle + 1)

  function Add-StateKeyIfNeeded([string]$Key) {
    if (-not $SkipIfInStateLocal) { return }
    if (-not $StateFileLocal -or $StateFileLocal.Trim() -eq '') { return }
    if (-not $UploadedLocal) { return }

    if ($UploadedLocal.ContainsKey($Key)) { return }

    try {
      $UploadedLocal[$Key] = $true
      Add-Content -Path $StateFileLocal -Value $Key -ErrorAction Stop
    } catch {
      Write-Warning "Failed to write state: $($_.Exception.Message)"
    }
  }

  function Wait-Completed([switch]$WaitOne) {
    while ($true) {
      $completedIndex = -1
      for ($i = 0; $i -lt $inFlight.Count; $i++) {
        if ($inFlight[$i].Async.IsCompleted) { $completedIndex = $i; break }
      }

      if ($completedIndex -lt 0) {
        if ($WaitOne -and $inFlight.Count -gt 0) {
          Start-Sleep -Milliseconds 200
          continue
        }
        break
      }

      $h = $inFlight[$completedIndex]
      try {
        $out = $h.PS.EndInvoke($h.Async)
        $res = $out | Select-Object -First 1
      } catch {
        $res = [pscustomobject]@{
          Key = $h.Key
          Success = $false
          WasUploaded = $false
          SkippedRemoteExists = $false
          ErrorMessage = $_.Exception.Message
        }
      } finally {
        $h.PS.Dispose()
        [void]$inFlight.RemoveAt($completedIndex)
      }

      $results.Add($res)

      if ($res.Success) {
        if ($res.SkippedRemoteExists) {
          Write-Host "Skipping (already in R2): $($res.Key)"
          Add-StateKeyIfNeeded ([string]$res.Key)
        } else {
          $attempts = [int]$res.Attempts
          $max = [int]$res.MaxRetries
          $obj = [string]$res.ObjectPath
          if (-not $obj -or $obj.Trim() -eq '') { $obj = "$Bucket/$($res.Key)" }
          if ($attempts -lt 1) { $attempts = 1 }
          if ($max -lt 1) { $max = $MaxRetriesLocal }
          Write-Host "[${attempts}/${max}] put $obj"
          Add-StateKeyIfNeeded ([string]$res.Key)
        }
      } else {
        $attempts = [int]$res.Attempts
        $max = [int]$res.MaxRetries
        $obj = [string]$res.ObjectPath
        if (-not $obj -or $obj.Trim() -eq '') { $obj = "$Bucket/$($res.Key)" }
        if ($attempts -lt 1) { $attempts = $max }
        if ($max -lt 1) { $max = $MaxRetriesLocal }
        Write-Host "[${attempts}/${max}] put $obj"
        Write-Warning "Upload failed: $($res.Key) - $($res.ErrorMessage)"

        if ($FailedPathLocal -and $FailedPathLocal.Trim() -ne '') {
          try {
            Add-Content -Path $FailedPathLocal -Value ([string]$res.Key) -ErrorAction Stop
          } catch {
            Write-Warning "Failed to write failed list: $($_.Exception.Message)"
          }
        }

        if (-not $ContinueOnErrorLocal) {
          $queueState.Stop = $true
        }
      }
    }
  }

  try {
    foreach ($item in $Items) {
      if ($queueState.Stop) { break }

      while ($inFlight.Count -ge $maxQueued) {
        Wait-Completed -WaitOne
        if ($queueState.Stop) { break }
      }
      if ($queueState.Stop) { break }

      $ps = [powershell]::Create()
      $ps.RunspacePool = $pool
      [void]$ps.AddScript($scriptBlock)
      [void]$ps.AddArgument($item.ObjectPath)
      [void]$ps.AddArgument($item.FilePath)
      [void]$ps.AddArgument($item.ContentType)
      [void]$ps.AddArgument($item.Key)
      [void]$ps.AddArgument($MaxRetriesLocal)
      [void]$ps.AddArgument($InitialDelaySecondsLocal)
      [void]$ps.AddArgument([bool]$CheckRemoteExistsLocal)

      $async = $ps.BeginInvoke()
      [void]$inFlight.Add([pscustomobject]@{ PS = $ps; Async = $async; Key = $item.Key })
    }

    while ($inFlight.Count -gt 0) {
      Wait-Completed -WaitOne
    }
  } finally {
    $pool.Close()
    $pool.Dispose()
  }

  return $results
}

function Publish-R2Folder([string]$Folder, [string]$Prefix, [string]$FailedPath) {
  $failed = @()

  # 读取 state：已上传的 key 直接跳过
  $uploaded = @{}
  if ($SkipIfInState -and $StateFile -and (Test-Path $StateFile)) {
    foreach ($line in (Get-Content -Path $StateFile -ErrorAction SilentlyContinue)) {
      $k = $line.Trim()
      if ($k) { $uploaded[$k] = $true }
    }
  }

  $itemsAll = @(Get-R2UploadItems $Folder $Prefix)
  $items = @()
  foreach ($it in $itemsAll) {
    if ($SkipIfInState -and $uploaded.ContainsKey($it.Key)) {
      Write-Host "Skipping (in state): $($it.Key)"
      continue
    }
    $items += $it
  }

  if ($items.Count -eq 0) {
    return
  }

  function Add-StateKeyIfNeeded([string]$Key) {
    if (-not $SkipIfInState) { return }
    if (-not $StateFile -or $StateFile.Trim() -eq '') { return }
    if ($uploaded.ContainsKey($Key)) { return }

    try {
      $uploaded[$Key] = $true
      Add-Content -Path $StateFile -Value $Key -ErrorAction Stop
    } catch {
      Write-Warning "Failed to write state: $($_.Exception.Message)"
    }
  }

  if ($Concurrency -le 1) {
    foreach ($it in $items) {
      $key = $it.Key
      $objectPath = $it.ObjectPath

      if ($CheckRemoteExists) {
        Write-Host "Checking remote exists: $key"
        if (Test-R2ObjectExistsRemote $objectPath) {
          Write-Host "Skipping (already in R2): $key"
          Add-StateKeyIfNeeded $key
          continue
        }
      }

      $ok = Invoke-R2PutWithRetry $objectPath $it.FilePath $it.ContentType
      if (-not $ok) {
        $failed += $key
        $msg = "Upload failed after ${MaxRetries} attempts: $key"

        if ($FailedPath -and $FailedPath.Trim() -ne '') {
          try {
            Add-Content -Path $FailedPath -Value $key -ErrorAction Stop
          } catch {
            Write-Warning "Failed to write failed list: $($_.Exception.Message)"
          }
        }

        if ($ContinueOnErrorEnabled) {
          Write-Warning $msg
          continue
        }
        throw $msg
      }

      Add-StateKeyIfNeeded $key
    }
  } else {
    Write-Host "Uploading with concurrency=$Concurrency (items=$($items.Count))"
    $results = Invoke-R2UploadsParallel -Items $items -Throttle $Concurrency -MaxRetriesLocal $MaxRetries -InitialDelaySecondsLocal $InitialDelaySeconds -CheckRemoteExistsLocal:$CheckRemoteExists -ContinueOnErrorLocal $ContinueOnErrorEnabled -UploadedLocal $uploaded -StateFileLocal $StateFile -SkipIfInStateLocal:$SkipIfInState -FailedPathLocal $FailedPath

    foreach ($r in $results) {
      if (-not $r.Success) {
        $failed += [string]$r.Key
      }
    }

    if (-not $ContinueOnErrorEnabled -and $failed.Count -gt 0) {
      throw "Upload failed (parallel): $($failed[0])"
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

if (Test-Path $failedPath) {
  Remove-Item -Force $failedPath | Out-Null
}

Write-Host "State file: $StateFile"
Publish-R2Folder (Join-Path $root 'data\image\portrait') 'portrait' $failedPath
Publish-R2Folder (Join-Path $root 'data\image\landscape') 'landscape' $failedPath

if ($script:FailedKeys.Count -gt 0) {
  $script:FailedKeys | Sort-Object | Out-File -FilePath $failedPath -Encoding utf8
  Write-Warning "Some uploads failed. See $failedPath"
}

Write-Host 'Done.'
