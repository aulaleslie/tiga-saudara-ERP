<?php

use App\Http\Controllers\PricePointController;
use App\Http\Controllers\WsMonitorController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Modules\Setting\Entities\Setting;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    $settings = Setting::orderBy('id')->get(['id','company_name']);
    return view('auth.login', compact('settings'));
})->middleware('guest');

Auth::routes(['register' => false]);

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    Route::get('/home', 'HomeController@index')
        ->name('home');

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
    Route::get('/ws-test', fn () => view('ws-test'));
});

Route::get('/price-points/{setting}', [PricePointController::class, 'index'])
    ->whereNumber('setting')
    ->name('price-points.index');

