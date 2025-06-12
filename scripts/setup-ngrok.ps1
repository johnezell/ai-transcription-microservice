Write-Host "🌐 Ngrok Setup Script for AI Transcription Microservice" -ForegroundColor Cyan
Write-Host "=================================================="

# Check if ngrok.env exists, if not create from template
if (-not (Test-Path "ngrok.env")) {
    Write-Host "📄 Creating ngrok.env from template..." -ForegroundColor Yellow
    Copy-Item "ngrok.env.example" "ngrok.env"
    Write-Host "✅ Created ngrok.env file" -ForegroundColor Green
    Write-Host ""
    Write-Host "⚠️  IMPORTANT: Please edit ngrok.env and add your NGROK_AUTHTOKEN" -ForegroundColor Red
    Write-Host "   Get your authtoken from: https://dashboard.ngrok.com/get-started/your-authtoken"
    Write-Host ""
    Write-Host "   After adding your authtoken, run this script again."
    Read-Host "Press Enter to exit"
    exit 1
}

# Check if authtoken is configured
$content = Get-Content "ngrok.env" -Raw
if ($content -match "NGROK_AUTHTOKEN=your_ngrok_authtoken_here" -or $content -notmatch "NGROK_AUTHTOKEN=\w+") {
    Write-Host "❌ NGROK_AUTHTOKEN not configured in ngrok.env" -ForegroundColor Red
    Write-Host "   Please edit ngrok.env and add your authtoken from:"
    Write-Host "   https://dashboard.ngrok.com/get-started/your-authtoken"
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "🚀 Starting ngrok tunnel..." -ForegroundColor Green

# Start docker services with ngrok
& docker-compose --env-file ngrok.env up -d ngrok laravel

Write-Host "⏳ Waiting for ngrok to start..." -ForegroundColor Yellow
Start-Sleep -Seconds 5

# Get the ngrok URL
Write-Host "🔍 Retrieving ngrok tunnel URL..." -ForegroundColor Yellow
$NGROK_URL = ""
$attempts = 0
$maxAttempts = 30

do {
    $attempts++
    try {
        $response = Invoke-RestMethod -Uri "http://localhost:4040/api/tunnels" -ErrorAction Stop
        if ($response.tunnels -and $response.tunnels.Count -gt 0) {
            $NGROK_URL = $response.tunnels[0].public_url
            break
        }
    }
    catch {
        # Ignore errors and continue trying
    }
    
    if ($attempts -lt $maxAttempts) {
        Write-Host "   Attempt $attempts/$maxAttempts`: Waiting for ngrok..." -ForegroundColor Gray
        Start-Sleep -Seconds 2
    }
} while ($attempts -lt $maxAttempts -and [string]::IsNullOrEmpty($NGROK_URL))

if ([string]::IsNullOrEmpty($NGROK_URL)) {
    Write-Host "❌ Failed to retrieve ngrok URL" -ForegroundColor Red
    Write-Host "   Check if ngrok container is running: docker logs ngrok-tunnel"
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host "✅ Ngrok tunnel active: $NGROK_URL" -ForegroundColor Green

# Update the ngrok.env file with the actual URL
Write-Host "📝 Updating ngrok.env with tunnel URL..." -ForegroundColor Yellow
$content = Get-Content "ngrok.env" -Raw
$content = $content -replace "NGROK_URL=.*", "NGROK_URL=$NGROK_URL"
Set-Content "ngrok.env" -Value $content

# Restart Laravel with updated environment
Write-Host "🔄 Restarting Laravel with updated configuration..." -ForegroundColor Yellow
& docker-compose --env-file ngrok.env up -d laravel

# Update service configurations that use localhost
Write-Host "🔧 Updating service configurations..." -ForegroundColor Yellow

# Update validation scripts
if (Test-Path "validate_ollama_integration.py") {
    $content = Get-Content "validate_ollama_integration.py" -Raw
    $content = $content -replace "http://localhost:5051", "$NGROK_URL`:5051"
    Set-Content "validate_ollama_integration.py" -Value $content
}

if (Test-Path "test_guitar_evaluator.py") {
    $content = Get-Content "test_guitar_evaluator.py" -Raw
    $content = $content -replace "http://localhost:5051", "$NGROK_URL`:5051"
    Set-Content "test_guitar_evaluator.py" -Value $content
}

Write-Host ""
Write-Host "🎉 Ngrok setup complete!" -ForegroundColor Green
Write-Host "================================"
Write-Host "🌐 Laravel Application: $NGROK_URL" -ForegroundColor Cyan
Write-Host "🔧 Ngrok Web Interface: http://localhost:4040" -ForegroundColor Cyan
Write-Host "📊 Monitor tunnels at: http://localhost:4040/inspect/http" -ForegroundColor Cyan
Write-Host ""
Write-Host "📋 Next steps:"
Write-Host "   1. Share the ngrok URL with others to access your application"
Write-Host "   2. The URL will change each time ngrok restarts (unless you have a paid plan)"
Write-Host "   3. To stop ngrok: docker-compose down ngrok"
Write-Host ""
Write-Host "⚠️  Note: Free ngrok accounts have connection limits and the URL changes on restart" -ForegroundColor Yellow

Read-Host "Press Enter to exit" 