<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Route;
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
    //Product
    Route::resource('products', 'ProductController');
    //Product Category
    Route::resource('product-categories', 'CategoriesController')->except('create', 'show');
    Route::resource('brands', 'BrandController');
});

