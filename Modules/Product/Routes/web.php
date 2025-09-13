<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductBundleController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ProductUploadController;   // ⟵ NEW
use Modules\Product\Http\Controllers\ProductImportController;   // ⟵ NEW

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    Route::get('/products/print-barcode', 'BarcodeController@printBarcode')->name('barcode.print');

    // ⟵ keep these URLs & names exactly, just point to the new controller
    Route::get('/products/upload',  [ProductUploadController::class, 'uploadPage'])->name('products.upload.page');
    Route::post('/products/upload', [ProductUploadController::class, 'upload'])->name('products.upload');

    Route::get('/products/upload/template', [ProductController::class, 'downloadCsvTemplate'])
        ->name('products.upload.template');

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

    Route::resource('products', 'ProductController');

    Route::prefix('products/{product}/bundles')->group(function () {
        Route::get('/', [ProductBundleController::class, 'index'])->name('products.bundle.index');
        Route::get('/create', [ProductBundleController::class, 'create'])->name('products.bundle.create');
        Route::post('/', [ProductBundleController::class, 'store'])->name('products.bundle.store');
        Route::get('/{bundle}/edit', [ProductBundleController::class, 'edit'])->name('products.bundle.edit');
        Route::put('/{bundle}', [ProductBundleController::class, 'update'])->name('products.bundle.update');
        Route::delete('/{bundle}', [ProductBundleController::class, 'destroy'])->name('products.bundle.destroy');
    });

    Route::resource('product-categories', 'CategoriesController')->except('create', 'show');
    Route::resource('brands', 'BrandController');
});
