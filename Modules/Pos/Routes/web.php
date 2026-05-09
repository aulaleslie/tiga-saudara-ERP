<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosSessionController;
use Modules\Pos\Http\Controllers\PosSellController;
use Modules\Pos\Http\Controllers\PosSellSupervisorController;
use Modules\Pos\Http\Controllers\PosTerminalController;
use Modules\Pos\Http\Controllers\PosReportController;
use Modules\Pos\Http\Controllers\PosReconciliationController;
use Modules\Pos\Http\Controllers\PosCartApprovalController;
use Modules\Pos\Http\Controllers\PosSupervisorApprovalQueueController;
use Modules\Pos\Http\Controllers\PosTransactionController;
use Modules\Pos\Http\Controllers\PosReturnController;

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.returns.view']], function () {
    Route::get('/pos/returns', [PosReturnController::class, 'index'])->name('pos.returns.index');
    Route::get('/pos/returns/data', [PosReturnController::class, 'data'])->name('pos.returns.data');
    Route::get('/pos/returns/{return}', [PosReturnController::class, 'show'])->name('pos.returns.show')->whereNumber('return');

    Route::group(['middleware' => ['can:pos.returns.create']], function () {
        Route::get('/pos/returns/create', [PosReturnController::class, 'create'])->name('pos.returns.create');
        Route::get('/pos/returns/lookup', [PosReturnController::class, 'lookup'])->name('pos.returns.lookup');
        Route::post('/pos/returns', [PosReturnController::class, 'store'])->name('pos.returns.store');
    });

    Route::group(['middleware' => ['can:pos.returns.edit']], function () {
        Route::get('/pos/returns/{return}/edit', [PosReturnController::class, 'edit'])->name('pos.returns.edit')->whereNumber('return');
        Route::put('/pos/returns/{return}', [PosReturnController::class, 'update'])->name('pos.returns.update')->whereNumber('return');
    });

    Route::group(['middleware' => ['can:pos.returns.delete']], function () {
        Route::delete('/pos/returns/{return}', [PosReturnController::class, 'destroy'])->name('pos.returns.destroy')->whereNumber('return');
        Route::post('/pos/returns/{return}/archive', [PosReturnController::class, 'archive'])->name('pos.returns.archive')->whereNumber('return');
        Route::post('/pos/returns/{return}/cancel', [PosReturnController::class, 'cancel'])->name('pos.returns.cancel')->whereNumber('return');
    });

    Route::group(['middleware' => ['can:pos.returns.approve']], function () {
        Route::post('/pos/returns/{return}/submit-draft', [PosReturnController::class, 'submitDraft'])->name('pos.returns.submit-draft')->whereNumber('return');
        Route::post('/pos/returns/{return}/approve', [PosReturnController::class, 'approve'])->name('pos.returns.approve')->whereNumber('return');
        Route::post('/pos/returns/{return}/reject', [PosReturnController::class, 'reject'])->name('pos.returns.reject')->whereNumber('return');
    });

    Route::group(['middleware' => ['can:pos.returns.receive']], function () {
        Route::post('/pos/returns/{return}/receive', [PosReturnController::class, 'receive'])->name('pos.returns.receive')->whereNumber('return');
    });

    Route::group(['middleware' => ['can:pos.returns.settle']], function () {
        Route::post('/pos/returns/{return}/settle', [PosReturnController::class, 'settle'])->name('pos.returns.settle')->whereNumber('return');
    });

    Route::group(['middleware' => ['can:pos.returns.dispatch']], function () {
        Route::post('/pos/returns/{return}/dispatch', [PosReturnController::class, 'dispatch'])->name('pos.returns.dispatch')->whereNumber('return');
    });
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sessions.view']], function () {
    Route::get('/pos/sessions', [PosSessionController::class, 'index'])->name('pos.sessions.index');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sessions.open']], function () {
    Route::get('/pos/sessions/open', [PosSessionController::class, 'create'])->name('pos.sessions.create');
    Route::post('/pos/sessions/open', [PosSessionController::class, 'store'])->name('pos.sessions.store');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access']], function () {
    Route::get('/pos/sessions/{session}/summary', [PosSessionController::class, 'summary'])
        ->whereNumber('session')
        ->name('pos.sessions.summary');
    Route::get('/pos/sessions/{session}/checkouts/{checkout}', [PosSessionController::class, 'checkoutDetail'])->name('pos.sessions.checkout-detail');
});


Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.reports.access']], function () {
    Route::get('/pos/reports', [PosReportController::class, 'index'])->name('pos.reports.index');
    Route::get('/pos/reports/daily-sales', [PosReportController::class, 'dailySales'])->name('pos.reports.daily-sales');
    Route::get('/pos/reports/cashier-summary', [PosReportController::class, 'cashierSummary'])->name('pos.reports.cashier-summary');
    Route::get('/pos/reports/payment-methods', [PosReportController::class, 'paymentMethodSummary'])->name('pos.reports.payment-methods');
    Route::get('/pos/reports/item-sales', [PosReportController::class, 'itemSales'])->name('pos.reports.item-sales');
    Route::get('/pos/reports/supervisor-approvals', [PosReportController::class, 'supervisorApprovals'])->name('pos.reports.supervisor-approvals');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.reconciliation.access']], function () {
    Route::get('/pos/reconciliation', [PosReconciliationController::class, 'index'])->name('pos.reconciliation.index');
    Route::get('/pos/reconciliation/sessions', [PosReconciliationController::class, 'sessions'])->name('pos.reconciliation.sessions');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.safeDrops.create', 'log.pos.pickup']], function () {
    Route::post('/pos/sessions/{session}/safe-drops', [PosSessionController::class, 'safeDrop'])
        ->whereNumber('session')
        ->name('pos.sessions.safe-drops.store');
});

// Cash pickup route - doesn't require can:pos.safeDrops.create since the supervisor's credentials are validated in the controller
Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'log.pos.pickup']], function () {
    Route::post('/pos/sessions/{session}/pickup', [PosSessionController::class, 'pickup'])
        ->whereNumber('session')
        ->name('pos.sessions.pickup');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access']], function () {
    Route::post('/pos/sessions/{session}/close', [PosSessionController::class, 'close'])
        ->whereNumber('session')
        ->name('pos.sessions.close');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.sell', 'pos.session.active']], function () {
    Route::get('/pos/sell', [PosSellController::class, 'index'])->name('pos.sell');
    Route::get('/pos/sell/products/search', [PosSellController::class, 'search'])->name('pos.sell.products.search');
    Route::get('/pos/sell/products/{product}/bundles', [PosSellController::class, 'productBundles'])->name('pos.sell.products.bundles');
    Route::get('/pos/sell/customers/search', [PosSellController::class, 'customerSearch'])->name('pos.sell.customers.search');
    Route::get('/pos/sell/supervisors/search', [PosSellSupervisorController::class, 'search'])->name('pos.sell.supervisors.search');
    Route::post('/pos/sell/customers', [PosSellController::class, 'customerStore'])->name('pos.sell.customers.store');

    Route::post('/pos/sell/approval-requests', [PosCartApprovalController::class, 'store'])->name('pos.sell.approval-requests.store');
    Route::get('/pos/sell/approval-requests/{id}', [PosCartApprovalController::class, 'show'])->name('pos.sell.approval-requests.show');
    Route::post('/pos/sell/approval-requests/{id}/cancel', [PosCartApprovalController::class, 'cancel'])->name('pos.sell.approval-requests.cancel');

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

    Route::get('/pos/sell/payment-methods/search', [PosSellController::class, 'paymentMethodSearch'])->name('pos.sell.payment-methods.search');

    Route::post('/pos/sell/checkout/stage-payment', [PosSellController::class, 'stagePayment'])
        ->name('pos.sell.checkout.stage-payment');
    Route::get('/pos/sell/checkout/payment-chain', [PosSellController::class, 'getPaymentChain'])
        ->name('pos.sell.checkout.payment-chain');
    Route::delete('/pos/sell/checkout/payment-chain', [PosSellController::class, 'resetPaymentChain'])
        ->name('pos.sell.checkout.payment-chain.reset');

    Route::post('/pos/sell/checkout/preflight', [PosSellController::class, 'checkoutPreflight'])
        ->name('pos.sell.checkout.preflight');

    Route::post('/pos/sell/checkout/finalize', [PosSellController::class, 'checkoutFinalize'])
        ->name('pos.sell.checkout.finalize');

    Route::get('/pos/sell/checkout/{checkout}/receipt', [PosSellController::class, 'receiptView'])
        ->name('pos.sell.checkout.receipt');
    Route::group(['middleware' => ['can:pos.receipts.reprint']], function () {
        Route::get('/pos/sell/checkout/{checkout}/receipt/reprint', [PosSellController::class, 'receiptReprint'])
            ->name('pos.sell.checkout.receipt.reprint');
    });

    Route::get('/pos/sell/serials/search', [PosSellController::class, 'serialSearch'])->name('pos.sell.serials.search');
    Route::post('/pos/sell/cart/lines/{lineId}/serials', [PosSellController::class, 'cartAssignSerials'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.serials.store');
    Route::put('/pos/sell/cart/lines/{lineId}/serials', [PosSellController::class, 'cartAssignSerials'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.serials.update');
    Route::post('/pos/sell/cart/lines/{lineId}/serials/append', [PosSellController::class, 'cartAppendSerial'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.serials.append');
    Route::delete('/pos/sell/cart/lines/{lineId}/serials/{serial}', [PosSellController::class, 'cartRemoveSerial'])
        ->whereNumber('lineId')
        ->name('pos.sell.cart.lines.serials.remove');
    Route::get('/pos/sell/search/resolve', [PosSellController::class, 'scanResolve'])->name('pos.sell.search.resolve');

    Route::post('/pos/sell/transactions/save-and-new', [PosTransactionController::class, 'saveAndNew'])
        ->middleware('pos.transactions.enabled')
        ->name('pos.sell.transactions.save-and-new');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.terminals.access']], function () {
    Route::get('/pos/terminals', [PosTerminalController::class, 'index'])->name('pos.terminals.index');

    Route::group(['middleware' => ['can:pos.terminals.edit']], function () {
        Route::get('/pos/terminals/create', [PosTerminalController::class, 'create'])->name('pos.terminals.create');
        Route::post('/pos/terminals', [PosTerminalController::class, 'store'])->name('pos.terminals.store');
        Route::get('/pos/terminals/{terminal}/edit', [PosTerminalController::class, 'edit'])->name('pos.terminals.edit');
        Route::put('/pos/terminals/{terminal}', [PosTerminalController::class, 'update'])->name('pos.terminals.update');
        Route::delete('/pos/terminals/{terminal}', [PosTerminalController::class, 'destroy'])->name('pos.terminals.destroy');
    });
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'pos.transactions.enabled', 'can:pos.access', 'can:pos.transactions.view']], function () {
    Route::get('/pos/transactions', [PosTransactionController::class, 'index'])->name('pos.transactions.index');
    Route::get('/pos/transactions/data', [PosTransactionController::class, 'data'])->name('pos.transactions.data');
    Route::get('/pos/transactions/{transaction}', [PosTransactionController::class, 'show'])->name('pos.transactions.show');
    Route::get('/pos/transactions/{transaction}/receipt', [PosTransactionController::class, 'receipt'])->name('pos.transactions.receipt');
    Route::post('/pos/transactions/{transaction}/cancel', [PosTransactionController::class, 'cancel'])->name('pos.transactions.cancel');

    Route::group(['middleware' => ['can:pos.receipts.reprint']], function () {
        Route::match(['get', 'post'], '/pos/transactions/{transaction}/receipt/reprint', [PosTransactionController::class, 'receiptReprint'])->name('pos.transactions.receipt.reprint');
    });
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'pos.transactions.enabled', 'can:pos.access', 'can:pos.sell', 'can:pos.transactions.load', 'pos.session.active']], function () {
    Route::post('/pos/transactions/{transaction}/load', [PosTransactionController::class, 'load'])->name('pos.transactions.load');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access', 'can:pos.supervisor.approval']], function () {
    Route::get('/pos/supervisor/approval-requests', [PosSupervisorApprovalQueueController::class, 'index'])->name('pos.supervisor.approval-requests.index');
    Route::get('/pos/supervisor/approval-requests/data', [PosSupervisorApprovalQueueController::class, 'data'])->name('pos.supervisor.approval-requests.data');
    Route::post('/pos/supervisor/approval-requests/{id}/approve', [PosSupervisorApprovalQueueController::class, 'approve'])->name('pos.supervisor.approval-requests.approve');
    Route::post('/pos/supervisor/approval-requests/{id}/reject', [PosSupervisorApprovalQueueController::class, 'reject'])->name('pos.supervisor.approval-requests.reject');
});

Route::group(['middleware' => ['auth', 'role.setting', 'pos.enabled', 'can:pos.access']], function () {
    Route::post('/pos/sessions/{session}/finalize', [PosSessionController::class, 'finalize'])
        ->whereNumber('session')
        ->name('pos.sessions.finalize');
});
