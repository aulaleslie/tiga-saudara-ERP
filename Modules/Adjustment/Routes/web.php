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

    Route::patch('/adjustments/{adjustment}/submit', 'AdjustmentController@submit')->name('adjustments.submit');
    Route::patch('/adjustments/approve/{adjustment}', 'AdjustmentController@approve')->name('adjustments.approve');
    Route::patch('/adjustments/reject/{adjustment}', 'AdjustmentController@reject')->name('adjustments.reject');
    Route::resource('adjustments', 'AdjustmentController');
    Route::post('/transfers/{transfer}/approve', 'TransferStockController@approve')->name('transfers.approve');
    Route::post('/transfers/{transfer}/reject', 'TransferStockController@reject')->name('transfers.reject');
    Route::post('/transfers/{transfer}/acknowledge-rejection', 'TransferStockController@acknowledgeRejection')->name('transfers.acknowledge-rejection');
    Route::post('/transfers/{transfer}/resubmit', 'TransferStockController@resubmit')->name('transfers.resubmit');
    Route::post('/transfers/{transfer}/archive', 'TransferStockController@archive')->name('transfers.archive');
    Route::post('/transfers/{transfer}/dispatch', 'TransferStockController@dispatchShipment')->name('transfers.dispatch');
    Route::post('/transfers/{transfer}/receive', 'TransferStockController@receive')->name('transfers.receive');
    Route::post('/transfers/{transfer}/return-dispatch', 'TransferStockController@dispatchReturn')->name('transfers.return-dispatch');
    Route::post('/transfers/{transfer}/return-receive', 'TransferStockController@receiveReturn')->name('transfers.return-receive');

    // Version 2 Forward Dispatch Movement Routes (Gated by v2_dispatch_enabled config)
    Route::get('/transfers/{transfer}/movements/prepare', 'ForwardDispatchMovementController@prepare')->name('transfers.movements.prepare');
    Route::post('/transfers/{transfer}/movements/{movement}/scan', 'ForwardDispatchMovementController@scan')->name('transfers.movements.scan');
    Route::post('/transfers/{transfer}/movements/{movement}/set-quantity', 'ForwardDispatchMovementController@setQuantity')->name('transfers.movements.set-quantity');
    Route::post('/transfers/{transfer}/movements/{movement}/submit', 'ForwardDispatchMovementController@submit')->name('transfers.movements.submit');
    Route::post('/transfers/{transfer}/movements/{movement}/cancel', 'ForwardDispatchMovementController@cancel')->name('transfers.movements.cancel');
    Route::post('/transfers/{transfer}/movements/{movement}/correct', 'ForwardDispatchMovementController@correct')->name('transfers.movements.correct');
    Route::get('/transfers/{transfer}/movements/{movement}/review', 'ForwardDispatchMovementController@review')->name('transfers.movements.review');
    Route::post('/transfers/{transfer}/movements/{movement}/approve', 'ForwardDispatchMovementController@approve')->name('transfers.movements.approve');
    Route::post('/transfers/{transfer}/movements/{movement}/reject', 'ForwardDispatchMovementController@reject')->name('transfers.movements.reject');

    Route::resource('transfers', 'TransferStockController');
});
