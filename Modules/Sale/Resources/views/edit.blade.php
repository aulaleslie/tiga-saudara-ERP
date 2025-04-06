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
        <div class="row">
            <div class="col-12">
                <livewire:search-product/>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')
                        <form id="sale-form" action="{{ route('sales.update', $sale) }}" method="POST">
                            @csrf
                            @method('patch')
                            <livewire:sale.form-header />

                            <livewire:product-cart :cartInstance="'sale'" :data="$sale"/>

                            <div class="form-group">
                                <label for="note">Catatan (Jika Dibutuhkan)</label>
                                <textarea name="note" id="note" rows="5"
                                          class="form-control">{{ $sale->note }}</textarea>
                            </div>

                            <div class="mt-3">

                                @canany("sale.edit")
                                    <button type="submit" class="btn btn-primary">
                                        Perbaharui Penjualan <i class="bi bi-check"></i>
                                    </button>
                                @endcan

                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
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
