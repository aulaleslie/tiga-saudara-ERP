@extends('layouts.pos')

@section('title', 'POS')

@section('third_party_stylesheets')

@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">POS</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-12">
                @include('sale::pos.partials.cash-navigation')
            </div>
            <div class="col-lg-7">
                <livewire:search-product/>
                <livewire:pos.product-list :categories="$product_categories"/>
            </div>
            <div class="col-lg-5">
                <livewire:pos.checkout :cart-instance="'sale'" :customers="$customers"/>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        function initPosCheckoutMaskMoney() {
            const $paidAmount = $('#paid_amount');
            const $totalAmount = $('#total_amount');

            if (!$paidAmount.length || typeof $paidAmount.maskMoney !== 'function') {
                return;
            }

            if ($paidAmount.data('maskMoney')) {
                try { $paidAmount.maskMoney('destroy'); } catch (e) {}
            }

            if ($totalAmount.length && $totalAmount.data('maskMoney')) {
                try { $totalAmount.maskMoney('destroy'); } catch (e) {}
            }

            $paidAmount.maskMoney({
                prefix:'{{ settings()->currency->symbol }}',
                thousands:'{{ settings()->currency->thousand_separator }}',
                decimal:'{{ settings()->currency->decimal_separator }}',
                allowZero: false,
            });

            $totalAmount.maskMoney({
                prefix:'{{ settings()->currency->symbol }}',
                thousands:'{{ settings()->currency->thousand_separator }}',
                decimal:'{{ settings()->currency->decimal_separator }}',
                allowZero: true,
            });

            $paidAmount.maskMoney('mask');
            $totalAmount.maskMoney('mask');
        }

        $(document).ready(function () {
            window.addEventListener('showCheckoutModal', () => {
                $('#checkoutModal').modal('show');

                initPosCheckoutMaskMoney();

                $('#checkout-form').off('submit.pos').on('submit.pos', function () {
                    const paidAmount = $('#paid_amount').maskMoney('unmasked')[0];
                    $('#paid_amount').val(paidAmount);
                    const totalAmount = $('#total_amount').maskMoney('unmasked')[0];
                    $('#total_amount').val(totalAmount);
                });
            });

            window.addEventListener('pos-mask-money-init', () => {
                initPosCheckoutMaskMoney();
            });
        });
    </script>

@endpush
