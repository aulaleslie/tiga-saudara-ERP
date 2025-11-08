<?php

use Illuminate\Http\Request;
use Modules\Sale\Http\Controllers\GlobalMenuController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/sale', function (Request $request) {
    return $request->user();
});

// Global Menu (Serial Number Search) Routes
Route::middleware('auth:sanctum')->prefix('global-menu')->group(function () {
    // Search for sales by various criteria (POST for complex queries)
    Route::post('/search', [GlobalMenuController::class, 'search'])
        ->name('api.global-menu.search');

    // Search for sales by reference number
    Route::get('/sales/{reference}', [GlobalMenuController::class, 'searchByReference'])
        ->name('api.global-menu.search-by-reference');

    // Get serial number details with associated sales
    Route::get('/serials/{id}', [GlobalMenuController::class, 'getSerialDetails'])
        ->name('api.global-menu.serial-details');

    // Autocomplete suggestions
    Route::get('/suggest', [GlobalMenuController::class, 'suggest'])
        ->name('api.global-menu.suggest');
});