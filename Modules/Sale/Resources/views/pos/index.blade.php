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
            <div class="col-12 mb-3">
                <livewire:pos.session-manager />
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const currencySettings = {
                symbol: @json(settings()->currency->symbol ?? ''),
                thousand_separator: @json(settings()->currency->thousand_separator ?? ','),
                decimal_separator: @json(settings()->currency->decimal_separator ?? '.'),
                code: @json(settings()->currency->code ?? 'IDR'),
                locale: @json(data_get(settings()->currency, 'locale')),
            };
            const decimalDigits = 2;
            const localeGuess = currencySettings.decimal_separator === ',' ? 'id-ID' : 'en-US';
            const formatter = new Intl.NumberFormat(currencySettings.locale || localeGuess, {
                style: 'currency',
                currency: currencySettings.code || 'IDR',
                minimumFractionDigits: decimalDigits,
                maximumFractionDigits: decimalDigits,
            });

            const displays = () => document.querySelectorAll('[data-pos-currency-target]');

            const escapeRegExp = (string) => string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            const parseCurrencyInput = (value) => {
                if (typeof value !== 'string') {
                    return null;
                }

                let working = value.trim();

                if (!working) {
                    return null;
                }

                if (currencySettings.symbol) {
                    working = working.replace(new RegExp(escapeRegExp(currencySettings.symbol), 'g'), '');
                }

                working = working.replace(/\s+/g, '').replace(/[^0-9.,-]/g, '');

                if (!working) {
                    return null;
                }

                const configuredDecimal = currencySettings.decimal_separator || null;
                const configuredMatches = configuredDecimal
                    ? (working.match(new RegExp(escapeRegExp(configuredDecimal), 'g')) || [])
                    : [];

                let decimalChar = null;

                if (configuredDecimal && configuredMatches.length === 1) {
                    decimalChar = configuredDecimal;
                } else {
                    const commaMatches = (working.match(/,/g) || []).length;
                    const dotMatches = (working.match(/\./g) || []).length;
                    const lastComma = working.lastIndexOf(',');
                    const lastDot = working.lastIndexOf('.');

                    if (commaMatches === 1 && (lastComma > lastDot || dotMatches !== 1)) {
                        decimalChar = ',';
                    } else if (dotMatches === 1) {
                        decimalChar = '.';
                    }
                }

                if (!decimalChar && configuredDecimal && configuredMatches.length === 0) {
                    decimalChar = configuredDecimal;
                }

                let integerPart = working;
                let fractionalPart = '';

                if (decimalChar && integerPart.includes(decimalChar)) {
                    const decimalIndex = integerPart.lastIndexOf(decimalChar);
                    fractionalPart = integerPart.slice(decimalIndex + 1);
                    integerPart = integerPart.slice(0, decimalIndex);
                }

                const thousandCandidates = [',', '.'].filter((char) => char !== decimalChar);

                thousandCandidates.forEach((char) => {
                    if (char) {
                        const regex = new RegExp(escapeRegExp(char), 'g');
                        integerPart = integerPart.replace(regex, '');
                        fractionalPart = fractionalPart.replace(regex, '');
                    }
                });

                integerPart = integerPart.replace(/[^0-9-]/g, '');
                fractionalPart = fractionalPart.replace(/[^0-9]/g, '');

                if (!integerPart && !fractionalPart) {
                    return null;
                }

                let negative = false;

                if (integerPart.includes('-')) {
                    negative = integerPart.trim().startsWith('-');
                    integerPart = integerPart.replace(/-/g, '');
                }

                let numericString = integerPart || '0';

                if (fractionalPart) {
                    numericString += '.' + fractionalPart;
                }

                if (negative && numericString !== '0') {
                    numericString = '-' + numericString;
                }

                const numeric = parseFloat(numericString);

                if (Number.isNaN(numeric)) {
                    return null;
                }

                return numeric;
            };

            const refreshDisplayFromHidden = (display, hidden) => {
                if (!hidden) {
                    return;
                }

                if (display.dataset.posCurrencyEditing === 'true') {
                    return;
                }

                const hiddenValue = hidden.value;

                if (hiddenValue === null || hiddenValue === undefined || hiddenValue === '') {
                    display.value = '';
                    return;
                }

                const numeric = parseFloat(hiddenValue);

                if (Number.isNaN(numeric)) {
                    display.value = '';
                    return;
                }

                const formatted = formatter.format(numeric);

                if (display.value !== formatted) {
                    display.value = formatted;
                }
            };

            const refreshPosCurrencyDisplays = () => {
                displays().forEach((display) => {
                    const targetId = display.getAttribute('data-pos-currency-target');
                    if (!targetId) {
                        return;
                    }
                    const hidden = document.getElementById(targetId);
                    refreshDisplayFromHidden(display, hidden);
                });
            };

            const bindDisplayFormatter = (display) => {
                const targetId = display.getAttribute('data-pos-currency-target');
                if (!targetId) {
                    return;
                }

                const hidden = document.getElementById(targetId);

                if (!hidden) {
                    return;
                }

                if (display.dataset.posFormatterBound === 'true') {
                    return;
                }

                display.dataset.posFormatterBound = 'true';

                display.addEventListener('focus', () => {
                    display.dataset.posCurrencyEditing = 'true';

                    if (hidden.value !== undefined && hidden.value !== null && hidden.value !== '') {
                        const numeric = parseFloat(hidden.value);
                        if (!Number.isNaN(numeric)) {
                            const asString = numeric.toFixed(decimalDigits);
                            const localized = currencySettings.decimal_separator && currencySettings.decimal_separator !== '.'
                                ? asString.replace('.', currencySettings.decimal_separator)
                                : asString;
                            display.value = localized;
                        } else {
                            display.value = hidden.value;
                        }
                    } else {
                        display.value = '';
                    }

                    try {
                        display.select();
                    } catch (e) {}
                });

                display.addEventListener('blur', () => {
                    display.dataset.posCurrencyEditing = 'false';
                    refreshDisplayFromHidden(display, hidden);
                });

                display.addEventListener('input', () => {
                    const numeric = parseCurrencyInput(display.value);

                    if (numeric === null) {
                        hidden.value = '';
                        hidden.dispatchEvent(new Event('input', { bubbles: true }));
                        return;
                    }

                    hidden.value = numeric.toFixed(decimalDigits);
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                });
            };

            window.initPosCurrencyFormatter = function () {
                displays().forEach((display) => bindDisplayFormatter(display));
                refreshPosCurrencyDisplays();
            };

            window.addEventListener('showCheckoutModal', () => {
                $('#checkoutModal').modal('show');
                window.initPosCurrencyFormatter();
            });

            window.addEventListener('show-change-modal', (event) => {
                const modal = $('#posChangeModal');
                const amount = event?.detail?.amount ?? '';

                if (amount) {
                    modal.attr('aria-label', `Kembalian Rp. ${amount}`);
                } else {
                    modal.removeAttr('aria-label');
                }

                modal.modal('show');
            });

            window.addEventListener('hide-change-modal', () => {
                $('#posChangeModal').modal('hide');
            });

            window.addEventListener('pos-mask-money-init', () => {
                window.initPosCurrencyFormatter();
            });

            if (window.Livewire && typeof window.Livewire.hook === 'function') {
                window.Livewire.hook('message.processed', () => {
                    refreshPosCurrencyDisplays();
                });
            }

            window.initPosCurrencyFormatter();
        });
    </script>
@endpush
