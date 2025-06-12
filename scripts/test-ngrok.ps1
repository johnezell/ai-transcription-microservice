Write-Host "🧪 Testing Ngrok Integration" -ForegroundColor Cyan
Write-Host "=================================="

# Check if ngrok.env exists
if (-not (Test-Path "ngrok.env")) {
    Write-Host "❌ ngrok.env file not found" -ForegroundColor Red
    Write-Host "   Run setup script first: .\scripts\setup-ngrok.ps1"
    Read-Host "Press Enter to exit"
    exit 1
}

# Load environment variables
$envContent = Get-Content "ngrok.env" -Raw
$envContent -split "`n" | ForEach-Object {
    if ($_ -match "^([^=]+)=(.*)$") {
        [Environment]::SetEnvironmentVariable($matches[1], $matches[2], "Process")
    }
}

$NGROK_AUTHTOKEN = [Environment]::GetEnvironmentVariable("NGROK_AUTHTOKEN", "Process")
$NGROK_URL = [Environment]::GetEnvironmentVariable("NGROK_URL", "Process")

Write-Host "📋 Configuration Check:"
$authtokenConfigured = if ($NGROK_AUTHTOKEN -and $NGROK_AUTHTOKEN -ne "your_ngrok_authtoken_here") { "✅ Yes" } else { "❌ No" }
Write-Host "   Authtoken configured: $authtokenConfigured"
Write-Host "   Ngrok URL: $(if ($NGROK_URL) { $NGROK_URL } else { "Not set" })"
Write-Host ""

# Check if containers are running
Write-Host "🐳 Docker Container Status:"
try {
    $ngrokStatus = (docker inspect -f '{{.State.Status}}' ngrok-tunnel 2>$null)
    if (-not $ngrokStatus) { $ngrokStatus = "not found" }
} catch {
    $ngrokStatus = "not found"
}

try {
    $laravelStatus = (docker inspect -f '{{.State.Status}}' laravel-app 2>$null)
    if (-not $laravelStatus) { $laravelStatus = "not found" }
} catch {
    $laravelStatus = "not found"
}

Write-Host "   Ngrok container: $ngrokStatus"
Write-Host "   Laravel container: $laravelStatus"
Write-Host ""

# Test ngrok API
Write-Host "🔍 Ngrok API Test:"
try {
    $response = Invoke-RestMethod -Uri "http://localhost:4040/api/tunnels" -ErrorAction Stop
    $tunnelCount = if ($response.tunnels) { $response.tunnels.Count } else { 0 }
    
    Write-Host "   API accessible: ✅ Yes" -ForegroundColor Green
    Write-Host "   Active tunnels: $tunnelCount"
    
    if ($tunnelCount -gt 0) {
        $publicUrl = $response.tunnels[0].public_url
        Write-Host "   Public URL: $publicUrl"
    }
} catch {
    Write-Host "   API accessible: ❌ No" -ForegroundColor Red
    $tunnelCount = 0
    $publicUrl = $null
}
Write-Host ""

# Test Laravel accessibility via ngrok (if URL is available)
if ($NGROK_URL -and $NGROK_URL -ne "") {
    Write-Host "🌐 Laravel Application Test:"
    Write-Host "   Testing: $NGROK_URL"
    
    try {
        $response = Invoke-WebRequest -Uri $NGROK_URL -TimeoutSec 10 -ErrorAction Stop
        $httpStatus = $response.StatusCode
        if ($httpStatus -eq 200) {
            Write-Host "   Laravel accessible: ✅ Yes (HTTP $httpStatus)" -ForegroundColor Green
        } else {
            Write-Host "   Laravel accessible: ❌ No (HTTP $httpStatus)" -ForegroundColor Red
        }
    } catch {
        Write-Host "   Laravel accessible: ❌ No (Error: $($_.Exception.Message))" -ForegroundColor Red
    }
} else {
    Write-Host "🌐 Laravel Application Test:"
    Write-Host "   Ngrok URL not available for testing"
}
Write-Host ""

# Test local Laravel accessibility
Write-Host "🏠 Local Laravel Test:"
try {
    $response = Invoke-WebRequest -Uri "http://localhost:8080" -TimeoutSec 5 -ErrorAction Stop
    $localStatus = $response.StatusCode
    if ($localStatus -eq 200) {
        Write-Host "   Local Laravel accessible: ✅ Yes (HTTP $localStatus)" -ForegroundColor Green
    } else {
        Write-Host "   Local Laravel accessible: ❌ No (HTTP $localStatus)" -ForegroundColor Red
    }
} catch {
    Write-Host "   Local Laravel accessible: ❌ No (Error: $($_.Exception.Message))" -ForegroundColor Red
    $localStatus = 0
}
Write-Host ""

# Summary
Write-Host "📊 Test Summary:"
if ($ngrokStatus -eq "running" -and $laravelStatus -eq "running" -and $tunnelCount -gt 0) {
    Write-Host "   Overall status: ✅ All systems operational" -ForegroundColor Green
    Write-Host ""
    Write-Host "🎉 Ngrok integration is working correctly!" -ForegroundColor Green
    Write-Host "   🌐 Public URL: $(if ($publicUrl) { $publicUrl } else { $NGROK_URL })" -ForegroundColor Cyan
    Write-Host "   🏠 Local URL: http://localhost:8080" -ForegroundColor Cyan
    Write-Host "   🔧 Monitor: http://localhost:4040" -ForegroundColor Cyan
} else {
    Write-Host "   Overall status: ⚠️  Some issues detected" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "🔧 Troubleshooting suggestions:" -ForegroundColor Yellow
    Write-Host "   1. Check container logs: docker logs ngrok-tunnel"
    Write-Host "   2. Restart services: docker-compose --env-file ngrok.env up -d"
    Write-Host "   3. Verify authtoken in ngrok.env"
    Write-Host "   4. Check ngrok web interface: http://localhost:4040"
}

Read-Host "Press Enter to exit" 