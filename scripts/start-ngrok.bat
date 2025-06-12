@echo off
echo 🌐 Starting Ngrok for AI Transcription Microservice
echo ==================================================

echo 🚀 Starting ngrok tunnel...

REM Start docker services
docker-compose up -d ngrok laravel

echo ⏳ Waiting for ngrok to start...
timeout /t 5 /nobreak >nul

echo 🔍 Retrieving ngrok tunnel URL...
set NGROK_URL=
set /a attempts=0

:retry
set /a attempts+=1
if %attempts% gtr 30 goto failed

REM Get ngrok URL using PowerShell
for /f "delims=" %%i in ('powershell -Command "try { $response = Invoke-RestMethod -Uri 'http://localhost:4040/api/tunnels' -ErrorAction Stop; $response.tunnels[0].public_url } catch { '' }"') do set NGROK_URL=%%i

if "%NGROK_URL%"=="" (
    echo    Attempt %attempts%/30: Waiting for ngrok...
    timeout /t 2 /nobreak >nul
    goto retry
)

echo.
echo 🎉 Ngrok tunnel is active!
echo ================================
echo 🌐 Public URL: %NGROK_URL%
echo 🏠 Local URL: http://localhost:8080
echo 🔧 Monitor: http://localhost:4040
echo.
echo 📋 Next steps:
echo    1. Share the public URL: %NGROK_URL%
echo    2. Monitor traffic at: http://localhost:4040
echo    3. To stop: docker-compose down ngrok
echo.
echo ⚠️  Note: URL will change when ngrok restarts (free account)
echo.
echo URL: %NGROK_URL%
pause
exit /b 0

:failed
echo ❌ Failed to retrieve ngrok URL
echo    Check if ngrok container is running: docker logs ngrok-tunnel
pause
exit /b 1 