<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Print Barcode
    Route::get('/products/print-barcode', 'BarcodeController@printBarcode')->name('barcode.print');
    Route::get('/products/upload', 'ProductController@uploadPage')->name('products.upload.page');
    Route::post('/products/upload', 'ProductController@upload')->name('products.upload');
    //Product
    Route::resource('products', 'ProductController');
    //Product Category
    Route::resource('product-categories', 'CategoriesController')->except('create', 'show');
    Route::resource('brands', 'BrandController');
});

