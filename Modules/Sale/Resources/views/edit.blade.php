@extends('layouts.app')

@section('title', 'Ubah Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item active">Ubah</li>
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
                        @include('utils.alerts')
                        <form id="sale-form" action="{{ route('sales.update', $sale) }}" method="POST">
                            @csrf
                            @method('patch')

                            <!-- Header Form Component -->
                            <livewire:sale.form-header :customerId="$sale->customer_id" :paymentTermId="$sale->payment_term_id" />

                            <!-- Product Cart Livewire Component -->
                            <livewire:sale.product-cart :cartInstance="'sale'" :data="$sale"/>

                            <!-- Catatan -->
                            <div class="form-group mt-4">
                                <label for="note">Catatan (Jika Diperlukan)</label>
                                <textarea name="note" id="note" rows="5"
                                          class="form-control @error('note') is-invalid @enderror">{{ old('note', $sale->note) }}</textarea>
                                @error('note')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Submit Button -->
                            <div class="mt-3">
                                @canany("sale.edit")
                                    <button type="button" class="btn btn-primary"
                                            onclick="showConfirmationModal(() => document.getElementById('sale-form').submit(), 'Apakah Anda yakin ingin memperbaharui penjualan ini?')">
                                        Perbaharui Penjualan <i class="bi bi-check"></i>
                                    </button>
                                @endcanany
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
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(document).ready(function () {
            $('#paid_amount').maskMoney({
                prefix: '{{ settings()->currency->symbol }}',
                thousands: '{{ settings()->currency->thousand_separator }}',
                decimal: '{{ settings()->currency->decimal_separator }}',
                allowZero: true,
            });

            $('#paid_amount').maskMoney('mask');

            $('#sale-form').submit(function () {
                var paid_amount = $('#paid_amount').maskMoney('unmasked')[0];
                $('#paid_amount').val(paid_amount);
            });
        });
    </script>
@endpush
