<?php

use App\Http\Controllers\GlobalPurchaseAndSalesSearchController;
use App\Http\Controllers\WsMonitorController;
use App\Http\Controllers\PricePointController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('auth.login');
})->middleware('guest');

Auth::routes(['register' => false]);

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    Route::get('/home', 'HomeController@index')
        ->name('home');

    Route::get('/dashboard', 'HomeController@dashboard')
        ->name('dashboard');

    Route::get('/sales-purchases/chart-data', 'HomeController@salesPurchasesChart')
        ->name('sales-purchases.chart');

    Route::get('/current-month/chart-data', 'HomeController@currentMonthChart')
        ->name('current-month.chart');

    Route::get('/payment-flow/chart-data', 'HomeController@paymentChart')
        ->name('payment-flow.chart');
});

Route::middleware(['auth']) // tighten as you like (e.g. 'can:view-ws-monitor')
->group(function () {
    Route::get('/ws-monitor', [WsMonitorController::class, 'index'])->name('ws.monitor');
    Route::get('/ws-monitor/data', [WsMonitorController::class, 'data'])->name('ws.monitor.data');
    Route::get('/ws-monitor/presence/{name}', [WsMonitorController::class, 'presence'])->name('ws.monitor.presence');

    // Global Purchase and Sales Search Routes
    Route::get('/global-search', [GlobalPurchaseAndSalesSearchController::class, 'index'])
        ->name('global-purchase-and-sales-search.index');
    Route::post('/global-search/search', [GlobalPurchaseAndSalesSearchController::class, 'search'])
        ->name('global-purchase-and-sales-search.search');
    Route::get('/global-search/suggestions', [GlobalPurchaseAndSalesSearchController::class, 'suggestions'])
        ->name('global-purchase-and-sales-search.suggestions');
    Route::get('/global-search/statistics', [GlobalPurchaseAndSalesSearchController::class, 'statistics'])
        ->name('global-purchase-and-sales-search.statistics');

    // Notifications
    Route::get('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read'])
        ->name('notifications.read');

    Route::group(['middleware' => ['role.setting', 'can:notifications.access']], function () {
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.markAllRead');
    });
});

Route::middleware(['auth', 'role.setting'])
    ->group(function () {
        Route::get('/price-points', [PricePointController::class, 'index'])
            ->middleware('can:pricePoints.access')
            ->name('price-points.index');
    });
