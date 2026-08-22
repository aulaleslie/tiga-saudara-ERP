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
});
