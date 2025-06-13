# Docker Vite Production Mode Fix

## Problem Identified

When containers restart, the TrueFire segment pages show blank screens with `ERR_CONNECTION_REFUSED` errors because:

1. **Supervisor auto-starts Vite dev server** despite being configured for production
2. **Vite hot file gets recreated** which forces Laravel to use `localhost:5173` instead of built assets
3. **Mixed .env configuration** with development settings overriding Docker environment variables

## Root Causes

### 1. Supervisor Configuration Issue
- The `vite-dev` process had `autostart=true` instead of `autostart=false`
- This caused Vite dev server to start automatically on container restart
- Dev server creates `/public/hot` file which triggers development mode

### 2. Laravel Environment Conflict
- Container `.env` file had `APP_ENV=local` and `APP_DEBUG=true`
- This overrode Docker environment variables for production mode
- Laravel prioritizes `.env` file over environment variables

### 3. Dockerfile Development Mode
- Dockerfile was configured for development with `npm install --only=dev`
- Build step `npm run build` was commented out
- Port 5173 exposed for Vite dev server

## Complete Fix Applied

### 1. Fixed Supervisor Configuration
```bash
# Stop running Vite dev server
docker exec laravel-app supervisorctl stop vite-dev

# Update supervisor config to disable auto-start
docker exec laravel-app sed -i 's/autostart=true/autostart=false/' /etc/supervisor/conf.d/supervisord.conf
```

### 2. Updated Laravel Environment
```bash
# Set production mode in .env file
docker exec laravel-app sed -i 's/APP_ENV=local/APP_ENV=production/' .env
docker exec laravel-app sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env

# Add Vite production configuration
docker exec laravel-app bash -c "echo 'VITE_BUILD_ONLY=true' >> .env"
docker exec laravel-app bash -c "echo 'VITE_DEV_SERVER_ENABLED=false' >> .env"
docker exec laravel-app bash -c "echo 'VITE_HOT_RELOAD=false' >> .env"
```

### 3. Removed Hot File and Built Assets
```bash
# Remove hot file that forces dev mode
docker exec laravel-app rm -f public/hot

# Build production assets
docker exec laravel-app npm run build

# Clear Laravel caches
docker exec laravel-app php artisan config:clear
docker exec laravel-app php artisan cache:clear
```

## Updated User Rule

**NEW RULE**: After container restarts, run:
```bash
# Quick fix for Vite production mode
docker exec laravel-app supervisorctl stop vite-dev
docker exec laravel-app rm -f public/hot
docker exec laravel-app npm run build
```

## Verification Commands

```bash
# Check Vite dev server is stopped
docker exec laravel-app supervisorctl status | grep vite-dev

# Verify no hot file exists
docker exec laravel-app ls public/hot

# Check environment mode
docker exec laravel-app grep "APP_ENV\|APP_DEBUG" .env

# Test page loads correctly
# Visit: https://transcriptions.ngrok.dev/truefire-courses/85/segments/2231
```

## Long-term Solution

To permanently fix this issue for future container builds:

1. **Update Dockerfile.laravel** to enable production build:
   ```dockerfile
   # Uncomment this line:
   RUN npm run build
   ```

2. **Source supervisor config already fixed** with `autostart=false`

3. **Consider separate dev/prod Docker configurations**

## Expected Results

✅ **Pages load correctly after container restart**  
✅ **No ERR_CONNECTION_REFUSED errors**  
✅ **Assets served from /build/ instead of localhost:5173**  
✅ **Hot file stays deleted**  
✅ **Production mode maintained**

## Troubleshooting

If issue returns after restart:
1. Check if `vite-dev` is running: `docker exec laravel-app supervisorctl status`
2. Look for hot file: `docker exec laravel-app ls public/hot` 
3. Run the quick fix commands above
4. Consider rebuilding container with updated Dockerfile 