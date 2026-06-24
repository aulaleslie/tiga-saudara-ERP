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

    //Expense Category
    Route::resource('expense-categories', 'ExpenseCategoriesController')->except('show', 'create');
    
    // Expense Import
    Route::get('expenses/imports', 'ExpenseUploadController@index')->name('expenses.imports.index');
    Route::get('expenses/import', 'ExpenseUploadController@uploadPage')->name('expenses.imports.uploadPage');
    Route::post('expenses/import', 'ExpenseUploadController@upload')->name('expenses.imports.upload');
    Route::get('expenses/imports/{batch}', 'ExpenseUploadController@show')->name('expenses.imports.show');
    
    //Expense
    Route::resource('expenses', 'ExpenseController');
    Route::post('expenses/{expense}/submit', 'ExpenseController@submit')->name('expenses.submit')->middleware('idempotency');
    Route::post('expenses/{expense}/approve', 'ExpenseController@approve')->name('expenses.approve')->middleware('idempotency');
    Route::post('expenses/{expense}/reject', 'ExpenseController@reject')->name('expenses.reject')->middleware('idempotency');
    Route::post('expenses/{expense}/archive', 'ExpenseController@archive')->name('expenses.archive');

});
