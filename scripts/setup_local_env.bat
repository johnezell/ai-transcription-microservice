@echo off
echo 🚀 Setting up optimized local Laravel environment...

REM Navigate to Laravel directory
cd app\laravel

REM Backup current .env if it exists
if exist .env (
    echo 📦 Backing up current .env to .env.backup
    copy .env .env.backup >nul
)

REM Copy optimized local environment
echo 📝 Setting up optimized local .env configuration...
copy .env.local .env >nul

REM Clear all caches
echo 🧹 Clearing application caches...
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

REM Ensure SQLite database exists
echo 🗄️ Setting up local SQLite database...
if not exist database.sqlite (
    type nul > database.sqlite
    echo ✅ Created database.sqlite
)

REM Run migrations
echo 🔄 Running database migrations...
php artisan migrate --force

REM Optimize autoloader
echo ⚡ Optimizing Composer autoloader...
composer dump-autoload --optimize

REM Install/update npm dependencies if needed
if exist package.json (
    echo 📦 Installing/updating npm dependencies...
    npm install
)

REM Build assets for development
if exist vite.config.js (
    echo 🎨 Building development assets...
    npm run build
)

echo.
echo ✅ Local environment optimization complete!
echo.
echo 🎯 Performance improvements applied:
echo    • Switched from remote MySQL to local SQLite
echo    • Changed cache from database to file-based
echo    • Changed sessions from database to file-based
echo    • Set environment to 'local' for better debugging
echo    • Optimized autoloader
echo    • Cleared all caches
echo.
echo 🚀 Your Laravel application should now run much faster!
echo.
echo To start development server:
echo    composer run dev
echo.
echo To restore production environment:
echo    copy .env.backup .env (if backup exists)

pause