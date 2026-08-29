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

REM Step 6: Download vendor assets for offline use
echo [5/10] Downloading vendor assets...

REM Create directories
if not exist "public\vendor\jquery" mkdir "public\vendor\jquery"
if not exist "public\vendor\datatables" mkdir "public\vendor\datatables"
if not exist "public\vendor\bootstrap-icons\fonts" mkdir "public\vendor\bootstrap-icons\fonts"
if not exist "public\vendor\pdfmake" mkdir "public\vendor\pdfmake"
if not exist "public\vendor\perfect-scrollbar" mkdir "public\vendor\perfect-scrollbar"
if not exist "public\vendor\popperjs" mkdir "public\vendor\popperjs"
if not exist "public\vendor\chartjs" mkdir "public\vendor\chartjs"
if not exist "public\vendor\font-awesome\css" mkdir "public\vendor\font-awesome\css"
if not exist "public\vendor\font-awesome\webfonts" mkdir "public\vendor\font-awesome\webfonts"

REM Download files using curl -sL (silent, follow redirects)
curl -sL -o public\vendor\jquery\jquery-3.7.0.min.js "https://code.jquery.com/jquery-3.7.0.min.js"
curl -sL -o public\vendor\datatables\datatables.min.css "https://cdn.datatables.net/v/bs4/jszip-3.10.1/dt-1.13.5/b-2.4.1/b-html5-2.4.1/b-print-2.4.1/sl-1.7.0/datatables.min.css"
curl -sL -o public\vendor\datatables\datatables.min.js "https://cdn.datatables.net/v/bs4/jszip-3.10.1/dt-1.13.5/b-2.4.1/b-html5-2.4.1/b-print-2.4.1/sl-1.7.0/datatables.min.js"
curl -sL -o public\vendor\bootstrap-icons\bootstrap-icons.min.css "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
curl -sL -o public\vendor\bootstrap-icons\fonts\bootstrap-icons.woff2 "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"
curl -sL -o public\vendor\bootstrap-icons\fonts\bootstrap-icons.woff "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff"
curl -sL -o public\vendor\pdfmake\pdfmake.min.js "https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"
curl -sL -o public\vendor\pdfmake\vfs_fonts.js "https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"
curl -sL -o public\vendor\perfect-scrollbar\perfect-scrollbar.min.js "https://cdnjs.cloudflare.com/ajax/libs/jquery.perfect-scrollbar/1.4.0/perfect-scrollbar.min.js"
curl -sL -o public\vendor\popperjs\popper.min.js "https://cdn.jsdelivr.net/npm/@popperjs/core@2.10.2/dist/umd/popper.min.js"
curl -sL -o public\vendor\chartjs\chart.min.js "https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.5.0/chart.min.js"
curl -sL -o public\vendor\font-awesome\css\all.min.css "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.14.0/css/all.min.css"

REM Font Awesome webfonts
curl -sL -o public\vendor\font-awesome\webfonts\fa-solid-900.woff2 "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.14.0/webfonts/fa-solid-900.woff2"
curl -sL -o public\vendor\font-awesome\webfonts\fa-regular-400.woff2 "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.14.0/webfonts/fa-regular-400.woff2"
curl -sL -o public\vendor\font-awesome\webfonts\fa-brands-400.woff2 "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.14.0/webfonts/fa-brands-400.woff2"

REM Step 7: Clear all caches
echo [6/10] Clearing all caches...
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
if errorlevel 1 goto :deployment_failed

REM Seed permissions required by deployed modules
echo [7/10] Seeding permissions...
php artisan db:seed --class="Modules\User\Database\Seeders\PermissionsTableSeeder"
if errorlevel 1 goto :deployment_failed

REM Step 9: Optimize autoloader
echo [8/10] Optimizing application...
php artisan optimize

REM Step 10: Restart queue workers (if using queues)
echo [9/10] Restarting queue workers...
php artisan queue:restart

REM Step 12: Disable maintenance mode
echo [10/10] Disabling maintenance mode...
php artisan up

echo.
echo ============================================
echo   Deployment completed successfully!
echo ============================================
echo.

pause
goto :eof

:deployment_failed
echo.
echo ============================================
echo   Deployment failed. Disabling maintenance mode...
echo ============================================
php artisan up
exit /b 1
