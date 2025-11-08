<?php

use Illuminate\Http\Request;
use Modules\Sale\Http\Controllers\GlobalSalesSearchController;

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
Route::middleware('auth:sanctum')->prefix('global-sales-search')->group(function () {
    // Search for sales by various criteria (POST for complex queries)
    Route::post('/search', [GlobalSalesSearchController::class, 'search'])
        ->name('api.global-sales-search.search');

    // Search for sales by reference number
    Route::get('/sales/{reference}', [GlobalSalesSearchController::class, 'searchByReference'])
        ->name('api.global-sales-search.search-by-reference');

    // Get serial number details with associated sales
    Route::get('/serials/{id}', [GlobalSalesSearchController::class, 'getSerialDetails'])
        ->name('api.global-sales-search.serial-details');

    // Autocomplete suggestions
    Route::get('/suggest', [GlobalSalesSearchController::class, 'suggest'])
        ->name('api.global-sales-search.suggest');
});