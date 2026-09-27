<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::group(['middleware' => ['auth', 'role.setting']], function () {

    //User Profile
    Route::get('/user/profile', 'ProfileController@edit')->name('profile.edit');
    Route::patch('/user/profile', 'ProfileController@update')->name('profile.update');
    Route::patch('/user/password', 'ProfileController@updatePassword')->name('profile.update.password');

    // Two-Factor Authentication
    Route::post('/user/profile/2fa/setup', 'TwoFactorController@setup')->name('2fa.setup');
    Route::post('/user/profile/2fa/confirm', 'TwoFactorController@confirm')->name('2fa.confirm');
    Route::post('/user/profile/2fa/test', 'TwoFactorController@test')->name('2fa.test');
    Route::delete('/user/profile/2fa/disable', 'TwoFactorController@disable')->name('2fa.disable');
    Route::post('/user/profile/2fa/admin-reset', 'TwoFactorController@adminReset')->name('2fa.admin-reset');

    //Akun
    Route::resource('users', 'UsersController')->except('show');

    //Peran
    Route::resource('roles', 'RolesController')->except('show');

});
