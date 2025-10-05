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

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Generate PDF
    Route::get('/sale-returns/pdf/{id}', function ($id) {
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::findOrFail($id);
        $customer = \Modules\People\Entities\Customer::findOrFail($saleReturn->customer_id);

        $pdf = \PDF::loadView('salesreturn::print', [
            'sale_return' => $saleReturn,
            'customer' => $customer,
        ])->setPaper('a4');

        return $pdf->stream('sale-return-'. $saleReturn->reference .'.pdf');
    })->name('sale-returns.pdf');

    Route::post('sale-returns/{sale_return}/approve', 'SalesReturnController@approve')
        ->name('sale-returns.approve');
    Route::post('sale-returns/{sale_return}/reject', 'SalesReturnController@reject')
        ->name('sale-returns.reject');
    Route::post('sale-returns/{sale_return}/receive', 'SalesReturnController@receive')
        ->name('sale-returns.receive');
    Route::get('sale-returns/{sale_return}/settlement', 'SalesReturnController@settlement')
        ->name('sale-returns.settlement');

    //Sale Returns
    Route::resource('sale-returns', 'SalesReturnController');

    //Payments
    Route::get('/sale-return-payments/{sale_return_id}', 'SaleReturnPaymentsController@index')
        ->name('sale-return-payments.index');
});
