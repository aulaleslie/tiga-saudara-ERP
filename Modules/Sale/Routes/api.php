<?php

use Illuminate\Http\Request;
use Modules\Sale\Http\Controllers\GlobalSalesSearchController;
use Modules\Sale\Http\Controllers\PosCheckoutController;

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

// POS Checkout Routes - Multi-stage Sequential Payments
Route::middleware('auth:sanctum')->prefix('pos/sell/checkout')->group(function () {
    Route::post('/stage-payment', [PosCheckoutController::class, 'stagePayment'])
        ->name('api.pos.checkout.stage-payment');

    Route::get('/payment-chain', [PosCheckoutController::class, 'getPaymentChain'])
        ->name('api.pos.checkout.payment-chain');

    Route::post('/validate-edc-reference', [PosCheckoutController::class, 'validateEdcReference'])
        ->name('api.pos.checkout.validate-edc-reference');
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