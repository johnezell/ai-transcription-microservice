# Git Path Analysis Debug Script
Write-Host "=== Git Path Length Analysis ===" -ForegroundColor Green

# Check the specific problematic file
$problemFile = "app/services/transcription/models/huggingface/hub/models--Systran--faster-whisper-tiny/snapshots/d90ca5fe260221311c53c58e660288d3deb8d356/config.json"
$fullPath = Join-Path (Get-Location) $problemFile

Write-Host "Problem file path: $problemFile" -ForegroundColor Yellow
Write-Host "Full path length: $($fullPath.Length)" -ForegroundColor Yellow
Write-Host "Full path: $fullPath" -ForegroundColor Cyan

# Check if file exists and permissions
if (Test-Path $problemFile) {
    Write-Host "File exists: YES" -ForegroundColor Green
    try {
        $fileInfo = Get-Item $problemFile -ErrorAction Stop
        Write-Host "File size: $($fileInfo.Length) bytes" -ForegroundColor Green
        Write-Host "File attributes: $($fileInfo.Attributes)" -ForegroundColor Green
    } catch {
        Write-Host "Permission error: $($_.Exception.Message)" -ForegroundColor Red
    }
} else {
    Write-Host "File exists: NO" -ForegroundColor Red
}

# Check Windows path length limit
Write-Host "`n=== Windows Path Length Analysis ===" -ForegroundColor Green
Write-Host "Windows default path limit: 260 characters" -ForegroundColor Yellow
Write-Host "Current path length: $($fullPath.Length)" -ForegroundColor Yellow

if ($fullPath.Length -gt 260) {
    Write-Host "PATH TOO LONG - This is likely the issue!" -ForegroundColor Red
} else {
    Write-Host "Path length is within Windows limits" -ForegroundColor Green
}

# Check for long path support
Write-Host "`n=== Long Path Support Check ===" -ForegroundColor Green
try {
    $longPathEnabled = Get-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Control\FileSystem" -Name "LongPathsEnabled" -ErrorAction SilentlyContinue
    if ($longPathEnabled.LongPathsEnabled -eq 1) {
        Write-Host "Windows Long Path Support: ENABLED" -ForegroundColor Green
    } else {
        Write-Host "Windows Long Path Support: DISABLED" -ForegroundColor Red
    }
} catch {
    Write-Host "Could not check Long Path Support registry setting" -ForegroundColor Yellow
}

# List all files in models directory with lengths
Write-Host "`n=== All Model Files Path Analysis ===" -ForegroundColor Green
Get-ChildItem -Path "app\services\transcription\models" -Recurse -ErrorAction SilentlyContinue | ForEach-Object {
    $pathLength = $_.FullName.Length
    $color = if ($pathLength -gt 260) { "Red" } elseif ($pathLength -gt 200) { "Yellow" } else { "Green" }
    Write-Host "$pathLength : $($_.FullName)" -ForegroundColor $color
} | Sort-Object { $_.Split(':')[0] -as [int] } -Descending | Select-Object -First 10