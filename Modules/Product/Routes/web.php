<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductBundleController;
use Modules\Product\Http\Controllers\ProductController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Print Barcode
    Route::get('/products/print-barcode', 'BarcodeController@printBarcode')->name('barcode.print');
    Route::get('/products/upload', 'ProductController@uploadPage')->name('products.upload.page');
    Route::post('/products/upload', 'ProductController@upload')->name('products.upload');
    Route::post('/products/store-and-redirect', [ProductController::class, 'storeProductAndRedirectToInitializeProductStock'])->name('products.storeProductAndRedirectToInitializeProductStock');
    Route::get('/products/{product_id}/initialize-stock', [ProductController::class, 'initializeProductStock'])->name('products.initializeProductStock');
    Route::post('/products/{product_id}/initialize-stock', [ProductController::class, 'storeInitialProductStock'])->name('products.storeInitialProductStock');
    Route::post('/products/{product_id}/initialize-stock-and-redirect', [ProductController::class, 'storeInitialProductStockAndRedirectToInputSerialNumbers'])->name('products.storeInitialProductStockAndRedirectToInputSerialNumbers');
    Route::get('/products/{product_id}/input-serial-numbers/{location_id}', [ProductController::class, 'inputSerialNumbers'])->name('products.inputSerialNumbers');
    Route::post('/products/{product_id}/input-serial-numbers/{location_id}', [ProductController::class, 'storeSerialNumbers'])->name('products.storeSerialNumbers');
    Route::get('/products/search', [ProductController::class, 'search'])
        ->name('products.search');
    Route::delete('/products/{product}/media/{media}', [ProductController::class, 'destroyMedia'])
        ->name('products.media.destroy');
    //Product
    Route::resource('products', 'ProductController');

    Route::prefix('products/{product}/bundles')->group(function () {
        // List bundles for a given product
        Route::get('/', [ProductBundleController::class, 'index'])
            ->name('products.bundle.index');

        // Show form to create a new bundle for a given product
        Route::get('/create', [ProductBundleController::class, 'create'])
            ->name('products.bundle.create');

        // Store a newly created bundle for a given product
        Route::post('/', [ProductBundleController::class, 'store'])
            ->name('products.bundle.store');

        // Show form to edit a specific bundle (bundle ID)
        Route::get('/{bundle}/edit', [ProductBundleController::class, 'edit'])
            ->name('products.bundle.edit');

        // Update the specified bundle
        Route::put('/{bundle}', [ProductBundleController::class, 'update'])
            ->name('products.bundle.update');

        // Delete the specified bundle
        Route::delete('/{bundle}', [ProductBundleController::class, 'destroy'])
            ->name('products.bundle.destroy');
    });
    //Product Category
    Route::resource('product-categories', 'CategoriesController')->except('create', 'show');
    Route::resource('brands', 'BrandController');
});

