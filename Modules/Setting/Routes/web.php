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

use Modules\Setting\Http\Controllers\BusinessController;

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    //Mail Settings
    Route::patch('/settings/smtp', 'SettingController@updateSmtp')->name('settings.smtp.update');
    //General Settings
    Route::get('/settings', 'SettingController@index')->name('settings.index');
    Route::patch('/settings', 'SettingController@update')->name('settings.update');
    // Units
    Route::resource('units', 'UnitsController')->except('show');
    Route::resource('businesses', 'BusinessController');
    Route::post('/update-active-business', [BusinessController::class, 'updateActiveBusiness'])->name('update.active.business');
});
