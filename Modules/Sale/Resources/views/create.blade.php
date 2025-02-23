@extends('layouts.app')

@section('title', 'Buat Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item active">Tambah</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Search Product Livewire Component -->
        <div class="row">
            <div class="col-12">
                <livewire:sale.search-product/>
            </div>
        </div>

        <!-- Sale Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <!-- Alert Messages -->
                        @include('utils.alerts')

                        <!-- Sale Form Start -->
                        <form id="sale-form" action="{{ route('sales.store') }}" method="POST">
                            @csrf

                            <!-- Reference, Customer, Date -->
                            <div class="form-row">
                                <!-- Referensi -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="reference">Keterangan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" id="reference" required
                                               readonly value="PR">
                                        @error('reference')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <!-- Pelanggan -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="customer_id">Pelanggan <span class="text-danger">*</span></label>
                                        <select id="customer_id"
                                                class="form-control @error('customer_id') is-invalid @enderror"
                                                name="customer_id" required>
                                            <option value="">Pilih Pelanggan</option>
                                            @foreach($customers as $customer)
                                                <option value="{{ $customer->id }}"
                                                        data-payment-term="{{ $customer->payment_term_id }}">
                                                    {{ $customer->customer_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('customer_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <!-- Tanggal -->
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="date">Tanggal <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control @error('date') is-invalid @enderror"
                                               name="date" id="date" required value="{{ now()->format('Y-m-d') }}">
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
                                               name="due_date" id="due_date" required
                                               value="{{ now()->format('Y-m-d') }}">
                                        @error('due_date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="payment_term">Term Pembayaran <span
                                                class="text-danger">*</span></label>
                                        <select id="payment_term"
                                                class="form-control @error('payment_term') is-invalid @enderror"
                                                name="payment_term" required>
                                            <option value="">Pilih Term Pembayaran</option>
                                            @foreach($paymentTerms as $term)
                                                <option value="{{ $term->id }}" data-longevity="{{ $term->longevity }}">
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
                            <livewire:sale.product-cart :cartInstance="'sale'"/>

                            <!-- Catatan -->
                            <div class="form-group mt-4">
                                <label for="note">Catatan (Jika Diperlukan)</label>
                                <textarea name="note" id="note" rows="5"
                                          class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                                @error('note')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Submit Button -->
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary"
                                        onclick="showConfirmationModal(() => document.getElementById('sale-form').submit(), 'Apakah Anda yakin ingin membuat penjualan ini?')">
                                    Buat Penjualan <i class="bi bi-check"></i>
                                </button>
                                <a href="{{ route('sales.index') }}" class="btn btn-secondary">Kembali</a>
                            </div>
                        </form>
                        <!-- Sale Form End -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('components.confirmation-modal')
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const paymentTermSelect = document.getElementById('payment_term');
            const dueDateInput = document.getElementById('due_date');
            const customerDropdown = document.getElementById('customer_id');
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

            // Update payment term based on selected customer
            customerDropdown.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                const paymentTermId = selectedOption.getAttribute('data-payment-term');

                // Reset the payment term dropdown
                paymentTermDropdown.value = '';

                // If a payment term is associated with the customer, preselect it
                if (paymentTermId) {
                    paymentTermDropdown.value = paymentTermId;

                    // Update the due date based on the selected payment term
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
                    console.warn("No associated payment term for the selected customer.");
                }
            });
        });
    </script>
@endpush
