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

Route::group(['middleware' => ['auth', 'role.setting']], function() {

    //Generate PDF
    Route::get('/purchase-returns/pdf/{id}', function ($id) {
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::findOrFail($id);
        $supplier = \Modules\People\Entities\Supplier::findOrFail($purchaseReturn->supplier_id);

        $pdf = \PDF::loadView('purchasesreturn::print', [
            'purchase_return' => $purchaseReturn,
            'supplier' => $supplier,
        ])->setPaper('a4');

        return $pdf->stream('purchase-return-'. $purchaseReturn->reference .'.pdf');
    })->name('purchase-returns.pdf');

    //Purchase Returns
    Route::get('purchase-returns/{purchase_return}/settlement', 'PurchasesReturnController@settlement')
        ->name('purchase-returns.settlement');
    Route::put('purchase-returns/{purchase_return}/archive', 'PurchasesReturnController@archive')
        ->name('purchase-returns.archive');
    Route::resource('purchase-returns', 'PurchasesReturnController');
    Route::post('purchase-returns/{purchase_return}/approve', 'PurchaseReturnApprovalController@approve')
        ->name('purchase-returns.approve');
    Route::post('purchase-returns/{purchase_return}/reject', 'PurchaseReturnApprovalController@reject')
        ->name('purchase-returns.reject');
    Route::post('purchase-returns/{purchase_return}/dispatch-request', 'PurchaseReturnDispatchController@requestDispatch')
        ->name('purchase-returns.dispatch-request');
    Route::post('purchase-returns/{purchase_return}/dispatch-approve', 'PurchaseReturnDispatchController@approveDispatch')
        ->name('purchase-returns.dispatch-approve');
    Route::post('purchase-returns/{purchase_return}/dispatch-reject', 'PurchaseReturnDispatchController@rejectDispatch')
        ->name('purchase-returns.dispatch-reject');
    
    //Settlements
    Route::group(['prefix' => 'purchase-returns/settlements', 'as' => 'purchase-return-settlements.'], function () {
        Route::get('/', 'PurchasesReturnSettlementController@index')->name('index');
        Route::post('/{purchase_return}', 'PurchasesReturnSettlementController@store')->name('store');
        Route::post('/{settlement}/submit', 'PurchasesReturnSettlementController@submit')->name('submit');
        Route::post('/{settlement}/approve', 'PurchasesReturnSettlementController@approve')->name('approve');
        Route::post('/{settlement}/reject', 'PurchasesReturnSettlementController@reject')->name('reject');
        Route::post('/{settlement}/execute', 'PurchasesReturnSettlementController@execute')->name('execute');
        Route::post('/{settlement}/dispatch', 'PurchasesReturnSettlementController@dispatchStock')->name('dispatch');
        Route::post('/{settlement}/receive', 'PurchasesReturnSettlementController@receiveStock')->name('receive');

        // Per-item settlement approval routes
        Route::post('/item/{itemSettlement}/approve', 'PurchasesReturnSettlementController@approveItemSettlement')
            ->name('item.approve');
        Route::post('/item/{itemSettlement}/reject', 'PurchasesReturnSettlementController@rejectItemSettlement')
            ->name('item.reject');
        Route::post('/item/{itemSettlement}/receive', 'PurchasesReturnSettlementController@receiveItemSettlement')
            ->name('item.receive');
    });

    //Payments
    Route::get('/purchase-return-payments/{purchase_return_id}', 'PurchaseReturnPaymentsController@index')
        ->name('purchase-return-payments.index');
    Route::get('/purchase-return-payments/{purchase_return_id}/create', 'PurchaseReturnPaymentsController@create')
        ->name('purchase-return-payments.create');
    Route::post('/purchase-return-payments/store', 'PurchaseReturnPaymentsController@store')
        ->name('purchase-return-payments.store');
    Route::get('/purchase-return-payments/{purchase_return_id}/edit/{purchaseReturnPayment}', 'PurchaseReturnPaymentsController@edit')
        ->name('purchase-return-payments.edit');
    Route::patch('/purchase-return-payments/update/{purchaseReturnPayment}', 'PurchaseReturnPaymentsController@update')
        ->name('purchase-return-payments.update');
    Route::delete('/purchase-return-payments/destroy/{purchaseReturnPayment}', 'PurchaseReturnPaymentsController@destroy')
        ->name('purchase-return-payments.destroy');

});
