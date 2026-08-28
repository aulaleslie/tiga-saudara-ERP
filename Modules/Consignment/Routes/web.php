<?php

use Illuminate\Support\Facades\Route;
use Modules\Consignment\Http\Controllers\ConsignmentReceivalController;
use Modules\Consignment\Http\Controllers\ConsignmentReceivingController;
use Modules\Consignment\Http\Controllers\ConsignmentReconciliationController;

Route::group(['middleware' => ['auth', 'role.setting'], 'prefix' => 'consignments', 'as' => 'consignments.'], function () {

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
