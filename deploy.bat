@echo off
REM ============================================
REM Production Deployment Script for Tiga Saudara ERP
REM ============================================

echo.
echo ============================================
echo   Tiga Saudara ERP - Production Deployment
echo ============================================
echo.

REM Step 1: Enable maintenance mode
echo [1/10] Enabling maintenance mode...
php artisan down --message="Sedang dalam proses update. Silakan tunggu beberapa menit."

REM Step 2: Pull latest code (uncomment if using git)
REM echo [2/10] Pulling latest code from repository...
REM git pull origin main

REM Step 3: Install/update Composer dependencies
echo [2/10] Installing Composer dependencies (production)...
call composer install --no-dev --optimize-autoloader

REM Step 4: Install NPM dependencies
echo [3/10] Installing NPM dependencies...
call npm ci

REM Step 5: Build frontend assets
echo [4/10] Building frontend assets for production...
call npm run build

REM Step 6: Clear all caches
echo [5/10] Clearing all caches...
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

REM Step 7: Cache configuration for performance
echo [6/10] Caching configuration for performance...
php artisan config:cache
php artisan route:cache
php artisan view:cache

REM Step 8: Run database migrations
echo [7/10] Running database migrations...
php artisan migrate --force

REM Step 9: Optimize autoloader
echo [8/10] Optimizing application...
php artisan optimize

REM Step 10: Restart queue workers (if using queues)
echo [9/10] Restarting queue workers...
php artisan queue:restart

REM Step 11: Disable maintenance mode
echo [10/10] Disabling maintenance mode...
php artisan up

echo.
echo ============================================
echo   Deployment completed successfully!
echo ============================================
echo.

pause
