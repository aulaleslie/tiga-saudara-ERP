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
use Modules\Sale\Http\Controllers\SalesUploadController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    // Sales Upload/Import Routes
    Route::get('/sales/imports', [SalesUploadController::class, 'index'])->name('sales.imports.index');
    Route::get('/sales/upload', [SalesUploadController::class, 'uploadPage'])->name('sales.upload.form');
    Route::post('/sales/upload', [SalesUploadController::class, 'upload'])->name('sales.upload.store');
    Route::get('/sales/upload/template', [SalesUploadController::class, 'downloadTemplate'])->name('sales.upload.template');
    Route::get('/sales/imports/{batch}', [SalesUploadController::class, 'show'])->name('sales.imports.show');

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

    Route::get('/sales/pos/pdf/{sale}', [SaleController::class, 'posPdf'])->name('sales.pos.pdf');

    //Sales
    Route::post('/sales/{sale}/dispatch', [SaleController::class, 'storeDispatch'])->name('sales.storeDispatch');
    Route::get('/sales/{sale}/dispatch', [SaleController::class, 'dispatch'])->name('sales.dispatch');
    Route::post('/dispatches/{dispatch}/approve', [SaleController::class, 'approveDispatch'])->name('dispatches.approve');
    Route::post('/dispatches/{dispatch}/reject', [SaleController::class, 'rejectDispatch'])->name('dispatches.reject');
    Route::patch('sales/{sale}/status', [SaleController::class, 'updateStatus'])->name('sales.updateStatus');
    Route::put('sales/{sale}/archive', [SaleController::class, 'archive'])->name('sales.archive');
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

    // POS Receipt Print (opens receipt page and auto-triggers browser print dialog)
    Route::get('/pos-receipt/{receipt}/print', function (\App\Models\PosReceipt $receipt) {
        // Load relationships needed for the receipt view
        $receipt->load([
            'sales.saleDetails.product.conversions.unit',
            'sales.saleDetails.product.conversions.prices',
            'sales.saleDetails.product.baseUnit',
            'sales.saleDetails.product.prices',
            'sales.tenantSetting',
            'sales.customer'
        ]);

        // Verify tenant access
        if ($receipt->sales->first()?->setting_id !== session('setting_id')) {
            abort(403, 'Unauthorized access to receipt');
        }

        return view('sale::print-pos', [
            'receipt' => $receipt,
            'autoPrint' => true, // Flag to auto-trigger print dialog
        ]);
    })->name('pos.receipt.print')->middleware('can:pos.transactions.access');

});
