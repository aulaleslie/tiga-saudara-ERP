<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosSessionController;
use Modules\Pos\Http\Controllers\PosSellController;
use Modules\Pos\Http\Controllers\PosTerminalController;

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sessions.open']], function () {
    Route::get('/pos/sessions/open', [PosSessionController::class, 'create'])->name('pos.sessions.create');
    Route::post('/pos/sessions/open', [PosSessionController::class, 'store'])->name('pos.sessions.store');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access']], function () {
    Route::get('/pos/sessions/{session}/summary', [PosSessionController::class, 'summary'])->name('pos.sessions.summary');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.safeDrops.create']], function () {
    Route::post('/pos/sessions/{session}/safe-drops', [PosSessionController::class, 'safeDrop'])->name('pos.sessions.safe-drops.store');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sessions.close']], function () {
    Route::post('/pos/sessions/{session}/close', [PosSessionController::class, 'closeFinalize'])->name('pos.sessions.close.finalize');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sell', 'pos.session.active']], function () {
    Route::get('/pos/sell', [PosSellController::class, 'index'])->name('pos.sell');
});

Route::group(['middleware' => ['auth', 'role.setting', 'can:pos.terminals.access']], function () {
    Route::get('/pos/terminals', [PosTerminalController::class, 'index'])->name('pos.terminals.index');

    Route::group(['middleware' => ['can:pos.terminals.edit']], function () {
        Route::get('/pos/terminals/create', [PosTerminalController::class, 'create'])->name('pos.terminals.create');
        Route::post('/pos/terminals', [PosTerminalController::class, 'store'])->name('pos.terminals.store');
        Route::get('/pos/terminals/{terminal}/edit', [PosTerminalController::class, 'edit'])->name('pos.terminals.edit');
        Route::put('/pos/terminals/{terminal}', [PosTerminalController::class, 'update'])->name('pos.terminals.update');
        Route::delete('/pos/terminals/{terminal}', [PosTerminalController::class, 'destroy'])->name('pos.terminals.destroy');
    });
});
