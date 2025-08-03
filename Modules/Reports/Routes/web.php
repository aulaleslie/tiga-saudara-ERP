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

use App\Livewire\Reports\PurchaseReport;
use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\MekariConverterController;
use Modules\Reports\Http\Controllers\PurchaseReportController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Profit Loss Report
    Route::get('/profit-loss-report', 'ReportsController@profitLossReport')
        ->name('profit-loss-report.index');
    //Payments Report
    Route::get('/payments-report', 'ReportsController@paymentsReport')
        ->name('payments-report.index');
    //Sales Report
    Route::get('/sales-report', 'ReportsController@salesReport')
        ->name('sales-report.index');
    //Purchases Report
    Route::get('/purchases-report', 'ReportsController@purchasesReport')
        ->name('purchases-report.index');
    //Sales Return Report
    Route::get('/sales-return-report', 'ReportsController@salesReturnReport')
        ->name('sales-return-report.index');
    //Purchases Return Report
    Route::get('/purchases-return-report', 'ReportsController@purchasesReturnReport')
        ->name('purchases-return-report.index');

    Route::get('/mekari-converter', [MekariConverterController::class, 'convertMekariReport'])->name('reports.mekari-converter.index');
    Route::post('/mekari-converter', [MekariConverterController::class, 'handleMekariReport'])->name('reports.mekari-converter.handle');
    Route::post('/mekari-converter/xlsx', [MekariConverterController::class, 'handleXlsxReport'])->name('reports.mekari-converter.xlsx.handle');
    Route::post('/mekari-converter/convert-filtered-csv-to-xlsx', [MekariConverterController::class, 'convertFilteredCsvToFormattedXlsx'])
        ->name('reports.mekari-converter.formatted-xlsx');

    Route::prefix('reports')->middleware(['web', 'auth'])->group(function () {
        Route::get('/invoice-generator', [MekariConverterController::class, 'showForm'])->name('reports.mekari-invoice-generator.index');
        Route::post('/invoice-generator', [MekariConverterController::class, 'generate'])->name('reports.mekari-invoice-generator.generate');

        Route::get('/purchase-report', [PurchaseReportController::class, 'index'])
            ->name('reports.purchase-report.index')
            ->middleware('can:reports.access');
    });

    Route::get('/test-pdf', function () {
        $pdf = \PDF::loadView('reports::mekari-invoice-generator.invoice-pdf', [
            'invoiceNo' => 'JL.2025.9999',
            'invoiceDate' => now()->format('d/m/Y'),
            'customer' => ['*DisplayName' => 'PT. TEST CUSTOMER', 'TaxNumber' => '00.000.000.0-000.000'],
            'items' => collect([
                ['Produk' => 'Laptop A', 'Kuantitas' => 2, 'Satuan' => 'PCS', 'Harga Satuan' => 5000000, 'Jumlah Tagihan' => 10000000],
                ['Produk' => 'Mouse B', 'Kuantitas' => 1, 'Satuan' => 'PCS', 'Harga Satuan' => 250000, 'Jumlah Tagihan' => 250000],
            ]),
            'taxes' => collect([
                ['Produk' => 'Pajak 11%', 'Jumlah Tagihan' => 1127500]
            ]),
            'total' => 11275000,
        ]);

        return $pdf->stream('test-invoice.pdf');
    });

});
