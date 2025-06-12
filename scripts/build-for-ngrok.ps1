Write-Host "🔧 Building Vite Assets for Ngrok Production Mode" -ForegroundColor Cyan
Write-Host "=================================================="

# Navigate to Laravel directory
Set-Location "app/laravel"

Write-Host "📦 Installing/updating npm dependencies..." -ForegroundColor Yellow
npm install

Write-Host "🏗️  Building Vite assets for production..." -ForegroundColor Yellow
npm run build

Write-Host "🔄 Restarting Laravel container..." -ForegroundColor Yellow
Set-Location "../.."
docker-compose restart laravel

Write-Host ""
Write-Host "✅ Vite assets built for production!" -ForegroundColor Green
Write-Host "================================"
Write-Host "📋 What this fixes:"
Write-Host "   • Eliminates CORS issues with Vite dev server"
Write-Host "   • Assets served directly through Laravel/ngrok"
Write-Host "   • No dependency on localhost:5173"
Write-Host ""
Write-Host "🌐 Your app should now work correctly at:"
Write-Host "   https://transcriptions.ngrok.dev" -ForegroundColor Cyan
Write-Host ""
Write-Host "ℹ️  Note: For development mode, use 'npm run dev' in app/laravel"

Read-Host "Press Enter to exit" 