<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosSessionController;
use Modules\Pos\Http\Controllers\PosSellController;
use Modules\Pos\Http\Controllers\PosTerminalController;
use Modules\Pos\Http\Controllers\PosReportController;

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sessions.open']], function () {
    Route::get('/pos/sessions/open', [PosSessionController::class, 'create'])->name('pos.sessions.create');
    Route::post('/pos/sessions/open', [PosSessionController::class, 'store'])->name('pos.sessions.store');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access']], function () {
    Route::get('/pos/sessions/{session}/summary', [PosSessionController::class, 'summary'])->name('pos.sessions.summary');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.monitor.access']], function () {
    Route::get('/pos/monitor', [PosSessionController::class, 'monitor'])->name('pos.monitor.index');
    Route::get('/pos/monitor/sessions', [PosSessionController::class, 'monitorApi'])->name('pos.monitor.sessions');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.reports.access']], function () {
    Route::get('/pos/reports', [PosReportController::class, 'index'])->name('pos.reports.index');
    Route::get('/pos/reports/daily-sales', [PosReportController::class, 'dailySales'])->name('pos.reports.daily-sales');
    Route::get('/pos/reports/cashier-summary', [PosReportController::class, 'cashierSummary'])->name('pos.reports.cashier-summary');
    Route::get('/pos/reports/payment-methods', [PosReportController::class, 'paymentMethodSummary'])->name('pos.reports.payment-methods');
    Route::get('/pos/reports/item-sales', [PosReportController::class, 'itemSales'])->name('pos.reports.item-sales');
    Route::get('/pos/reports/supervisor-approvals', [PosReportController::class, 'supervisorApprovals'])->name('pos.reports.supervisor-approvals');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.safeDrops.create']], function () {
    Route::post('/pos/sessions/{session}/safe-drops', [PosSessionController::class, 'safeDrop'])->name('pos.sessions.safe-drops.store');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sessions.close']], function () {
    Route::post('/pos/sessions/{session}/close', [PosSessionController::class, 'closeFinalize'])->name('pos.sessions.close.finalize');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sell', 'pos.session.active']], function () {
    Route::get('/pos/sell', [PosSellController::class, 'index'])->name('pos.sell');
    Route::get('/pos/sell/products/search', [PosSellController::class, 'search'])->name('pos.sell.products.search');
    Route::get('/pos/sell/customers/search', [PosSellController::class, 'customerSearch'])->name('pos.sell.customers.search');

    Route::get('/pos/sell/cart', [PosSellController::class, 'cartShow'])->name('pos.sell.cart.show');
    Route::post('/pos/sell/cart/lines', [PosSellController::class, 'cartStoreLine'])->name('pos.sell.cart.lines.store');
    Route::patch('/pos/sell/cart/lines/{lineId}', [PosSellController::class, 'cartUpdateLine'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.update');
    Route::delete('/pos/sell/cart/lines/{lineId}', [PosSellController::class, 'cartDestroyLine'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.destroy');
    Route::patch('/pos/sell/cart/discount', [PosSellController::class, 'cartUpdateDiscount'])->name('pos.sell.cart.discount.update');
    Route::post('/pos/sell/cart/lines/{lineId}/price-override', [PosSellController::class, 'cartOverridePrice'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.price-override');
    Route::delete('/pos/sell/cart', [PosSellController::class, 'cartClear'])->name('pos.sell.cart.clear');
    Route::patch('/pos/sell/cart/customer', [PosSellController::class, 'cartUpdateCustomer'])->name('pos.sell.cart.customer.update');
    Route::post('/pos/sell/checkout/finalize', [PosSellController::class, 'checkoutFinalize'])
        ->name('pos.sell.checkout.finalize');

    Route::get('/pos/sell/checkout/{checkout}/receipt', [PosSellController::class, 'receiptView'])
        ->name('pos.sell.checkout.receipt');
    Route::get('/pos/sell/checkout/{checkout}/receipt/reprint', [PosSellController::class, 'receiptReprint'])
        ->name('pos.sell.checkout.receipt.reprint');

    Route::get('/pos/sell/serials/search', [PosSellController::class, 'serialSearch'])->name('pos.sell.serials.search');
    Route::post('/pos/sell/cart/lines/{lineId}/serials', [PosSellController::class, 'cartAssignSerials'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.serials.store');
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
