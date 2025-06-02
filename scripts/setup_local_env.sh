#!/bin/bash

# Laravel Local Development Performance Optimization Script

echo "🚀 Setting up optimized local Laravel environment..."

# Navigate to Laravel directory
cd app/laravel

# Backup current .env if it exists
if [ -f .env ]; then
    echo "📦 Backing up current .env to .env.backup"
    cp .env .env.backup
fi

# Copy optimized local environment
echo "📝 Setting up optimized local .env configuration..."
cp .env.local .env

# Clear all caches
echo "🧹 Clearing application caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Ensure SQLite database exists
echo "🗄️ Setting up local SQLite database..."
if [ ! -f database.sqlite ]; then
    touch database.sqlite
    echo "✅ Created database.sqlite"
fi

# Run migrations
echo "🔄 Running database migrations..."
php artisan migrate --force

# Optimize autoloader
echo "⚡ Optimizing Composer autoloader..."
composer dump-autoload --optimize

# Install/update npm dependencies if needed
if [ -f package.json ]; then
    echo "📦 Installing/updating npm dependencies..."
    npm install
fi

# Build assets for development
if [ -f vite.config.js ]; then
    echo "🎨 Building development assets..."
    npm run build
fi

echo ""
echo "✅ Local environment optimization complete!"
echo ""
echo "🎯 Performance improvements applied:"
echo "   • Switched from remote MySQL to local SQLite"
echo "   • Changed cache from database to file-based"
echo "   • Changed sessions from database to file-based"
echo "   • Set environment to 'local' for better debugging"
echo "   • Optimized autoloader"
echo "   • Cleared all caches"
echo ""
echo "🚀 Your Laravel application should now run much faster!"
echo ""
echo "To start development server:"
echo "   composer run dev"
echo ""
echo "To restore production environment:"
echo "   cp .env.backup .env (if backup exists)"