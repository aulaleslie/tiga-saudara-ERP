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

use Modules\Setting\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;
use Modules\Setting\Http\Controllers\BusinessController;
use Modules\Setting\Http\Controllers\JournalController;
use Modules\Setting\Http\Controllers\PaymentMethodController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    //Mail Settings
    Route::patch('/settings/smtp', 'SettingController@updateSmtp')->name('settings.smtp.update');
    //General Settings
    Route::get('/settings', 'SettingController@index')->name('settings.index');
    Route::patch('/settings', 'SettingController@update')->name('settings.update');
    // Units
    Route::resource('units', 'UnitsController')->except('show');
    Route::resource('businesses', 'BusinessController');
    Route::post('/update-active-business', [BusinessController::class, 'updateActiveBusiness'])->name('update.active.business');
    // Locations
    Route::resource('locations', 'LocationController')->except('show');
    // Taxes
    Route::resource('taxes', 'TaxController')->except('show');
    // PaymentTerms
    Route::resource('payment-terms', 'PaymentTermController')->except('show');
    // Chart of accounts
    Route::resource('chart-of-account', 'ChartofAccountController')->except('show');
    // Journals
    Route::resource('journals', JournalController::class);

    Route::get('/print-receipt', function() {

        $pdf = \PDF::loadView('setting::print.receipt', [
        ])->setPaper('a4');

        return $pdf->stream('receipt.pdf');
    })->name('print.receipt');
    Route::get('/print-sales-document', function() {

        $pdf = \PDF::loadView('setting::print.sales', [
        ])->setPaper('a4');

        return $pdf->stream('sales.pdf');
    })->name('print.salesDocument');

    Route::resource('payment-methods', PaymentMethodController::class)
        ->except('show');

});
