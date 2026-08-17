<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductBundleController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ProductUploadController;   // ⟵ NEW
use Modules\Product\Http\Controllers\ProductImportController;   // ⟵ NEW
use Modules\Product\Http\Controllers\SerialNumberController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    // Serial number validation (AJAX)
    Route::post('/serial-numbers/validate', [SerialNumberController::class, 'validateSerial'])
        ->name('serial-numbers.validate');
    Route::post('/serial-numbers/validate-dispatch', [SerialNumberController::class, 'validateDispatchSerial'])
        ->name('serial-numbers.validate-dispatch');
    Route::post('/serial-numbers/validate-purchase-return', [SerialNumberController::class, 'validatePurchaseReturnSerial'])
        ->name('serial-numbers.validate-purchase-return');
    Route::put('/serial-numbers/{serialNumber}', [SerialNumberController::class, 'updateSerial'])
        ->name('serial-numbers.update');
    Route::get('/products/print-barcode', 'BarcodeController@printBarcode')->name('barcode.print');
    Route::post('/products/print-barcode/batch', 'BarcodeController@batchPrint')
        ->middleware('can:barcodes.print')
        ->name('barcode.batch-print');
    // Non-production diagnostic sheet for physical printer acceptance testing.
    Route::get('/products/print-barcode/diagnostic', 'BarcodeController@diagnosticPrint')
        ->middleware('can:barcodes.print')
        ->name('barcode.diagnostic-print');

    // ⟵ keep these URLs & names exactly, just point to the new controller
    Route::group(['middleware' => ['can:products.manage_cross_business_prices']], function () {
        Route::get('/products/{product}/cross-business-prices', [\Modules\Product\Http\Controllers\CrossBusinessPriceController::class, 'edit'])->name('products.cross-business-prices.edit');
        Route::put('/products/{product}/cross-business-prices', [\Modules\Product\Http\Controllers\CrossBusinessPriceController::class, 'update'])->name('products.cross-business-prices.update');
    });

    Route::get('/products/upload',  [ProductUploadController::class, 'uploadPage'])->name('products.upload.page');
    Route::post('/products/upload', [ProductUploadController::class, 'upload'])->name('products.upload');

    Route::get('/products/upload/template', [ProductController::class, 'downloadCsvTemplate'])
        ->name('products.upload.template');

    // Stock snapshot import (RETIRED: use sales-price-snapshot for combined price+stock)
    // Route::get('/products/stock-snapshot/upload', [ProductUploadController::class, 'stockSnapshotUploadPage'])->name('products.stock-snapshot.upload.page');
    // Route::post('/products/stock-snapshot/upload', [ProductUploadController::class, 'stockSnapshotUpload'])->name('products.stock-snapshot.upload');
    // Route::get('/products/stock-snapshot/template', [ProductUploadController::class, 'downloadStockSnapshotTemplate'])->name('products.stock-snapshot.template');

    // Sales HPP snapshot import
    Route::get('/products/sales-hpp-snapshot/upload', [ProductUploadController::class, 'salesHppSnapshotUploadPage'])->name('products.sales-hpp-snapshot.upload.page');
    Route::post('/products/sales-hpp-snapshot/upload', [ProductUploadController::class, 'salesHppSnapshotUpload'])->name('products.sales-hpp-snapshot.upload');
    Route::get('/products/sales-hpp-snapshot/template', [ProductUploadController::class, 'downloadSalesHppSnapshotTemplate'])->name('products.sales-hpp-snapshot.template');

    // Sales price snapshot import
    Route::get('/products/sales-price-snapshot/upload', [ProductUploadController::class, 'salesPriceSnapshotUploadPage'])->name('products.sales-price-snapshot.upload.page');
    Route::post('/products/sales-price-snapshot/upload', [ProductUploadController::class, 'salesPriceSnapshotUpload'])->name('products.sales-price-snapshot.upload');

    // Dual-company tier price import (round-trip of product:export-tiga-nusa-prices workbook)
    Route::get('/products/dual-company-tier-price/upload', [ProductUploadController::class, 'dualCompanyTierPriceUploadPage'])->name('products.dual-company-tier-price.upload.page');
    Route::post('/products/dual-company-tier-price/upload', [ProductUploadController::class, 'dualCompanyTierPriceUpload'])->name('products.dual-company-tier-price.upload');

    // Monitor pages (new)
    Route::get('/products/imports',                [ProductImportController::class, 'index'])->name('products.imports.index');
    Route::get('/products/imports/{batch}',        [ProductImportController::class, 'show'])->name('products.imports.show');
    Route::post('/products/imports/{batch}/undo',  [ProductImportController::class, 'undo'])->name('products.imports.undo');

    // Your existing product routes stay the same
    Route::post('/products/store-and-redirect', [ProductController::class, 'storeProductAndRedirectToInitializeProductStock'])->name('products.storeProductAndRedirectToInitializeProductStock');
    Route::get('/products/{product_id}/initialize-stock', [ProductController::class, 'initializeProductStock'])->name('products.initializeProductStock');
    Route::post('/products/{product_id}/initialize-stock', [ProductController::class, 'storeInitialProductStock'])->name('products.storeInitialProductStock');
    Route::post('/products/{product_id}/initialize-stock-and-redirect', [ProductController::class, 'storeInitialProductStockAndRedirectToInputSerialNumbers'])->name('products.storeInitialProductStockAndRedirectToInputSerialNumbers');
    Route::get('/products/{product_id}/input-serial-numbers/{location_id}', [ProductController::class, 'inputSerialNumbers'])->name('products.inputSerialNumbers');
    Route::post('/products/{product_id}/input-serial-numbers/{location_id}', [ProductController::class, 'storeSerialNumbers'])->name('products.storeSerialNumbers');
    Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
    Route::delete('/products/{product}/media/{media}', [ProductController::class, 'destroyMedia'])->name('products.media.destroy');

    // UOM Normalization
    Route::middleware('can:uomNormalize,product')
        ->group(function () {
            Route::get('/products/{product}/uom-normalize', [\Modules\Product\Http\Controllers\UomNormalizationController::class, 'edit'])->name('products.uom-normalize.edit');
            Route::post('/products/{product}/uom-normalize/preview', [\Modules\Product\Http\Controllers\UomNormalizationController::class, 'preview'])->name('products.uom-normalize.preview');
            Route::post('/products/{product}/uom-normalize', [\Modules\Product\Http\Controllers\UomNormalizationController::class, 'store'])->name('products.uom-normalize.store');
            Route::get('/products/{product}/uom-normalize/units/search', [\Modules\Product\Http\Controllers\UomNormalizationController::class, 'searchUnits'])->name('products.uom-normalize.units.search');
            Route::get('/products/{product}/uom-normalize/candidate-lines', [\Modules\Product\Http\Controllers\UomNormalizationController::class, 'candidateLines'])->name('products.uom-normalize.candidate-lines');
        });

    Route::resource('products', 'ProductController')->middleware('idempotency');

    Route::prefix('products/{product}/bundles')->group(function () {
        Route::get('/', [ProductBundleController::class, 'index'])->name('products.bundle.index');
        Route::get('/create', [ProductBundleController::class, 'create'])->name('products.bundle.create');
        Route::post('/', [ProductBundleController::class, 'store'])->name('products.bundle.store');
        Route::get('/{bundle}/edit', [ProductBundleController::class, 'edit'])->name('products.bundle.edit');
        Route::put('/{bundle}', [ProductBundleController::class, 'update'])->name('products.bundle.update');
        Route::delete('/{bundle}', [ProductBundleController::class, 'destroy'])->name('products.bundle.destroy');
    });

    Route::group(['middleware' => ['can:products.barcodes.manage']], function () {
        Route::get('/products/barcodes/manage', [\Modules\Product\Http\Controllers\ProductBarcodeInitializationController::class, 'index'])->name('products.barcodes.index');
    });

    Route::resource('product-categories', 'CategoriesController')->except('create', 'show');
    Route::resource('brands', 'BrandController');
});
