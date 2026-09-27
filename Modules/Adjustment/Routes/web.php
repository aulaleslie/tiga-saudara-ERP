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

    // Version 2 Forward Receipt Movement Routes (Gated by v2_dispatch_enabled config)
    Route::get('/transfers/{transfer}/movements/receipt/prepare', 'ForwardReceiptMovementController@prepare')->name('transfers.movements.receipt.prepare');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/confirm-empty', 'ForwardReceiptMovementController@confirmEmpty')->name('transfers.movements.receipt.confirm-empty');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/scan', 'ForwardReceiptMovementController@scan')->name('transfers.movements.receipt.scan');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/set-quantity', 'ForwardReceiptMovementController@setQuantity')->name('transfers.movements.receipt.set-quantity');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/submit', 'ForwardReceiptMovementController@submit')->name('transfers.movements.receipt.submit');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/cancel', 'ForwardReceiptMovementController@cancel')->name('transfers.movements.receipt.cancel');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/correct', 'ForwardReceiptMovementController@correct')->name('transfers.movements.receipt.correct');
    Route::get('/transfers/{transfer}/movements/{movement}/receipt/review', 'ForwardReceiptMovementController@review')->name('transfers.movements.receipt.review');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/approve', 'ForwardReceiptMovementController@approve')->name('transfers.movements.receipt.approve');
    Route::post('/transfers/{transfer}/movements/{movement}/receipt/reject', 'ForwardReceiptMovementController@reject')->name('transfers.movements.receipt.reject');

    // Version 2 Return Dispatch Movement Routes (Gated by v2_dispatch_enabled config)
    Route::post('/transfers/{transfer}/movements/return/create', 'ReturnDispatchMovementController@create')->name('transfers.movements.return.create');
    Route::get('/transfers/{transfer}/movements/return/prepare', 'ReturnDispatchMovementController@prepare')->name('transfers.movements.return.prepare');
    Route::post('/transfers/{transfer}/movements/{movement}/return/scan', 'ReturnDispatchMovementController@scan')->name('transfers.movements.return.scan');
    Route::post('/transfers/{transfer}/movements/{movement}/return/set-quantity', 'ReturnDispatchMovementController@setQuantity')->name('transfers.movements.return.set-quantity');
    Route::post('/transfers/{transfer}/movements/{movement}/return/submit', 'ReturnDispatchMovementController@submit')->name('transfers.movements.return.submit');
    Route::post('/transfers/{transfer}/movements/{movement}/return/cancel', 'ReturnDispatchMovementController@cancel')->name('transfers.movements.return.cancel');
    Route::post('/transfers/{transfer}/movements/{movement}/return/correct', 'ReturnDispatchMovementController@correct')->name('transfers.movements.return.correct');
    Route::get('/transfers/{transfer}/movements/{movement}/return/review', 'ReturnDispatchMovementController@review')->name('transfers.movements.return.review');
    Route::post('/transfers/{transfer}/movements/{movement}/return/approve', 'ReturnDispatchMovementController@approve')->name('transfers.movements.return.approve');
    Route::post('/transfers/{transfer}/movements/{movement}/return/reject', 'ReturnDispatchMovementController@reject')->name('transfers.movements.return.reject');
    // Version 2 Return Receipt Movement Routes (Gated by v2_dispatch_enabled config)
    Route::get('/transfers/{transfer}/movements/return-receipt/prepare', 'ReturnReceiptMovementController@prepare')->name('transfers.movements.return-receipt.prepare');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/confirm-empty', 'ReturnReceiptMovementController@confirmEmpty')->name('transfers.movements.return-receipt.confirm-empty');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/scan', 'ReturnReceiptMovementController@scan')->name('transfers.movements.return-receipt.scan');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/set-quantity', 'ReturnReceiptMovementController@setQuantity')->name('transfers.movements.return-receipt.set-quantity');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/submit', 'ReturnReceiptMovementController@submit')->name('transfers.movements.return-receipt.submit');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/cancel', 'ReturnReceiptMovementController@cancel')->name('transfers.movements.return-receipt.cancel');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/correct', 'ReturnReceiptMovementController@correct')->name('transfers.movements.return-receipt.correct');
    Route::get('/transfers/{transfer}/movements/{movement}/return-receipt/review', 'ReturnReceiptMovementController@review')->name('transfers.movements.return-receipt.review');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/approve', 'ReturnReceiptMovementController@approve')->name('transfers.movements.return-receipt.approve');
    Route::post('/transfers/{transfer}/movements/{movement}/return-receipt/reject', 'ReturnReceiptMovementController@reject')->name('transfers.movements.return-receipt.reject');

    // Workflow version 3 (location-free entry, approver allocations,
    // approval-time dispatch, confirmation receipt, dispatch cancellation)
    Route::get('/transfers/{transfer}/v3/approval', 'TransferV3Controller@approvalWorkspace')->name('transfers.v3.approval');
    Route::post('/transfers/{transfer}/v3/approval/progress', 'TransferV3Controller@saveProgress')->name('transfers.v3.approval.progress');
    Route::post('/transfers/{transfer}/v3/approve', 'TransferV3Controller@approve')->name('transfers.v3.approve');
    Route::post('/transfers/{transfer}/v3/reject', 'TransferV3Controller@reject')->name('transfers.v3.reject');
    Route::post('/transfers/{transfer}/v3/acknowledge-rejection', 'TransferV3Controller@acknowledgeRejection')->name('transfers.v3.acknowledge-rejection');
    Route::post('/transfers/{transfer}/v3/submit', 'TransferV3Controller@submit')->name('transfers.v3.submit');
    Route::post('/transfers/{transfer}/v3/receive', 'TransferV3Controller@receive')->name('transfers.v3.receive');
    Route::post('/transfers/{transfer}/v3/cancel-dispatch', 'TransferV3Controller@cancelDispatch')->name('transfers.v3.cancel-dispatch');

    Route::resource('transfers', 'TransferStockController');
});
