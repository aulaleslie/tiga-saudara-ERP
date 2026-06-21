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
use Modules\Reports\Http\Controllers\InventoryValuationReportController;
use Modules\Reports\Http\Controllers\PurchaseReportController;
use Modules\Reports\Http\Controllers\PurchaseBySupplierReportController;
use Modules\Reports\Http\Controllers\SaleReportController;
use Modules\Reports\Http\Controllers\SaleByCustomerReportController;
use Modules\Reports\Http\Controllers\StockMutationReportController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Profit Loss Report
    Route::get('/profit-loss-report', 'ReportsController@profitLossReport')
        ->name('profit-loss-report.index');
    //Neraca Report
    Route::get('/operational-balance-sheet-report', 'ReportsController@operationalBalanceSheetReport')
        ->name('operational-balance-sheet-report.index');
    //Arus Kas Report
    Route::get('/operational-cash-flow-report', 'ReportsController@operationalCashFlowReport')
        ->name('operational-cash-flow-report.index');
    //Buku Besar Report
    Route::get('/operational-general-ledger-report', 'ReportsController@operationalGeneralLedgerReport')
        ->name('operational-general-ledger-report.index');
    //Neraca Saldo Report
    Route::get('/operational-trial-balance-report', 'ReportsController@operationalTrialBalanceReport')
        ->name('operational-trial-balance-report.index');
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
        Route::get('/', 'ReportsController@index')->name('reports.index');

        Route::get('/invoice-generator', [MekariConverterController::class, 'showForm'])->name('reports.mekari-invoice-generator.index');
        Route::post('/invoice-generator', [MekariConverterController::class, 'generate'])->name('reports.mekari-invoice-generator.generate');

        Route::get('/purchase-report', [PurchaseReportController::class, 'index'])
            ->name('reports.purchase-report.index')
            ->middleware('can:purchaseReports.access');

        Route::get('/purchase-report/global', [PurchaseReportController::class, 'indexGlobal'])
            ->name('reports.purchase-report.global')
            ->middleware('can:purchaseReports.global.access');

        Route::get('/purchase-by-supplier', [PurchaseBySupplierReportController::class, 'index'])
            ->name('reports.purchase-by-supplier.index')
            ->middleware('can:purchaseReports.access');

        Route::get('/sale-report', [SaleReportController::class, 'index'])
            ->name('reports.sale-report.index')
            ->middleware('can:saleReports.access');

        Route::get('/sale-by-customer', [SaleByCustomerReportController::class, 'index'])
            ->name('reports.sale-by-customer.index')
            ->middleware('can:saleReports.access');

        Route::get('/sale-report/global', [SaleReportController::class, 'indexGlobal'])
            ->name('reports.sale-report.global')
            ->middleware('can:saleReports.global.access');

        Route::get('/stock-mutation-report', [StockMutationReportController::class, 'index'])
            ->name('reports.stock-mutation-report.index')
            ->middleware('can:stockMutationReports.access');

        Route::get('/stock-mutation-report/global', [StockMutationReportController::class, 'indexGlobal'])
            ->name('reports.stock-mutation-report.global')
            ->middleware('can:stockMutationReports.global.access');

        Route::get('/inventory-valuation-report', [InventoryValuationReportController::class, 'index'])
            ->name('reports.inventory-valuation-report.index')
            ->middleware('can:inventoryValuationReports.access');
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
