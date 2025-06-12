Write-Host "Starting Ngrok for AI Transcription Microservice" -ForegroundColor Cyan
Write-Host "=================================================="

Write-Host "Starting ngrok tunnel..." -ForegroundColor Green

# Start docker services
docker-compose up -d ngrok laravel

Write-Host "Waiting for ngrok to start..." -ForegroundColor Yellow
Start-Sleep -Seconds 5

# Get the ngrok URL
Write-Host "Retrieving ngrok tunnel URL..." -ForegroundColor Yellow
$attempts = 0
$maxAttempts = 30
$NGROK_URL = ""

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
        Write-Host "   Attempt $attempts/$maxAttempts : Waiting for ngrok..." -ForegroundColor Gray
        Start-Sleep -Seconds 2
    }
} while ($attempts -lt $maxAttempts -and [string]::IsNullOrEmpty($NGROK_URL))

if ([string]::IsNullOrEmpty($NGROK_URL)) {
    Write-Host "Failed to retrieve ngrok URL" -ForegroundColor Red
    Write-Host "   Check if ngrok container is running: docker logs ngrok-tunnel"
    Read-Host "Press Enter to exit"
    exit 1
}

Write-Host ""
Write-Host "Ngrok tunnel is active!" -ForegroundColor Green
Write-Host "================================"
Write-Host "Public URL: $NGROK_URL" -ForegroundColor Cyan
Write-Host "Local URL: http://localhost:8080" -ForegroundColor Cyan
Write-Host "Monitor: http://localhost:4040" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:"
Write-Host "   1. Share the public URL: $NGROK_URL"
Write-Host "   2. Monitor traffic at: http://localhost:4040"
Write-Host "   3. To stop: docker-compose down ngrok"
Write-Host ""
Write-Host "Note: URL will change when ngrok restarts (free account)" -ForegroundColor Yellow

# Copy URL to clipboard
try {
    Set-Clipboard -Value $NGROK_URL
    Write-Host "URL copied to clipboard!" -ForegroundColor Green
} catch {
    Write-Host "Could not copy to clipboard, but URL is displayed above" -ForegroundColor Gray
}

Read-Host "Press Enter to exit" 