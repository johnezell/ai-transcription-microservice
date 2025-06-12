@echo off
echo 🌐 Ngrok Setup Script for AI Transcription Microservice
echo ==================================================

REM Check if ngrok.env exists, if not create from template
if not exist "ngrok.env" (
    echo 📄 Creating ngrok.env from template...
    copy "ngrok.env.example" "ngrok.env" >nul
    echo ✅ Created ngrok.env file
    echo.
    echo ⚠️  IMPORTANT: Please edit ngrok.env and add your NGROK_AUTHTOKEN
    echo    Get your authtoken from: https://dashboard.ngrok.com/get-started/your-authtoken
    echo.
    echo    After adding your authtoken, run this script again.
    pause
    exit /b 1
)

REM Check if authtoken is configured (basic check)
findstr /C:"NGROK_AUTHTOKEN=your_ngrok_authtoken_here" ngrok.env >nul
if %errorlevel% equ 0 (
    echo ❌ NGROK_AUTHTOKEN not configured in ngrok.env
    echo    Please edit ngrok.env and add your authtoken from:
    echo    https://dashboard.ngrok.com/get-started/your-authtoken
    pause
    exit /b 1
)

echo 🚀 Starting ngrok tunnel...

REM Start docker services with ngrok
docker-compose --env-file ngrok.env up -d ngrok laravel

echo ⏳ Waiting for ngrok to start...
timeout /t 5 /nobreak >nul

echo 🔍 Retrieving ngrok tunnel URL...
set NGROK_URL=
set /a attempts=0

:retry
set /a attempts+=1
if %attempts% gtr 30 goto failed

REM Get ngrok URL using PowerShell (more reliable than curl on Windows)
for /f "delims=" %%i in ('powershell -Command "try { $response = Invoke-RestMethod -Uri 'http://localhost:4040/api/tunnels' -ErrorAction Stop; $response.tunnels[0].public_url } catch { '' }"') do set NGROK_URL=%%i

if "%NGROK_URL%"=="" (
    echo    Attempt %attempts%/30: Waiting for ngrok...
    timeout /t 2 /nobreak >nul
    goto retry
)

echo ✅ Ngrok tunnel active: %NGROK_URL%

REM Update the ngrok.env file with the actual URL
echo 📝 Updating ngrok.env with tunnel URL...
powershell -Command "(Get-Content 'ngrok.env') -replace 'NGROK_URL=.*', 'NGROK_URL=%NGROK_URL%' | Set-Content 'ngrok.env'"

REM Restart Laravel with updated environment
echo 🔄 Restarting Laravel with updated configuration...
docker-compose --env-file ngrok.env up -d laravel

REM Update service configurations that use localhost
echo 🔧 Updating service configurations...

REM Update validation scripts
if exist "validate_ollama_integration.py" (
    powershell -Command "(Get-Content 'validate_ollama_integration.py') -replace 'http://localhost:5051', '%NGROK_URL%:5051' | Set-Content 'validate_ollama_integration.py'"
)

if exist "test_guitar_evaluator.py" (
    powershell -Command "(Get-Content 'test_guitar_evaluator.py') -replace 'http://localhost:5051', '%NGROK_URL%:5051' | Set-Content 'test_guitar_evaluator.py'"
)

echo.
echo 🎉 Ngrok setup complete!
echo ================================
echo 🌐 Laravel Application: %NGROK_URL%
echo 🔧 Ngrok Web Interface: http://localhost:4040
echo 📊 Monitor tunnels at: http://localhost:4040/inspect/http
echo.
echo 📋 Next steps:
echo    1. Share the ngrok URL with others to access your application
echo    2. The URL will change each time ngrok restarts (unless you have a paid plan)
echo    3. To stop ngrok: docker-compose down ngrok
echo.
echo ⚠️  Note: Free ngrok accounts have connection limits and the URL changes on restart
pause
exit /b 0

:failed
echo ❌ Failed to retrieve ngrok URL
echo    Check if ngrok container is running: docker logs ngrok-tunnel
pause
exit /b 1 