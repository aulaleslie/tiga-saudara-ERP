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
    Route::post('products/store-and-redirect', 'ProductController@storeProductAndRedirectToSerialNumberInput')->name('products.storeAndRedirect');
    Route::get('products/input-serial-number', 'ProductController@inputSerialNumber')->name('products.inputSerialNumber');
    Route::post('products/save-serial-numbers', 'ProductController@saveSerialNumbers')->name('products.saveSerialNumbers');
    //Product
    Route::resource('products', 'ProductController');
    //Product Category
    Route::resource('product-categories', 'CategoriesController')->except('create', 'show');
    Route::resource('brands', 'BrandController');
});

