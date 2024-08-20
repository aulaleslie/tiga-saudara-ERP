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

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth', 'role.setting']], function () {
    //Product Adjustment
    Route::get('/adjustments/create-breakage', 'AdjustmentController@createBreakage')->name('adjustments.createBreakage');
    Route::post('/adjustments/store-breakage', 'AdjustmentController@storeBreakage')->name('adjustments.storeBreakage');
    Route::resource('adjustments', 'AdjustmentController');
});
