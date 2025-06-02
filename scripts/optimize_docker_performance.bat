@echo off
echo 🚀 Optimizing Laravel Docker Performance...

REM Stop containers if running
echo 🛑 Stopping existing containers...
docker-compose down

REM Copy optimized environment file
echo 📝 Setting up optimized environment...
copy app\laravel\.env.local app\laravel\.env

REM Build containers with no cache for fresh start
echo 🔨 Rebuilding containers...
docker-compose build --no-cache laravel

REM Start containers
echo 🚀 Starting optimized containers...
docker-compose up -d

REM Wait for containers to be ready
echo ⏳ Waiting for containers to initialize...
timeout /t 10 /nobreak >nul

REM Clear all Laravel caches inside container
echo 🧹 Clearing Laravel caches...
docker-compose exec laravel php artisan config:clear
docker-compose exec laravel php artisan cache:clear
docker-compose exec laravel php artisan route:clear
docker-compose exec laravel php artisan view:clear

REM Ensure SQLite database exists and run migrations
echo 🗄️ Setting up database...
docker-compose exec laravel touch /var/www/database/database.sqlite
docker-compose exec laravel php artisan migrate --force

REM Optimize autoloader
echo ⚡ Optimizing autoloader...
docker-compose exec laravel composer dump-autoload --optimize

REM Install npm dependencies and build assets
echo 📦 Installing npm dependencies...
docker-compose exec laravel npm install

echo 🎨 Building assets...
docker-compose exec laravel npm run build

echo.
echo ✅ Docker performance optimization complete!
echo.
echo 🎯 Optimizations applied:
echo    • Using local SQLite database
echo    • File-based caching and sessions
echo    • Optimized autoloader
echo    • Fresh container build
echo    • All caches cleared
echo.
echo 🌐 Your application should now be available at:
echo    http://localhost:8080
echo.
echo 📊 To monitor logs:
echo    docker-compose logs -f laravel
echo.
echo 🔧 To run artisan commands:
echo    docker-compose exec laravel php artisan [command]

pause