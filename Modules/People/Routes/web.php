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

    //Customers
    Route::patch('customers/{customer}/toggle-status', 'CustomersController@toggleStatus')->name('customers.toggle-status');
    Route::resource('customers', 'CustomersController');
    //Suppliers
    Route::patch('suppliers/{supplier}/toggle-status', 'SuppliersController@toggleStatus')->name('suppliers.toggle-status');
    Route::resource('suppliers', 'SuppliersController');

});
