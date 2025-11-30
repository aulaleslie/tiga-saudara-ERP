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
use Barryvdh\DomPDF\Facade\Pdf;
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
        // Ambil data purchase + relasi supplier + details + product
        $purchase = Purchase::with([
            'supplier',
            'purchaseDetails.product', // join ke produk agar dapat nama/unit jika perlu
            'purchaseDetails.product.baseUnit'
        ])->findOrFail($id);

        // Optional: Logging untuk debug
        Log::info("Data", [
            'purchase' => $purchase->toArray(),
            'supplier' => $purchase->supplier->toArray(),
            'details'  => $purchase->purchaseDetails->toArray(),
        ]);

        // Kirim ke view untuk PDF
        $pdf = Pdf::loadView('purchase::print', [
            'purchase' => $purchase,
            'supplier' => $purchase->supplier,
            'details'  => $purchase->purchaseDetails,
        ]);
        return $pdf->stream('purchase-order-'.$purchase->reference.'.pdf');
    })->name('purchases.pdf');

    //Purchases
    Route::middleware('can:purchaseReceivings.access')
        ->get('/purchases/receiving', [PurchaseController::class, 'receivingIndex'])
        ->name('purchases.receiving.index');
    Route::get('/purchases/receivings/{purchase_id}', [PurchaseController::class, 'showReceivings'])
        ->name('purchases.receivings');
    Route::post('/purchases/{purchase}/receive', [PurchaseController::class, 'storeReceive'])->name('purchases.storeReceive');
    Route::get('/purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive');
    Route::patch('purchases/{purchase}/status', [PurchaseController::class, 'updateStatus'])->name('purchases.updateStatus');
    Route::resource('purchases', 'PurchaseController')->middleware('idempotency');

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
