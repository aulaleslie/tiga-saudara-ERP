<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosSellController;
use Modules\Pos\Http\Controllers\PosTerminalController;

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sell']], function () {
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
