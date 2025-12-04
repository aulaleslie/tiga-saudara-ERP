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
    <!-- Printer Not Configured Overlay -->
    <div id="printerNotConfiguredOverlay" class="position-fixed w-100 h-100 d-none" style="top: 0; left: 0; background: rgba(0,0,0,0.7); z-index: 9999;">
        <div class="d-flex align-items-center justify-content-center h-100">
            <div class="card shadow-lg" style="max-width: 500px;">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle mr-2"></i> Printer Belum Dikonfigurasi
                    </h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        Printer untuk mencetak struk belum dikonfigurasi di perangkat ini.
                        Silakan konfigurasi printer terlebih dahulu sebelum melanjutkan.
                    </p>
                    <div class="alert alert-info">
                        <small>
                            <i class="bi bi-info-circle mr-1"></i>
                            Pengaturan printer disimpan per perangkat. Jika Anda baru saja pindah ke perangkat baru atau menghapus data browser, konfigurasi printer perlu dilakukan ulang.
                        </small>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <a href="{{ route('app.pos.session') }}" class="btn btn-primary">
                        <i class="bi bi-gear mr-1"></i> Konfigurasi Printer
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="openQuickPrinterSetup()">
                        <i class="bi bi-lightning mr-1"></i> Setup Cepat
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Printer Setup Modal -->
    <div class="modal fade" id="quickPrinterSetupModal" tabindex="-1" role="dialog" aria-labelledby="quickPrinterSetupModalLabel" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickPrinterSetupModalLabel">
                        <i class="bi bi-printer mr-2"></i> Setup Cepat Printer
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle mr-1"></i>
                        Masukkan nama printer thermal 80mm yang terhubung ke komputer ini.
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Nama Printer</label>
                        <input type="text" id="quickPrinterName" class="form-control" placeholder="Contoh: EPSON TM-T82, Xprinter XP-58">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="quickTestPrint()">
                        <i class="bi bi-printer mr-1"></i> Test Print
                    </button>
                    <button type="button" class="btn btn-primary" onclick="quickSavePrinter()">
                        <i class="bi bi-check-lg mr-1"></i> Simpan & Lanjutkan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-12 mb-3">
                <div class="card shadow-sm">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="mb-0">Kelola Sesi POS</h6>
                            <small class="text-muted">Jeda, lanjutkan, atau tutup sesi kasir Anda</small>
                        </div>
                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('app.pos.reprint-last') }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="bi bi-printer mr-1"></i> Cetak Ulang Terakhir
                                </button>
                            </form>
                            <a href="{{ route('app.pos.session') }}" class="btn btn-outline-primary">
                                <i class="bi bi-gear mr-1"></i> Status Sesi POS
                            </a>
                        </div>
                    </div>
                </div>
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
    <script src="{{ asset('js/pos-printer.js') }}"></script>
    <script>
        // Check printer configuration on page load
        document.addEventListener('DOMContentLoaded', function () {
            checkPrinterConfiguration();
            handlePendingPrint();
        });

        function checkPrinterConfiguration() {
            const overlay = document.getElementById('printerNotConfiguredOverlay');
            if (!window.PosPrinterManager || !window.PosPrinterManager.isPrinterConfigured()) {
                overlay.classList.remove('d-none');
            } else {
                overlay.classList.add('d-none');
            }
        }

        // Handle pending print from session (after successful POS sale)
        function handlePendingPrint() {
            @if(session('pos_print_content'))
            // Auto print receipt after successful sale
            const printContent = @json(session('pos_print_content'));
            if (printContent && window.PosPrinterManager && window.PosPrinterManager.isPrinterConfigured()) {
                // Small delay to ensure page is fully loaded
                setTimeout(function() {
                    window.PosPrinterManager.print(printContent)
                        .then(() => {
                            console.log('Receipt printed successfully');
                        })
                        .catch((error) => {
                            console.error('Failed to print receipt:', error);
                            alert('Gagal mencetak struk: ' + error.message);
                        });
                }, 500);
            }
            @endif
        }

        function openQuickPrinterSetup() {
            document.getElementById('printerNotConfiguredOverlay').classList.add('d-none');
            $('#quickPrinterSetupModal').modal('show');
        }

        function quickSavePrinter() {
            const printerName = document.getElementById('quickPrinterName').value.trim();
            if (!printerName) {
                alert('Silakan masukkan nama printer.');
                return;
            }

            window.PosPrinterManager.savePrinter(printerName);
            $('#quickPrinterSetupModal').modal('hide');
            checkPrinterConfiguration();
        }

        function quickTestPrint() {
            const printerName = document.getElementById('quickPrinterName').value.trim();
            if (!printerName) {
                alert('Silakan masukkan nama printer terlebih dahulu.');
                return;
            }

            // Temporarily save for test
            window.PosPrinterManager.savePrinter(printerName);

            window.PosPrinterManager.testPrint()
                .then(() => {
                    alert('Test print berhasil dikirim! Periksa printer Anda.');
                })
                .catch((error) => {
                    alert('Gagal mengirim test print: ' + error.message);
                });
        }

        // Listen for printer selection changes from other tabs/windows
        window.addEventListener('storage', function(e) {
            if (e.key === 'pos_printer_configured') {
                checkPrinterConfiguration();
            }
        });
    </script>
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

                const configuredDecimal = currencySettings.decimal_separator || '';
                const commaMatches = (working.match(/,/g) || []).length;
                const dotMatches = (working.match(/\./g) || []).length;
                const lastComma = working.lastIndexOf(',');
                const lastDot = working.lastIndexOf('.');
                const workingLength = working.length;

                const digitsAfterComma = lastComma === -1 ? 0 : Math.max(0, workingLength - (lastComma + 1));
                const digitsAfterDot = lastDot === -1 ? 0 : Math.max(0, workingLength - (lastDot + 1));

                let decimalChar = null;

                if (commaMatches > 0 && dotMatches > 0) {
                    decimalChar = (lastComma > lastDot) ? ',' : '.';
                } else if (commaMatches === 1 && dotMatches === 0) {
                    if (configuredDecimal === ',' || digitsAfterComma <= 2) {
                        decimalChar = ',';
                    }
                } else if (dotMatches === 1 && commaMatches === 0) {
                    if (configuredDecimal === '.' || digitsAfterDot <= 2) {
                        decimalChar = '.';
                    }
                } else if (decimalChar === null && configuredDecimal) {
                    const configuredCount = (working.match(new RegExp(escapeRegExp(configuredDecimal), 'g')) || []).length;
                    if (configuredCount === 1) {
                        decimalChar = configuredDecimal;
                    }
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

                const numeric = parseCurrencyInput(hiddenValue);

                if (numeric === null) {
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

                    let numeric = parseCurrencyInput(hidden.value);

                    if (numeric === null) {
                        numeric = parseCurrencyInput(display.value);
                    }

                    if (numeric !== null) {
                        const asString = numeric.toFixed(decimalDigits);
                        const localized = currencySettings.decimal_separator && currencySettings.decimal_separator !== '.'
                            ? asString.replace('.', currencySettings.decimal_separator)
                            : asString;
                        display.value = localized;
                    }

                    try {
                        display.select();
                    } catch (e) {}
                });

                const updateHiddenField = () => {
                    // Parse and update hidden field when user finishes editing
                    const numeric = parseCurrencyInput(display.value);

                    if (numeric === null) {
                        hidden.value = '';
                    } else {
                        hidden.value = numeric.toFixed(decimalDigits);
                    }
                    
                    // Dispatch event to notify Livewire of the change
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    // Force Livewire to sync if using wire:model
                    if (typeof Livewire !== 'undefined') {
                        const component = Livewire.find(
                            display.closest('[wire\\:id]')?.getAttribute('wire:id')
                        );
                        if (component) {
                            // Trigger Livewire update by simulating a change
                            setTimeout(() => {
                                hidden.dispatchEvent(new Event('input', { bubbles: true }));
                            }, 10);
                        }
                    }
                };

                const handleLiveUpdate = () => {
                    display.dataset.posCurrencyEditing = 'true';
                    updateHiddenField();
                };

                display.addEventListener('blur', () => {
                    display.dataset.posCurrencyEditing = 'false';
                    updateHiddenField();
                    
                    // Refresh display with formatted value
                    refreshDisplayFromHidden(display, hidden);
                });

                display.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        updateHiddenField();
                        display.blur();
                    }
                });

                display.addEventListener('input', handleLiveUpdate);
                display.addEventListener('keyup', handleLiveUpdate);
            };

            window.initPosCurrencyFormatter = function () {
                displays().forEach((display) => bindDisplayFormatter(display));
                refreshPosCurrencyDisplays();
            };

            window.addEventListener('showCheckoutModal', () => {
                $('#checkoutModal').modal('show');
                window.initPosCurrencyFormatter();
            });

            let lastChangeModalTransactionId = null;

            window.addEventListener('show-change-modal', (event) => {
                const modal = $('#posChangeModal');
                const detail = event?.detail ?? {};
                const amount = detail.amount ?? '';
                const transactionId = detail.transactionId ?? null;
                const explicit = detail.explicit ?? false;

                if (transactionId && transactionId === lastChangeModalTransactionId && !explicit) {
                    return;
                }

                lastChangeModalTransactionId = transactionId ?? lastChangeModalTransactionId;

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
