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
use Modules\Sale\Http\Controllers\SaleController;
use Modules\Sale\Http\Controllers\SalesUploadController;
use Modules\Sale\Http\Controllers\GlobalSalePaymentController;
use Modules\Sale\Http\Controllers\SaleReportingDateController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    // Global Payments - Cross-setting multi-invoice payment (MUST be before resource route)
    Route::get('/sales/global-payments', [GlobalSalePaymentController::class, 'index'])
        ->name('sales.global-payments.index')
        ->middleware('permission:salePayments.global.access');
    Route::get('/sales/{sale_id}/global-payments/show', [GlobalSalePaymentController::class, 'show'])
        ->name('sales.global-payments.show')
        ->middleware('permission:salePayments.global.access');
    Route::get('/sales/{sale_id}/global-payments/history', [GlobalSalePaymentController::class, 'history'])
        ->name('sales.global-payments.history')
        ->middleware('permission:salePayments.global.access');
    Route::get('/sales/{sale_id}/global-payments/create', [GlobalSalePaymentController::class, 'create'])
        ->name('sales.global-payments.create')
        ->middleware(['permission:salePayments.global.access', 'permission:salePayments.create']);
    Route::post('/sales/{sale_id}/global-payments/store', [GlobalSalePaymentController::class, 'store'])
        ->name('sales.global-payments.store')
        ->middleware(['permission:salePayments.global.access', 'permission:salePayments.create', 'idempotency']);

    // Sales Upload/Import Routes
    Route::get('/sales/imports', [SalesUploadController::class, 'index'])->name('sales.imports.index');
    Route::get('/sales/upload', [SalesUploadController::class, 'uploadPage'])->name('sales.upload.form');
    Route::post('/sales/upload', [SalesUploadController::class, 'upload'])->name('sales.upload.store');
    Route::get('/sales/upload/template', [SalesUploadController::class, 'downloadTemplate'])->name('sales.upload.template');
    Route::get('/sales/imports/{batch}', [SalesUploadController::class, 'show'])->name('sales.imports.show');

    //Generate PDF
    Route::get('/sales/{sale}/delivery-slip', [SaleController::class, 'deliverySlip'])
        ->name('sales.deliverySlip');

    Route::get('/sales/{sale}/invoice', [SaleController::class, 'invoicePdf'])
        ->name('sales.invoicePdf');

    //Sales
    Route::get('/sales/dispatches', [SaleController::class, 'dispatchIndex'])
        ->name('sales.dispatches.index');

    //Sales
    Route::post('/sales/{sale}/dispatch', [SaleController::class, 'storeDispatch'])->name('sales.storeDispatch');
    Route::get('/sales/{sale}/dispatch', [SaleController::class, 'dispatch'])->name('sales.dispatch');
    Route::post('/dispatches/{dispatch}/approve', [SaleController::class, 'approveDispatch'])->name('dispatches.approve');
    Route::post('/dispatches/{dispatch}/reject', [SaleController::class, 'rejectDispatch'])->name('dispatches.reject');
    Route::patch('sales/{sale}/status', [SaleController::class, 'updateStatus'])->name('sales.updateStatus');
    Route::put('sales/{sale}/archive', [SaleController::class, 'archive'])->name('sales.archive');

    // Sale Reporting Date Overrides
    Route::post('/sales/{sale}/reporting-date', [SaleReportingDateController::class, 'store'])->name('sales.reporting-date.store');
    Route::delete('/sales/{sale}/reporting-date', [SaleReportingDateController::class, 'destroy'])->name('sales.reporting-date.destroy');

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

});
