<?php

use Illuminate\Support\Facades\Route;
use Modules\Consignment\Http\Controllers\ConsignmentReceivalController;
use Modules\Consignment\Http\Controllers\ConsignmentReceivingController;
use Modules\Consignment\Http\Controllers\ConsignmentReconciliationController;
use Modules\Consignment\Http\Controllers\ConsignmentSelectSearchController;
use Modules\Consignment\Http\Controllers\ConsignmentTransferLotController;

Route::group(['middleware' => ['auth', 'role.setting'], 'prefix' => 'consignments', 'as' => 'consignments.'], function () {

    // Shared AJAX Select2 endpoints for filter dropdowns
    Route::get('select/suppliers', [ConsignmentSelectSearchController::class, 'suppliers'])->name('select.suppliers');
    Route::get('select/products', [ConsignmentSelectSearchController::class, 'products'])->name('select.products');
    Route::get('select/locations', [ConsignmentSelectSearchController::class, 'locations'])->name('select.locations');
    Route::get('select/sold-sources', [ConsignmentSelectSearchController::class, 'soldSources'])->name('select.sold-sources');

    // Receipt-lot confirmation for consignment stock transfers (V3 approval workspace)
    Route::post('transfers/{transfer}/lots', [ConsignmentTransferLotController::class, 'confirm'])->name('transfer-lots.confirm');

    // Consignment Receivals
    Route::get('receival-suppliers/search', [ConsignmentReceivalController::class, 'searchSuppliers'])->name('receival-suppliers.search');
    Route::get('receival-products/search', [ConsignmentReceivalController::class, 'searchProducts'])->name('receival-products.search');
    Route::get('receivals', [ConsignmentReceivalController::class, 'index'])->name('receivals.index');
    Route::get('receivals/create', [ConsignmentReceivalController::class, 'create'])->name('receivals.create');
    Route::post('receivals', [ConsignmentReceivalController::class, 'store'])->name('receivals.store');
    Route::get('receivals/{id}', [ConsignmentReceivalController::class, 'show'])->name('receivals.show');
    Route::get('receivals/{id}/edit', [ConsignmentReceivalController::class, 'edit'])->name('receivals.edit');
    Route::put('receivals/{id}', [ConsignmentReceivalController::class, 'update'])->name('receivals.update');
    Route::delete('receivals/{id}', [ConsignmentReceivalController::class, 'destroy'])->name('receivals.destroy');

    Route::post('receivals/{id}/submit', [ConsignmentReceivalController::class, 'submit'])->name('receivals.submit');
    Route::post('receivals/{id}/approve', [ConsignmentReceivalController::class, 'approve'])->name('receivals.approve');
    Route::post('receivals/{id}/reject', [ConsignmentReceivalController::class, 'reject'])->name('receivals.reject');

    // Consignment Receivings
    Route::get('receivings', [ConsignmentReceivingController::class, 'index'])->name('receivings.index');
    Route::get('receivals/{receival_id}/receive', [ConsignmentReceivingController::class, 'create'])->name('receivings.create');
    Route::post('receivals/{receival_id}/receive', [ConsignmentReceivingController::class, 'store'])->name('receivings.store');
    Route::get('receivings/{id}', [ConsignmentReceivingController::class, 'show'])->name('receivings.show');
    Route::post('receivings/{id}/approve', [ConsignmentReceivingController::class, 'approve'])->name('receivings.approve');
    Route::post('receivings/{id}/reject', [ConsignmentReceivingController::class, 'reject'])->name('receivings.reject');
    Route::post('receivings/{id}/reverse', [ConsignmentReceivingController::class, 'reverse'])->name('receivings.reverse');

    // Reconciliation
    Route::get('reconciliation', [ConsignmentReconciliationController::class, 'index'])->name('reconciliation.index');

    // Sold Sources
    Route::get('sold-sources', [\Modules\Consignment\Http\Controllers\ConsignmentSoldSourceController::class, 'index'])->name('sold-sources.index');
    Route::post('sold-sources/discover', [\Modules\Consignment\Http\Controllers\ConsignmentSoldSourceController::class, 'discover'])->name('sold-sources.discover');

    // Buyouts: read screens always; every mutation route is a hidden 404 unless consignment.buyouts.ui_enabled.
    $buyouts = \Modules\Consignment\Http\Controllers\ConsignmentBuyoutController::class;
    Route::get('buyouts', [$buyouts, 'index'])->name('buyouts.index');
    Route::get('buyouts/create', [$buyouts, 'create'])->name('buyouts.create');
    Route::get('buyouts/{id}', [$buyouts, 'show'])->name('buyouts.show')->whereNumber('id');
    Route::get('buyouts/{id}/edit', [$buyouts, 'edit'])->name('buyouts.edit')->whereNumber('id');
    Route::post('buyouts/preview', [$buyouts, 'preview'])->name('buyouts.preview');
    Route::post('buyouts', [$buyouts, 'store'])->name('buyouts.store');
    Route::put('buyouts/{id}', [$buyouts, 'update'])->name('buyouts.update')->whereNumber('id');
    Route::post('buyouts/{id}/submit', [$buyouts, 'submit'])->name('buyouts.submit')->whereNumber('id');
    Route::post('buyouts/{id}/approve', [$buyouts, 'approve'])->name('buyouts.approve')->whereNumber('id');
    Route::post('buyouts/{id}/reject', [$buyouts, 'reject'])->name('buyouts.reject')->whereNumber('id');
    Route::post('buyouts/{id}/cancel', [$buyouts, 'cancel'])->name('buyouts.cancel')->whereNumber('id');
    Route::post('buyouts/{id}/finalize', [$buyouts, 'finalize'])->name('buyouts.finalize')->whereNumber('id');
    Route::post('buyouts/{id}/reverse', [$buyouts, 'reverse'])->name('buyouts.reverse')->whereNumber('id');
    Route::post('buyouts/sold-sources/{sourceId}/attribution', [$buyouts, 'attribute'])->name('buyouts.attribute')->whereNumber('sourceId');
    Route::post('buyouts/returns/{detailId}/declare', [$buyouts, 'declareReturn'])->name('buyouts.returns.declare')->whereNumber('detailId');

    // Confirmations
    Route::get('confirmations', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'index'])->name('confirmations.index');
    Route::get('confirmations/create', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'create'])->name('confirmations.create');
    Route::post('confirmations', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'store'])->name('confirmations.store');
    Route::get('confirmations/{id}', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'show'])->name('confirmations.show');
    Route::get('confirmations/{id}/edit', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'edit'])->name('confirmations.edit');
    Route::put('confirmations/{id}', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'update'])->name('confirmations.update');
    Route::delete('confirmations/{id}', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'destroy'])->name('confirmations.destroy');

    Route::post('confirmations/{id}/submit', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'submit'])->name('confirmations.submit');
    Route::post('confirmations/{id}/approve', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'approve'])->name('confirmations.approve');
    Route::post('confirmations/{id}/reject', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConfirmationController::class, 'reject'])->name('confirmations.reject');

    // Consignment Billing Conversion
    Route::get('billing', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConversionController::class, 'index'])->name('billing.index');
    Route::get('confirmations/{id}/billing-convert', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConversionController::class, 'create'])->name('billing.create');
    Route::post('confirmations/{id}/billing-preview', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConversionController::class, 'preview'])->name('billing.preview');
    Route::post('confirmations/{id}/billing-convert', [\Modules\Consignment\Http\Controllers\ConsignmentBillingConversionController::class, 'convert'])->name('billing.convert');
});
