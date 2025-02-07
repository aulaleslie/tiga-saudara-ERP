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

use App\Events\PrintJobEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Http\Controllers\PurchaseController;
use Modules\Purchase\Http\Controllers\PurchasePaymentsController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    Route::get('/purchases/datatable', [PurchaseController::class, 'datatable'])->name('datatable.purchases');
    //Generate PDF
    Route::get('/purchases/pdf/{id}', function ($id) {
        $purchase = Purchase::findOrFail($id);
        $supplier = Supplier::findOrFail($purchase->supplier_id);

        Log::info("Caller: ", ["htmlContent" => "test"]);
        Log::info("Caller: ", ["type" => "a4"]);
        Log::info("Caller: ", ["userId", auth()->id()]);

        trigger_pusher_event('print-jobs.' . auth()->id(), 'PrintJobDispatched', [
            'type' => 'thermal',
            'content' => 'Test'
        ]);
        $pdf = \PDF::loadView('purchase::print', [
            'purchase' => $purchase,
            'supplier' => $supplier,
        ])->setPaper('a4');

        return $pdf->stream('purchase-'. $purchase->reference .'.pdf');
    })->name('purchases.pdf');

    //Purchases
    Route::post('/purchases/{purchase}/receive', [PurchaseController::class, 'storeReceive'])->name('purchases.storeReceive');
    Route::get('/purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive');
    Route::patch('purchases/{purchase}/status', [PurchaseController::class, 'updateStatus'])->name('purchases.updateStatus');
    Route::resource('purchases', 'PurchaseController');

    //Payments
    Route::get('/purchase-payments/datatable/{purchase_id}', [PurchasePaymentsController::class, 'datatable'])
        ->name('datatable.purchase_payments');
    Route::get('/purchase-payments/{purchase_id}', 'PurchasePaymentsController@index')->name('purchase-payments.index');
    Route::get('/purchase-payments/{purchase_id}/create', 'PurchasePaymentsController@create')->name('purchase-payments.create');
    Route::post('/purchase-payments/store', 'PurchasePaymentsController@store')->name('purchase-payments.store');
    Route::get('/purchase-payments/{purchase_id}/edit/{purchasePayment}', 'PurchasePaymentsController@edit')->name('purchase-payments.edit');
    Route::patch('/purchase-payments/update/{purchasePayment}', 'PurchasePaymentsController@update')->name('purchase-payments.update');
    Route::delete('/purchase-payments/destroy/{purchasePayment}', 'PurchasePaymentsController@destroy')->name('purchase-payments.destroy');

});
