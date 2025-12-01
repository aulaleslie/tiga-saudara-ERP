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
use Modules\Sale\Entities\Sale;
use Modules\Sale\Http\Controllers\PosController;
use Modules\Sale\Http\Controllers\SaleController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    Route::get('/app/pos/session', [PosController::class, 'session'])->name('app.pos.session');
    Route::get('/app/pos/sessions/monitor', [PosController::class, 'monitor'])
        ->name('app.pos.monitor')
        ->middleware('can:reports.access');

    Route::middleware('pos.session')->group(function () {
        //POS
        Route::get('/app/pos', 'PosController@index')->name('app.pos.index');
        Route::post('/app/pos', 'PosController@store')->name('app.pos.store');
        Route::post('/pos/store-as-quotation', [PosController::class, 'storeAsQuotation'])->name('app.pos.store-as-quotation');
        Route::post('/app/pos/reprint-last', [PosController::class, 'reprintLast'])->name('app.pos.reprint-last');

        Route::view('/app/pos/cash-settlement', 'sale::pos.cash-settlement')->name('app.pos.cash-settlement');
        Route::view('/app/pos/cash-pickup', 'sale::pos.cash-pickup')->name('app.pos.cash-pickup');
        Route::view('/app/pos/cash-reconciliation', 'sale::pos.cash-reconciliation')->name('app.pos.cash-reconciliation');
    });


    //Generate PDF
    Route::get('/sales/{sale}/delivery-slip', [SaleController::class, 'deliverySlip'])
        ->name('sales.deliverySlip');

    Route::get('/sales/{sale}/invoice', [SaleController::class, 'invoicePdf'])
        ->name('sales.invoicePdf');

    Route::get('/sales/pos/pdf/{id}', function ($id) {
        $sale = Sale::with(['saleDetails.product.conversions.unit', 'saleDetails.product.conversions.prices', 'saleDetails.product.baseUnit', 'saleDetails.product.prices', 'customer', 'posReceipt.sales.saleDetails.product.conversions.unit', 'posReceipt.sales.saleDetails.product.conversions.prices', 'posReceipt.sales.saleDetails.product.baseUnit', 'posReceipt.sales.saleDetails.product.prices', 'posReceipt.sales.tenantSetting', 'posReceipt.sales.customer'])
            ->findOrFail($id);

        $receipt = $sale->posReceipt;
        $viewData = $receipt ? ['receipt' => $receipt] : ['sale' => $sale];
        $fileReference = $receipt?->receipt_number ?? $sale->reference;

        $pdf = \PDF::loadView('sale::print-pos', $viewData)->setPaper('a7')
            ->setOption('margin-top', 8)
            ->setOption('margin-bottom', 8)
            ->setOption('margin-left', 5)
            ->setOption('margin-right', 5);

        return $pdf->stream('sale-' . $fileReference . '.pdf');
    })->name('sales.pos.pdf');

    //Sales
    Route::post('/sales/{sale}/dispatch', [SaleController::class, 'storeDispatch'])->name('sales.storeDispatch');
    Route::get('/sales/{sale}/dispatch', [SaleController::class, 'dispatch'])->name('sales.dispatch');
    Route::patch('sales/{sale}/status', [SaleController::class, 'updateStatus'])->name('sales.updateStatus');
    Route::resource('sales', 'SaleController')->middleware('idempotency');

    //Payments
    Route::get('/sale-payments/{sale_id}', 'SalePaymentsController@index')->name('sale-payments.index');
    Route::get('/sale-payments/{sale_id}/create', 'SalePaymentsController@create')->name('sale-payments.create');
    Route::post('/sale-payments/store', 'SalePaymentsController@store')->name('sale-payments.store');
    Route::get('/sale-payments/{sale_id}/edit/{salePayment}', 'SalePaymentsController@edit')->name('sale-payments.edit');
    Route::patch('/sale-payments/update/{salePayment}', 'SalePaymentsController@update')->name('sale-payments.update');
    Route::delete('/sale-payments/destroy/{salePayment}', 'SalePaymentsController@destroy')->name('sale-payments.destroy');

    // Global Menu - Track Sales by Serial Number
    Route::get('/global-sales-search', 'GlobalSalesSearchController@index')->name('global-sales-search.index')->middleware('auth');
    Route::get('/global-sales-search/search', 'GlobalSalesSearchController@ajaxSearch')->name('global-sales-search.search')->middleware('auth');

    // POS Transactions History
    Route::get('/pos-transactions', function () {
        return view('sale::pos.transactions');
    })->name('pos.transactions.index')->middleware('can:pos.transactions.access');
});
