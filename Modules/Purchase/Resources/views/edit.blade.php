@php use Modules\People\Entities\Supplier;use Modules\Purchase\Entities\PaymentTerm; @endphp
@extends('layouts.app')

@section('title', 'Edit Purchase')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Search Product Livewire Component -->
        <div class="row">
            <div class="col-12">
                <livewire:purchase.search-product/>
            </div>
        </div>

        <!-- Purchase Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <!-- Alert Messages -->
                        @include('utils.alerts')

                        <!-- Purchase Form Start -->
                        <form id="purchase-form" action="{{ route('purchases.update', $purchase->id) }}" method="POST">
                            @csrf
                            @method('patch')

                            <!-- Reference, Supplier, Date -->
                            <div class="form-row">
                                <!-- Referensi -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="reference">Referensi <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" id="reference" required
                                               readonly value="{{ $purchase->reference }}">
                                        @error('reference')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <!-- Pemasok -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Pemasok <span class="text-danger">*</span></label>
                                        <select id="supplier_id" class="form-control @error('supplier_id') is-invalid @enderror" name="supplier_id" required>
                                            <option value="">Pilih Pemasok</option>
                                            @foreach($suppliers as $supplier)
                                                <option value="{{ $supplier->id }}" data-payment-term="{{ $supplier->payment_term_id }}" {{ $supplier->id == $purchase->supplier_id ? 'selected' : '' }}>
                                                    {{ $supplier->supplier_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('supplier_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <!-- Tanggal -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="date">Tanggal <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control @error('date') is-invalid @enderror"
                                               name="date" id="date" required value="{{ $purchase->date }}">
                                        @error('date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="due_date">Tanggal Jatuh Tempo <span
                                                class="text-danger">*</span></label>
                                        <input type="date" class="form-control @error('due_date') is-invalid @enderror"
                                               name="due_date" id="due_date" required value="{{ $purchase->due_date }}">
                                        @error('due_date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <!-- Term Pembayaran -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="payment_term">Term Pembayaran <span
                                                class="text-danger">*</span></label>
                                        <select id="payment_term" class="form-control @error('payment_term') is-invalid @enderror" name="payment_term" required>
                                            <option value="">Pilih Term Pembayaran</option>
                                            @foreach($paymentTerms as $term)
                                                <option value="{{ $term->id }}" data-longevity="{{ $term->longevity }}" {{ $term->id == $purchase->payment_term_id ? 'selected' : '' }}>
                                                    {{ $term->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('payment_term')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <!-- Product Cart Livewire Component -->
                            <livewire:purchase.product-cart :cartInstance="'purchase'" :data="$purchase"/>

                            <!-- Catatan -->
                            <div class="form-group mt-4">
                                <label for="note">Catatan (Jika Diperlukan)</label>
                                <textarea name="note" id="note" rows="5"
                                          class="form-control @error('note') is-invalid @enderror">{{ $purchase->note }}</textarea>
                                @error('note')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Submit Button -->
                            <div class="mt-3">
                                @canany('purchase.edit')
                                <button type="submit" class="btn btn-primary">
                                    Update Pembelian <i class="bi bi-check"></i>
                                </button>
                                @endcanany
                                    <a href="{{ route('purchases.index') }}" class="btn btn-secondary">Kembali</a>
                            </div>
                        </form>
                        <!-- Purchase Form End -->
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const paymentTermSelect = document.getElementById('payment_term');
            const dueDateInput = document.getElementById('due_date');
            const supplierDropdown = document.getElementById('supplier_id');
            const paymentTermDropdown = document.getElementById('payment_term');

            // Update due date based on selected payment term
            paymentTermSelect.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                const longevity = selectedOption.dataset.longevity;

                if (longevity) {
                    const baseDate = new Date(document.getElementById('date').value);
                    baseDate.setDate(baseDate.getDate() + parseInt(longevity));
                    dueDateInput.value = baseDate.toISOString().split('T')[0];
                } else {
                    console.error("Longevity not defined for the selected payment term.");
                }
            });

            // Update payment term based on selected supplier
            supplierDropdown.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                const paymentTermId = selectedOption.getAttribute('data-payment-term');

                // Reset the payment term dropdown
                paymentTermDropdown.value = '';

                if (paymentTermId) {
                    paymentTermDropdown.value = paymentTermId;

                    const selectedPaymentTermOption = paymentTermDropdown.options[paymentTermDropdown.selectedIndex];
                    const longevity = selectedPaymentTermOption?.dataset?.longevity;

                    if (longevity) {
                        const baseDate = new Date(document.getElementById('date').value);
                        baseDate.setDate(baseDate.getDate() + parseInt(longevity));
                        dueDateInput.value = baseDate.toISOString().split('T')[0];
                    } else {
                        console.warn("Longevity not defined for the selected payment term.");
                    }
                } else {
                    paymentTermDropdown.disabled = true;
                    console.warn("No associated payment term for the selected supplier.");
                }
            });
        });
    </script>
@endpush
