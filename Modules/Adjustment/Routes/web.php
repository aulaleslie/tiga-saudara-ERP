<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Product Adjustment
    Route::get('/adjustments/create-breakage', 'AdjustmentController@createBreakage')->name('adjustments.createBreakage');
    Route::post('/adjustments/store-breakage', 'AdjustmentController@storeBreakage')->name('adjustments.storeBreakage');
    Route::get('/adjustments/breakage/{adjustment}/edit', 'AdjustmentController@editBreakage')->name('adjustments.editBreakage');
    Route::patch('/adjustments/breakage/{adjustment}', 'AdjustmentController@updateBreakage')->name('adjustments.updateBreakage');

    Route::patch('/adjustments/approve/{adjustment}', 'AdjustmentController@approve')->name('adjustments.approve');
    Route::patch('/adjustments/reject/{adjustment}', 'AdjustmentController@reject')->name('adjustments.reject');
    Route::resource('adjustments', 'AdjustmentController');
    Route::post('/transfers/{transfer}/approve', 'TransferStockController@approve')->name('transfers.approve');
    Route::post('/transfers/{transfer}/reject', 'TransferStockController@reject')->name('transfers.reject');
    Route::post('/transfers/{transfer}/dispatch', 'TransferStockController@dispatchShipment')->name('transfers.dispatch');
    Route::post('/transfers/{transfer}/receive', 'TransferStockController@receive')->name('transfers.receive');
    Route::post('/transfers/{transfer}/return-dispatch', 'TransferStockController@dispatchReturn')->name('transfers.return-dispatch');
    Route::post('/transfers/{transfer}/return-receive', 'TransferStockController@receiveReturn')->name('transfers.return-receive');
    Route::resource('transfers', 'TransferStockController');
});
