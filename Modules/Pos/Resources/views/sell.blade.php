@extends('layouts.pos')

@section('title', 'Kasir POS')

@push('page_css')
<style>
    html,
    body {
        height: 100%;
        overflow: hidden;
    }

    .pos-shell {
        height: 100dvh;
        max-height: 100dvh;
        background: #f2f4f8;
        padding: 0.5rem;
        overflow: hidden;
    }

    .pos-lock-screen {
        display: none;
    }

    .pos-viewport {
        height: 100%;
        display: grid;
        grid-template-columns: minmax(0, 7fr) minmax(0, 3fr);
        grid-template-rows: clamp(64px, 9dvh, 86px) clamp(104px, 16dvh, 150px) minmax(0, 1fr) clamp(132px, 22dvh, 184px);
        grid-template-areas:
            "info nav"
            "search search"
            "cart customer"
            "cart payment";
        gap: 0.5rem;
        min-height: 0;
    }

    .pos-area {
        min-height: 0;
    }

    .pos-area-nav {
        position: relative;
        z-index: 40;
    }

    .pos-area-search {
        position: relative;
        z-index: 30;
    }

    .pos-area-customer {
        position: relative;
        z-index: 25;
    }

    .pos-area-cart,
    .pos-area-payment {
        position: relative;
        z-index: 10;
    }

    .pos-card {
        border: 1px solid #dbe1ea;
        border-radius: 0.5rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .pos-card .card-header {
        flex: 0 0 auto;
        padding: 0.36rem 0.68rem;
    }

    .pos-card .card-body {
        flex: 1 1 auto;
        min-height: 0;
        padding: 0.45rem 0.68rem;
        overflow: hidden;
    }

    .pos-area-nav .card-body,
    .pos-area-search .card-body,
    .pos-area-customer .card-body {
        overflow: visible;
    }

    .pos-section-title {
        font-size: 0.84rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        margin: 0;
        color: #1f2937;
    }

    .pos-area-info {
        grid-area: info;
    }

    .pos-area-nav {
        grid-area: nav;
    }

    .pos-area-search {
        grid-area: search;
    }

    .pos-area-cart {
        grid-area: cart;
    }

    .pos-area-customer {
        grid-area: customer;
    }

    .pos-area-payment {
        grid-area: payment;
    }

    .pos-thin-card .card-body {
        padding-top: 0.34rem;
        padding-bottom: 0.34rem;
    }

    .pos-info-strip {
        height: 100%;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.6rem;
        align-items: center;
    }

    .pos-info-title {
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
        color: #0f172a;
    }

    .pos-info-metrics {
        display: flex;
        align-items: center;
        gap: 0.72rem;
        overflow: hidden;
        white-space: nowrap;
        min-width: 0;
    }

    .pos-info-item {
        font-size: 0.74rem;
        color: #334155;
        line-height: 1.1;
        white-space: nowrap;
    }

    .pos-info-item strong {
        color: #0f172a;
        font-weight: 600;
    }

    .pos-nav-strip {
        height: 100%;
        display: grid;
        grid-template-columns: auto auto minmax(0, 1fr);
        align-items: center;
        gap: 0.45rem;
    }

    .pos-nav-label {
        font-size: 0.8rem;
        font-weight: 700;
        color: #0f172a;
        white-space: nowrap;
    }

    .pos-nav-strip .btn {
        padding-top: 0.28rem;
        padding-bottom: 0.28rem;
        font-size: 0.74rem;
    }

    .pos-nav-strip .dropdown-menu {
        max-height: 230px;
        overflow-y: auto;
        z-index: 1400;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
        border: 1px solid #dbe1ea;
    }

    .pos-nav-note {
        justify-self: end;
        font-size: 0.66rem;
        color: #94a3b8;
        line-height: 1;
    }

    #pos-shell-posting-note {
        margin: 0;
    }

    .pos-search-grid {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        min-height: 0;
    }

    .pos-search-head {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.45rem;
        align-items: end;
    }

    .pos-search-main {
        min-width: 0;
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .pos-search-main label {
        margin-bottom: 0;
    }

    #pos-shell-scan-feedback {
        height: calc(1.5em + 0.75rem + 2px);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    #pos-shell-search-status {
        min-height: 1rem;
        line-height: 1.2;
        margin: 0;
    }

    #pos-shell-search-results {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        max-height: clamp(180px, 31dvh, 290px);
        overflow-y: auto;
        z-index: 1300;
        background: #fff;
        border: 1px solid #dbe1ea;
        border-radius: 0.45rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
    }

    #pos-shell-search-results:empty {
        display: none;
    }

    .pos-cart-shell {
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .pos-cart-table-wrap {
        flex: 1 1 auto;
        min-height: 0;
        overflow: auto;
        border: 1px solid #e2e8f0;
        border-radius: 0.45rem;
        background: #fff;
    }

    .pos-cart-table {
        margin-bottom: 0;
    }

    .pos-cart-table td,
    .pos-cart-table th {
        vertical-align: middle;
        padding: 0.31rem 0.4rem;
        font-size: 0.77rem;
    }

    .pos-cart-product {
        max-width: 320px;
    }

    .pos-cart-product .name {
        font-weight: 700;
        line-height: 1.2;
    }

    .pos-cart-product .meta {
        font-size: 0.69rem;
        color: #64748b;
        line-height: 1.2;
    }

    .pos-cart-qty {
        width: 70px;
        margin: 0 auto;
    }

    .pos-cart-actions {
        text-align: center;
    }

    .pos-cart-actions .btn {
        min-width: 60px;
        font-size: 0.69rem;
        padding-top: 0.18rem;
        padding-bottom: 0.18rem;
    }



    .pos-customer-shell {
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
        position: relative;
    }

    .pos-customer-search-anchor {
        position: relative;
    }

    #pos-customer-search-results {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        max-height: clamp(150px, 24dvh, 230px);
        overflow-y: auto;
        z-index: 1250;
        background: #fff;
        border: 1px solid #dbe1ea;
        border-radius: 0.45rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
    }

    #pos-customer-search-results:empty {
        display: none;
    }

    .pos-payment-shell {
        min-height: 0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.45rem;
        height: 100%;
    }

    .pos-total-value {
        font-size: clamp(1.25rem, 2.4vw, 1.82rem);
        font-weight: 700;
        line-height: 1.1;
        margin-bottom: 0;
    }

    .pos-payment-shell .btn {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        font-size: 0.92rem;
    }

    #pos-shell-search-results .list-group-item,
    #pos-customer-search-results .list-group-item {
        padding: 0.45rem 0.55rem;
        line-height: 1.35;
        font-size: 0.79rem;
        border-left: 0;
        border-right: 0;
    }

    #pos-shell-search-results .list-group-item:first-child,
    #pos-customer-search-results .list-group-item:first-child {
        border-top: 0;
    }

    #pos-shell-search-results .list-group-item:last-child,
    #pos-customer-search-results .list-group-item:last-child {
        border-bottom: 0;
    }

    .modal-dialog {
        margin: 0.75rem auto;
    }

    @media (max-height: 780px) {
        .pos-shell {
            padding: 0.4rem;
        }

        .pos-viewport {
            gap: 0.4rem;
            grid-template-rows: clamp(58px, 8dvh, 76px) clamp(92px, 14dvh, 126px) minmax(0, 1fr) clamp(118px, 21dvh, 164px);
        }

        .pos-card .card-header {
            padding: 0.28rem 0.58rem;
        }

        .pos-card .card-body {
            padding: 0.34rem 0.58rem;
        }

        .pos-section-title {
            font-size: 0.78rem;
        }

        .pos-info-title,
        .pos-nav-label {
            font-size: 0.74rem;
        }

        .pos-info-item {
            font-size: 0.69rem;
        }

        .pos-nav-strip .btn {
            font-size: 0.69rem;
            padding-top: 0.22rem;
            padding-bottom: 0.22rem;
        }

        .pos-cart-table td,
        .pos-cart-table th {
            font-size: 0.72rem;
            padding: 0.24rem 0.32rem;
        }

        .pos-total-value {
            font-size: clamp(1.1rem, 2.1vw, 1.55rem);
        }

        .pos-payment-shell .btn {
            font-size: 0.82rem;
            padding-top: 0.36rem;
            padding-bottom: 0.36rem;
        }
    }

    @media (max-width: 991.98px) {
        .pos-viewport {
            grid-template-columns: minmax(0, 6fr) minmax(0, 4fr);
            grid-template-rows: clamp(64px, 10dvh, 88px) clamp(96px, 15dvh, 132px) minmax(0, 1fr) clamp(128px, 22dvh, 176px);
        }
    }

    @media (max-width: 767.98px) and (orientation: landscape) {
        .pos-viewport {
            grid-template-columns: minmax(0, 58fr) minmax(0, 42fr);
            grid-template-rows: clamp(60px, 12dvh, 76px) clamp(92px, 18dvh, 124px) minmax(0, 1fr) clamp(118px, 24dvh, 160px);
            gap: 0.35rem;
        }

        .pos-shell {
            padding: 0.32rem;
        }

        .pos-info-strip {
            gap: 0.4rem;
        }

        .pos-info-metrics {
            gap: 0.5rem;
        }

        .pos-info-item,
        .pos-nav-label,
        .pos-info-title {
            font-size: 0.66rem;
        }

        .pos-nav-strip {
            grid-template-columns: auto auto;
        }

        .pos-nav-note {
            display: none;
        }

        .pos-nav-strip .btn {
            font-size: 0.66rem;
            padding-top: 0.18rem;
            padding-bottom: 0.18rem;
        }

        #pos-shell-scan-feedback {
            font-size: 0.68rem;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .pos-cart-table td,
        .pos-cart-table th {
            font-size: 0.68rem;
        }

        .pos-total-value {
            font-size: 1.05rem;
        }

        .pos-payment-shell .btn {
            font-size: 0.74rem;
            padding-top: 0.32rem;
            padding-bottom: 0.32rem;
        }

        #pos-shell-search-results,
        #pos-customer-search-results {
            max-height: 42dvh;
        }
    }

    @media (max-width: 640px) and (orientation: portrait) {
        .pos-shell {
            display: none;
        }

        .pos-lock-screen {
            display: flex;
            height: 100dvh;
            width: 100%;
            padding: 1.1rem;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: #e2e8f0;
            text-align: center;
        }

        .pos-lock-card {
            width: 100%;
            max-width: 420px;
            border-radius: 0.8rem;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.76);
            padding: 1.1rem;
        }

        .pos-lock-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 0.45rem;
        }

        .pos-lock-text {
            font-size: 0.88rem;
            margin-bottom: 0;
            color: #cbd5e1;
        }
    }
</style>
@endpush

@section('content')
    @php
        $terminalLabelFull = $activeSession->terminal
            ? ($activeSession->terminal->code . ' (' . $activeSession->terminal->name . ')')
            : '-';
        $terminalLabelShort = \Illuminate\Support\Str::limit($terminalLabelFull, 30);
    @endphp

    <div class="pos-lock-screen" aria-live="polite">
        <div class="pos-lock-card">
            <div class="pos-lock-title">Gunakan Mode Landscape</div>
            <p class="pos-lock-text">
                Putar perangkat ke posisi landscape untuk menggunakan POS kasir dengan nyaman.
            </p>
        </div>
    </div>

    <div class="pos-shell">
        @include('utils.alerts')

        <div class="container-fluid px-0 h-100">
            <div class="pos-viewport">
                <div class="pos-area pos-area-info">
                    <div class="card pos-card pos-thin-card">
                        <div class="card-body">
                            <span class="d-none">Layar Kasir POS</span>
                            <span class="d-none">Sesi #{{ $activeSession->id }}</span>
                            <div class="pos-info-strip">
                                <div class="pos-info-title">Kasir Information</div>
                                <div class="pos-info-metrics">
                                    <span class="pos-info-item"><strong>Sesi:</strong> #{{ $activeSession->id }}</span>
                                    <span class="pos-info-item" title="{{ $terminalLabelFull }}"><strong>Terminal:</strong> {{ $terminalLabelShort }}</span>
                                    <span class="pos-info-item"><strong>Dibuka:</strong> {{ optional($activeSession->opened_at)->format('Y-m-d H:i') ?? '-' }}</span>
                                    <span class="pos-info-item"><strong>Status:</strong> {{ strtoupper($activeSession->status) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pos-area pos-area-nav">
                    <div class="card pos-card pos-thin-card">
                        <div class="card-body">
                            <div class="pos-nav-strip">
                                <div class="pos-nav-label">Navigation</div>
                                <a href="{{ route('home') }}" class="btn btn-outline-dark">Kembali</a>
                                <div class="dropdown">
                                    <button class="btn btn-secondary dropdown-toggle" type="button" id="pos-nav-menu-dropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        Menu
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="pos-nav-menu-dropdown">
                                        <button type="button" id="pos-shortcut-reprint" class="dropdown-item" disabled>Reprint</button>

                                        @can('pos.reports.access')
                                            <a href="{{ route('pos.reports.index') }}" target="_blank" class="dropdown-item">Lap. Sales</a>
                                        @endcan
                                        @can('saleReturns.access')
                                            <a href="{{ route('sale-returns.index') }}" target="_blank" class="dropdown-item">Retur</a>
                                        @endcan

                                        @if(auth()->user()->canAny(['pos.sessions.view', 'pos.monitor.access', 'pos.reconciliation.access', 'pos.terminals.access']))
                                            <div class="dropdown-divider"></div>
                                        @endif

                                        @can('pos.sessions.view')
                                            <a class="dropdown-item" href="{{ route('pos.sessions.index') }}" target="_blank">Sesi POS</a>
                                        @endcan
                                        @can('pos.monitor.access')
                                            <a class="dropdown-item" href="{{ route('pos.monitor.index') }}" target="_blank">Monitor</a>
                                        @endcan
                                        @can('pos.reconciliation.access')
                                            <a class="dropdown-item" href="{{ route('pos.reconciliation.index') }}" target="_blank">Rekonsiliasi</a>
                                        @endcan
                                        @can('pos.terminals.access')
                                            <a class="dropdown-item" href="{{ route('pos.terminals.index') }}" target="_blank">Kelola Terminal</a>
                                        @endcan
                                    </div>
                                </div>
                                <span id="pos-shell-posting-note" class="pos-nav-note">pos-shell-posting-note</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pos-area pos-area-search">
                    <div class="card pos-card">
                        <div class="card-header bg-white">
                            <h5 class="pos-section-title">Search</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-search-grid">
                                <div class="pos-search-head">
                                    <div class="pos-search-main">
                                        <label for="pos-shell-search" class="small font-weight-bold">Pencarian / Pindai Produk</label>
                                        <input id="pos-shell-search" type="text" class="form-control"
                                               placeholder="Pindai barcode atau ketik nama/SKU"
                                               autocomplete="off">
                                        <div id="pos-shell-search-results" class="list-group"></div>
                                    </div>
                                    <button class="btn btn-outline-primary" type="button" id="pos-shell-scan-feedback">
                                        Siap Pindai
                                    </button>
                                </div>
                                <p id="pos-shell-search-status" class="small text-muted"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pos-area pos-area-cart">
                    <div class="card pos-card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="pos-section-title">Keranjang</h5>
                            <button id="pos-cart-clear" class="btn btn-sm btn-outline-danger" type="button">
                                Kosongkan Keranjang
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="pos-cart-shell">
                                <div class="pos-cart-table-wrap">
                                    <table class="table table-sm pos-cart-table">
                                        <thead>
                                        <tr>
                                            <th>Produk</th>
                                            <th class="text-right">Harga</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-right">Sub Total</th>
                                        </tr>
                                        </thead>
                                        <tbody id="pos-shell-cart-body">
                                        <tr id="pos-shell-cart-empty-row">
                                            <td colspan="4" class="text-muted text-center py-4">Keranjang kosong.</td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div id="pos-cart-action-alert" class="alert alert-danger p-2 mb-0 mt-2 small d-none font-weight-bold" role="alert">
                                    <!-- Error message goes here -->
                                </div>
                                <p id="pos-cart-action-status" class="mb-0 mt-1 small text-muted"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pos-area pos-area-customer">
                    <div class="card pos-card">
                        <div class="card-header bg-white">
                            <h5 class="pos-section-title">Pelanggan</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-customer-shell">
                                <label for="pos-customer-search" class="small font-weight-bold mb-1">Pelanggan (Opsional)</label>
                                <div class="pos-customer-search-anchor">
                                    <input id="pos-customer-search" type="text" class="form-control"
                                           placeholder="Cari nama / telepon pelanggan">
                                    <div id="pos-customer-search-results" class="list-group"></div>
                                </div>
                                <button id="pos-customer-clear" class="btn btn-sm btn-outline-secondary mt-1" type="button">
                                    Gunakan Pelanggan Walk-in Default
                                </button>
                                <p id="pos-customer-resolution" class="small text-muted mt-1 mb-0"></p>
                                <p id="pos-customer-action-status" class="small text-muted mt-1 mb-0"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pos-area pos-area-payment">
                    <div class="card pos-card">
                        <div class="card-header bg-white">
                            <h5 class="pos-section-title">Pembayaran</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-payment-shell">
                                <div>
                                    <div class="small text-muted">Total Akhir</div>
                                    <div id="pos-payment-summary-total" class="pos-total-value">Rp0</div>
                                </div>
                                <div class="d-flex" style="gap: 0.5rem;">
                                    <button id="pos-save-draft" class="btn btn-outline-secondary btn-lg" type="button">
                                        Simpan Draft
                                    </button>
                                    <button id="pos-checkout-final" class="btn btn-primary btn-lg flex-grow-1" type="button" disabled>
                                        Pilih Pembayaran
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="pos-checkout-modal" tabindex="-1" role="dialog" aria-labelledby="pos-checkout-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-checkout-modal-label">Pembayaran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-0">
                    <div class="row no-gutters">
                        <div class="col-lg-7 border-right bg-light p-4 d-none d-lg-block">
                            <h5 class="mb-3 text-muted">Ringkasan Pesanan</h5>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm">
                                    <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-right">Qty</th>
                                        <th class="text-right">Harga</th>
                                        <th class="text-right">Total</th>
                                    </tr>
                                    </thead>
                                    <tbody id="pos-checkout-receipt-lines"></tbody>
                                </table>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Total Akhir</span>
                                <strong id="pos-checkout-receipt-total">Rp0</strong>
                            </div>
                        </div>

                        <div class="col-lg-5 p-4">
                            <div id="pos-checkout-error" class="alert alert-danger d-none"></div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold d-block mb-2">Metode Pembayaran</label>
                                <div class="btn-group btn-group-sm d-flex" role="group" aria-label="Payment method" id="pos-checkout-method-selector">
                                    <button type="button" class="btn btn-outline-success js-payment-method active" data-method="cash">Tunai</button>
                                    <button type="button" class="btn btn-outline-info js-payment-method" data-method="transfer">Transfer</button>
                                    <button type="button" class="btn btn-outline-dark js-payment-method" data-method="qris">QRIS</button>
                                </div>
                                <input type="hidden" id="pos-checkout-method-code" value="cash">
                                <input type="text" id="pos-checkout-method-label" class="form-control-plaintext font-weight-bold text-uppercase mt-1" readonly value="TUNAI">
                            </div>

                            <div class="form-group row mb-2">
                                <label class="col-sm-4 col-form-label font-weight-bold">Total Akhir</label>
                                <div class="col-sm-8">
                                    <input type="text" id="pos-checkout-total-label" class="form-control-plaintext font-weight-bold h4 mb-0 text-primary" readonly value="Rp0">
                                </div>
                            </div>

                            <hr class="my-3">

                            <div class="form-group mb-3">
                                <label for="pos-checkout-amount-paid" class="font-weight-bold">Jumlah Bayar</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text font-weight-bold bg-white h5 mb-0">Rp</span>
                                    </div>
                                    <input type="number" id="pos-checkout-amount-paid" class="form-control form-control-lg font-weight-bold text-right" step="0.01" min="0" style="font-size: 1.4rem;">
                                </div>
                            </div>

                            <div id="pos-checkout-presets-wrapper" class="mb-3">
                                <div class="d-flex flex-wrap" style="gap: 8px;">
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="uang-pas">Uang Pas</button>
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="50000">50.000</button>
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="100000">100.000</button>
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="150000">150.000</button>
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="200000">200.000</button>
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="250000">250.000</button>
                                    <button type="button" class="btn btn-outline-secondary js-preset-amount" data-amount="500000">500.000</button>
                                </div>
                            </div>

                            <div id="pos-checkout-change-wrapper" class="form-group row mb-2 bg-light p-2 rounded">
                                <label class="col-sm-4 col-form-label font-weight-bold">Kembalian</label>
                                <div class="col-sm-8">
                                    <input type="text" id="pos-checkout-change-label" class="form-control-plaintext font-weight-bold text-success h4 mb-0 text-right" readonly value="Rp0">
                                </div>
                            </div>

                            <div id="pos-checkout-reference-wrapper" class="form-group d-none">
                                <label for="pos-checkout-reference" class="font-weight-bold">Referensi / No. Transaksi</label>
                                <input type="text" id="pos-checkout-reference" class="form-control form-control-lg" placeholder="Masukkan referensi pembayaran">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-lg" data-dismiss="modal">Batal</button>
                    <button type="button" id="pos-checkout-submit" class="btn btn-primary btn-lg px-5">Konfirmasi Pembayaran</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="pos-success-modal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content text-center py-4">
                <div class="modal-body">
                    <div class="mb-3">
                        <i class="fas fa-check-circle text-success fa-5x"></i>
                    </div>
                    <h4 class="mb-2">Pembayaran Berhasil!</h4>
                    <p id="pos-success-receipt" class="text-muted mb-1"></p>
                    <p id="pos-success-change" class="font-weight-bold text-success mb-1"></p>
                    <hr>
                    <button type="button" class="btn btn-outline-primary btn-block mb-2" id="pos-success-print-btn" onclick="printReceipt()">Cetak Struk</button>
                    <button type="button" class="btn btn-primary btn-block" data-dismiss="modal">Lanjut Jualan</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const searchInput = document.getElementById('pos-shell-search');
            const statusElement = document.getElementById('pos-shell-search-status');
            const resultListElement = document.getElementById('pos-shell-search-results');
            const scanFeedbackButton = document.getElementById('pos-shell-scan-feedback');
            const cartBody = document.getElementById('pos-shell-cart-body');
            const cartStatusElement = document.getElementById('pos-cart-action-status');
            const cartActionAlert = document.getElementById('pos-cart-action-alert');
            const clearCartButton = document.getElementById('pos-cart-clear');
            const subtotalElement = document.getElementById('pos-cart-total-subtotal');
            const grandElement = document.getElementById('pos-cart-total-grand');
            const paymentSummaryTotal = document.getElementById('pos-payment-summary-total');

            const customerSearchInput = document.getElementById('pos-customer-search');
            const customerResultListElement = document.getElementById('pos-customer-search-results');
            const customerClearButton = document.getElementById('pos-customer-clear');
            const customerResolutionElement = document.getElementById('pos-customer-resolution');
            const customerStatusElement = document.getElementById('pos-customer-action-status');

            const btnCheckout = document.getElementById('pos-checkout-final');

            const checkoutModalElement = document.getElementById('pos-checkout-modal');
            const checkoutMethodLabel = document.getElementById('pos-checkout-method-label');
            const checkoutMethodCode = document.getElementById('pos-checkout-method-code');
            const checkoutMethodButtons = Array.from(document.querySelectorAll('.js-payment-method'));
            const checkoutTotalLabel = document.getElementById('pos-checkout-total-label');
            const checkoutAmountPaid = document.getElementById('pos-checkout-amount-paid');
            const checkoutChangeLabel = document.getElementById('pos-checkout-change-label');
            const checkoutChangeWrapper = document.getElementById('pos-checkout-change-wrapper');
            const checkoutReference = document.getElementById('pos-checkout-reference');
            const checkoutReferenceWrapper = document.getElementById('pos-checkout-reference-wrapper');
            const checkoutPresetsWrapper = document.getElementById('pos-checkout-presets-wrapper');
            const checkoutSubmit = document.getElementById('pos-checkout-submit');
            const checkoutError = document.getElementById('pos-checkout-error');

            const checkoutReceiptLines = document.getElementById('pos-checkout-receipt-lines');
            const checkoutReceiptTotal = document.getElementById('pos-checkout-receipt-total');

            const successReceiptElement = document.getElementById('pos-success-receipt');
            const successChangeElement = document.getElementById('pos-success-change');
            const shortcutReprintBtn = document.getElementById('pos-shortcut-reprint');

            const searchEndpoint = @json(route('pos.sell.products.search'));
            const customerSearchEndpoint = @json(route('pos.sell.customers.search'));
            const cartShowEndpoint = @json(route('pos.sell.cart.show'));
            const cartStoreLineEndpoint = @json(route('pos.sell.cart.lines.store'));
            const cartClearEndpoint = @json(route('pos.sell.cart.clear'));
            const cartCustomerEndpoint = @json(route('pos.sell.cart.customer.update'));
            const finalizeEndpoint = @json(route('pos.sell.checkout.finalize'));
            const cartLinesBaseUrl = @json(url('/pos/sell/cart/lines'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            if (!searchInput || !statusElement || !resultListElement || !cartBody || !searchEndpoint || !cartShowEndpoint) {
                return;
            }

            let debounceHandle = null;
            let latestRequestId = 0;
            let customerDebounceHandle = null;
            let latestCustomerRequestId = 0;
            let currentSnapshot = null;

            const idrFormatter = new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                maximumFractionDigits: 0
            });

            function setSearchStatus(message, tone) {
                statusElement.textContent = message || '';
                statusElement.classList.remove('text-muted', 'text-danger', 'text-success');
                statusElement.classList.add(tone || 'text-muted');
            }

            function setCartStatus(message, tone, showAsAlert = false) {
                if (cartActionAlert) {
                    if (showAsAlert && tone === 'text-danger') {
                        cartActionAlert.textContent = message || '';
                        cartActionAlert.classList.remove('d-none');
                        // Optional: auto-hide the alert after 4 seconds
                        setTimeout(() => {
                            if (cartActionAlert.textContent === message) {
                                cartActionAlert.classList.add('d-none');
                            }
                        }, 4000);
                        
                        // Clear the muted status if showing alert
                        if (cartStatusElement) cartStatusElement.textContent = '';
                        return;
                    } else {
                        cartActionAlert.classList.add('d-none');
                    }
                }

                if (!cartStatusElement) {
                    return;
                }

                cartStatusElement.textContent = message || '';
                cartStatusElement.classList.remove('text-muted', 'text-danger', 'text-success');
                cartStatusElement.classList.add(tone || 'text-muted');
            }

            function setCustomerStatus(message, tone) {
                if (!customerStatusElement) {
                    return;
                }

                customerStatusElement.textContent = message || '';
                customerStatusElement.classList.remove('text-muted', 'text-danger', 'text-success');
                customerStatusElement.classList.add(tone || 'text-muted');
            }

            function clearResults() {
                resultListElement.innerHTML = '';
            }

            function clearCustomerResults() {
                if (customerResultListElement) {
                    customerResultListElement.innerHTML = '';
                }
            }

            function formatPrice(value) {
                const numeric = Number(value || 0);
                return idrFormatter.format(Number.isFinite(numeric) ? numeric : 0);
            }

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            async function jsonRequest(url, method, payload) {
                const options = {
                    method: method || 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                };

                if (options.method !== 'GET') {
                    options.headers['X-CSRF-TOKEN'] = csrfToken;
                    options.headers['Content-Type'] = 'application/json';
                }

                if (payload !== undefined) {
                    options.body = JSON.stringify(payload);
                }

                const response = await fetch(url, options);

                if (response.redirected) {
                    window.location.href = response.url;
                    return null;
                }

                let body = null;
                try {
                    body = await response.json();
                } catch (error) {
                    body = null;
                }

                if (!response.ok) {
                    const errorMessage = body && body.message ? body.message : 'Permintaan gagal diproses.';
                    throw new Error(errorMessage);
                }

                return body;
            }

            function getLineEndpoint(lineId) {
                return cartLinesBaseUrl + '/' + lineId;
            }

            function renderTotals(snapshot) {
                const totals = snapshot && snapshot.totals ? snapshot.totals : {};
                const subtotal = Number(totals.subtotal || 0);
                const grandTotal = Number(totals.grand_total || 0);

                if (subtotalElement) subtotalElement.textContent = formatPrice(subtotal);
                if (grandElement) grandElement.textContent = formatPrice(grandTotal);
                if (paymentSummaryTotal) paymentSummaryTotal.textContent = formatPrice(grandTotal);
            }

            function renderCustomer(snapshot) {
                if (!customerResolutionElement) {
                    return;
                }

                const customer = snapshot && snapshot.customer ? snapshot.customer : {};
                const selected = customer.selected_customer || null;
                const selectedName = selected && selected.display_name ? selected.display_name : null;
                const selectedPhone = selected && selected.customer_phone ? selected.customer_phone : null;
                const defaultCustomer = customer.default_customer || null;
                const defaultName = defaultCustomer && defaultCustomer.display_name ? defaultCustomer.display_name : null;
                const defaultPhone = defaultCustomer && defaultCustomer.customer_phone ? defaultCustomer.customer_phone : null;
                const resolutionSource = customer.resolution_source || 'unresolved';
                const resolutionError = customer.resolution_error || null;

                if (resolutionSource === 'selected') {
                    customerResolutionElement.textContent = selectedName
                        ? 'Pelanggan terpilih: ' + selectedName + (selectedPhone ? ' (' + selectedPhone + ')' : '')
                        : 'Pelanggan terpilih.';
                    customerResolutionElement.classList.remove('text-danger');
                    customerResolutionElement.classList.add('text-muted');
                    return;
                }

                if (resolutionSource === 'default') {
                    customerResolutionElement.textContent = defaultName
                        ? 'Walk-in default: ' + defaultName + (defaultPhone ? ' (' + defaultPhone + ')' : '')
                        : 'Walk-in default digunakan.';
                    customerResolutionElement.classList.remove('text-danger');
                    customerResolutionElement.classList.add('text-muted');
                    return;
                }

                const errorMessage = resolutionError && resolutionError.message
                    ? String(resolutionError.message)
                    : 'Pelanggan walk-in default belum dikonfigurasi.';
                customerResolutionElement.textContent = errorMessage;
                customerResolutionElement.classList.remove('text-muted');
                customerResolutionElement.classList.add('text-danger');
            }

            async function updateCustomerSelection(customerId) {
                const payload = customerId === null ? { customer_id: null } : { customer_id: Number(customerId) };

                const response = await jsonRequest(cartCustomerEndpoint, 'PATCH', payload);
                if (!response) {
                    return null;
                }

                renderCart(response.cart_snapshot || null);
                return response;
            }

            function renderCustomerSearchResults(data) {
                clearCustomerResults();

                if (!customerResultListElement) {
                    return;
                }

                const results = Array.isArray(data.results) ? data.results : [];

                if (results.length === 0) {
                    setCustomerStatus('Pelanggan tidak ditemukan.', 'text-muted');
                    return;
                }

                results.forEach((customer) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action list-group-item-light py-1 px-2';

                    const displayName = escapeHtml(customer.display_name || customer.customer_name || '-');
                    const phone = escapeHtml(customer.customer_phone || '-');

                    button.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small">${displayName}</span>
                            <span class="small text-muted">${phone}</span>
                        </div>
                    `;

                    button.addEventListener('click', async function () {
                        try {
                            await updateCustomerSelection(customer.id);
                            clearCustomerResults();
                            if (customerSearchInput) {
                                customerSearchInput.value = '';
                            }
                            setCustomerStatus('Pelanggan berhasil dipilih.', 'text-success');
                        } catch (error) {
                            setCustomerStatus(error.message || 'Gagal memilih pelanggan.', 'text-danger');
                        }
                    });

                    customerResultListElement.appendChild(button);
                });
            }

            async function executeCustomerSearch(query) {
                latestCustomerRequestId += 1;
                const requestId = latestCustomerRequestId;
                setCustomerStatus('Mencari pelanggan...', 'text-muted');

                const url = new URL(customerSearchEndpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '8');

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Pencarian pelanggan gagal.');
                    }

                    const data = await response.json();

                    if (requestId !== latestCustomerRequestId) {
                        return;
                    }

                    renderCustomerSearchResults(data);
                } catch (error) {
                    if (requestId !== latestCustomerRequestId) {
                        return;
                    }

                    clearCustomerResults();
                    setCustomerStatus('Pencarian pelanggan gagal.', 'text-danger');
                }
            }

            function buildLineRow(line) {
                const serialBadge = line.serial_number_required
                    ? '<span class="badge badge-warning ml-1">Perlu Serial</span>'
                    : '';

                const productName = escapeHtml(line.product_name || '-');
                const productCode = escapeHtml(line.product_code || '-');
                const barcode = escapeHtml(line.barcode || '-');
                const qty = Number(line.qty || 0);
                const availableQty = Number(line.available_qty || 0);
                const lineId = Number(line.line_id || 0);

                return `
                    <tr data-line-id="${lineId}">
                        <td class="pos-cart-product align-middle">
                            <div class="name">${productName}${serialBadge}</div>
                            <div class="meta">${productCode} | ${barcode}</div>
                            <div class="meta">Stok: ${availableQty}</div>
                        </td>
                        <td class="text-right align-middle">${formatPrice(line.unit_price || 0)}</td>
                        <td class="text-center align-middle">
                            <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty" type="number" min="1" value="${qty}" data-prev-qty="${qty}">
                        </td>
                        <td class="text-right align-middle">
                            <div class="font-weight-bold mb-1">${formatPrice(line.line_total || 0)}</div>
                            <button type="button" class="btn btn-link text-danger p-0 small js-line-remove" style="font-size: 0.75rem; text-decoration: none;">Hapus</button>
                        </td>
                    </tr>
                `;
            }

            function renderCart(snapshot) {
                currentSnapshot = snapshot || null;
                const lines = snapshot && Array.isArray(snapshot.lines) ? snapshot.lines : [];

                if (lines.length === 0) {
                    cartBody.innerHTML = `
                        <tr id="pos-shell-cart-empty-row">
                            <td colspan="4" class="text-muted text-center py-4">Keranjang kosong.</td>
                        </tr>
                    `;
                } else {
                    cartBody.innerHTML = lines.map(buildLineRow).join('');
                }

                renderTotals(snapshot);
                renderCustomer(snapshot);

                const grandTotal = snapshot && snapshot.totals ? Number(snapshot.totals.grand_total || 0) : 0;
                const hasItems = snapshot && Array.isArray(snapshot.lines) && snapshot.lines.length > 0;
                const canCheckout = hasItems && grandTotal > 0;

                if (btnCheckout) {
                    btnCheckout.disabled = !canCheckout;
                }
            }

            async function refreshCart() {
                try {
                    const response = await jsonRequest(cartShowEndpoint, 'GET');
                    if (!response) {
                        return;
                    }

                    renderCart(response.cart_snapshot || null);
                } catch (error) {
                    setCartStatus(error.message || 'Gagal memuat keranjang.', 'text-danger');
                }
            }

            async function addProductToCart(product, source) {
                latestRequestId += 1;
                clearResults();
                if (searchInput) {
                    searchInput.value = '';
                }

                try {
                    const response = await jsonRequest(cartStoreLineEndpoint, 'POST', {
                        product_id: Number(product.id),
                        qty: 1,
                    });

                    if (!response) {
                        return;
                    }

                    renderCart(response.cart_snapshot || null);
                    clearResults();
                    if (searchInput) {
                        searchInput.focus();
                    }

                    if (source === 'auto') {
                        setSearchStatus('Produk ditambahkan otomatis dari barcode.', 'text-success');
                    } else {
                        setSearchStatus('Produk ditambahkan ke keranjang.', 'text-success');
                    }

                    setCartStatus('Keranjang berhasil diperbarui.', 'text-success');
                } catch (error) {
                    setCartStatus(error.message || 'Gagal menambahkan produk ke keranjang.', 'text-danger');
                }
            }

            function renderSearchResults(data) {
                clearResults();

                const results = Array.isArray(data.results) ? data.results : [];
                const autoSelectId = data.meta && data.meta.auto_select_product_id ? Number(data.meta.auto_select_product_id) : null;

                if (autoSelectId) {
                    const autoSelected = results.find((item) => Number(item.id) === autoSelectId);

                    if (autoSelected) {
                        addProductToCart(autoSelected, 'auto');
                        return;
                    }
                }

                if (results.length === 0) {
                    setSearchStatus('Produk tidak ditemukan.', 'text-muted');
                    return;
                }

                setSearchStatus('Pilih produk dari daftar.', 'text-muted');

                results.forEach((product) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action';

                    const productName = escapeHtml(product.product_name);
                    const productCode = escapeHtml(product.product_code || '-');
                    const barcode = escapeHtml(product.barcode || '-');
                    const availableQty = escapeHtml(product.available_qty);

                    button.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="font-weight-bold">${productName}</div>
                                <div class="small text-muted">${productCode} | ${barcode}</div>
                            </div>
                            <div class="text-right">
                                <div class="small text-muted">Stok: ${availableQty}</div>
                                <div class="small">${formatPrice(product.sale_price)}</div>
                            </div>
                        </div>
                    `;
                    button.addEventListener('click', function () {
                        addProductToCart(product, 'manual');
                    });

                    resultListElement.appendChild(button);
                });
            }

            async function executeSearch(query) {
                latestRequestId += 1;
                const requestId = latestRequestId;

                setSearchStatus('Mencari produk...', 'text-muted');

                const url = new URL(searchEndpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '10');

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Permintaan pencarian gagal.');
                    }

                    const data = await response.json();

                    if (requestId !== latestRequestId) {
                        return;
                    }

                    renderSearchResults(data);
                } catch (error) {
                    if (requestId !== latestRequestId) {
                        return;
                    }

                    clearResults();
                    setSearchStatus('Pencarian gagal. Coba lagi.', 'text-danger');
                }
            }

            searchInput.addEventListener('input', function (event) {
                const query = (event.target.value || '').trim();

                if (debounceHandle) {
                    clearTimeout(debounceHandle);
                }

                if (query.length === 0) {
                    latestRequestId += 1;
                    clearResults();
                    setSearchStatus('', 'text-muted');
                    return;
                }

                debounceHandle = setTimeout(function () {
                    executeSearch(query);
                }, 250);
            });

            if (customerSearchInput) {
                customerSearchInput.addEventListener('input', function (event) {
                    const query = (event.target.value || '').trim();

                    if (customerDebounceHandle) {
                        clearTimeout(customerDebounceHandle);
                    }

                    if (query.length === 0) {
                        latestCustomerRequestId += 1;
                        clearCustomerResults();
                        setCustomerStatus('', 'text-muted');
                        return;
                    }

                    customerDebounceHandle = setTimeout(function () {
                        executeCustomerSearch(query);
                    }, 250);
                });
            }

            if (customerClearButton) {
                customerClearButton.addEventListener('click', async function () {
                    try {
                        await updateCustomerSelection(null);
                        clearCustomerResults();
                        if (customerSearchInput) {
                            customerSearchInput.value = '';
                        }
                        setCustomerStatus('Menggunakan pelanggan walk-in default.', 'text-success');
                    } catch (error) {
                        setCustomerStatus(error.message || 'Gagal mengubah pelanggan.', 'text-danger');
                    }
                });
            }

            if (scanFeedbackButton) {
                scanFeedbackButton.addEventListener('click', function () {
                    searchInput.focus();
                    setSearchStatus('Mode pindai aktif. Arahkan scanner ke kolom pencarian.', 'text-success');
                });
            }

            if (clearCartButton) {
                clearCartButton.addEventListener('click', async function () {
                    try {
                        const response = await jsonRequest(cartClearEndpoint, 'DELETE');

                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Keranjang dikosongkan.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal mengosongkan keranjang.', 'text-danger');
                    }
                });
            }

            cartBody.addEventListener('change', async function (event) {
                const qtyInput = event.target.closest('.js-line-qty');
                if (!qtyInput) return;

                const row = qtyInput.closest('tr[data-line-id]');
                if (!row) return;

                const lineId = Number(row.getAttribute('data-line-id'));
                const newQty = Number(qtyInput.value || 0);
                const prevQty = Number(qtyInput.getAttribute('data-prev-qty') || 0);

                if (!Number.isFinite(newQty) || newQty < 1) {
                    qtyInput.value = prevQty;
                    setCartStatus('Qty harus minimal 1.', 'text-danger', true);
                    return;
                }

                if (newQty < prevQty) {
                    qtyInput.value = prevQty;
                    setCartStatus('Jumlah qty tidak dapat dikurangi.', 'text-danger', true);
                    return;
                }

                if (newQty === prevQty) {
                    return;
                }

                qtyInput.setAttribute('data-prev-qty', newQty);

                try {
                    const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', { qty: newQty });
                    if (!response) {
                        qtyInput.value = prevQty;
                        qtyInput.setAttribute('data-prev-qty', prevQty);
                        return;
                    }

                    renderCart(response.cart_snapshot || null);
                    setCartStatus('Qty berhasil diperbarui.', 'text-success');
                } catch (error) {
                    qtyInput.value = prevQty;
                    qtyInput.setAttribute('data-prev-qty', prevQty);
                    setCartStatus(error.message || 'Gagal memperbarui qty.', 'text-danger');
                }
            });

            cartBody.addEventListener('click', async function (event) {
                const button = event.target.closest('button');
                const row = event.target.closest('tr[data-line-id]');

                if (!button || !row) {
                    return;
                }

                const lineId = Number(row.getAttribute('data-line-id'));
                if (!Number.isFinite(lineId) || lineId <= 0) {
                    setCartStatus('Baris keranjang tidak valid.', 'text-danger');
                    return;
                }

                if (button.classList.contains('js-line-remove')) {
                    try {
                        const response = await jsonRequest(getLineEndpoint(lineId), 'DELETE');
                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Baris keranjang dihapus.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal menghapus baris keranjang.', 'text-danger');
                    }
                }
            });

            function generateIdempotencyKey() {
                return 'pos-' + Date.now() + '-' + Math.random().toString(36).substring(2, 15);
            }

            function renderReceiptPreview(snapshot) {
                if (!checkoutReceiptLines) return;

                const lines = snapshot && Array.isArray(snapshot.lines) ? snapshot.lines : [];

                if (lines.length === 0) {
                    checkoutReceiptLines.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Keranjang kosong.</td></tr>';
                } else {
                    checkoutReceiptLines.innerHTML = lines.map(line => {
                        const productName = escapeHtml(line.product_name || '-');
                        const qty = Number(line.qty || 0);
                        const unitPrice = formatPrice(line.unit_price || 0);
                        const total = formatPrice(line.line_total || 0);

                        return `
                            <tr>
                                <td>
                                    <div class="text-truncate" style="max-width: 15rem;" title="${productName}">${productName}</div>
                                </td>
                                <td class="text-right">${qty}</td>
                                <td class="text-right">${unitPrice}</td>
                                <td class="text-right font-weight-bold">${total}</td>
                            </tr>
                        `;
                    }).join('');
                }

                const totals = snapshot && snapshot.totals ? snapshot.totals : {};
                if (checkoutReceiptTotal) {
                    checkoutReceiptTotal.textContent = formatPrice(totals.grand_total || 0);
                }
            }

            function setPaymentMethod(method) {
                const normalizedMethod = ['cash', 'transfer', 'qris'].includes(method) ? method : 'cash';
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                const methodLabelMap = {
                    cash: 'TUNAI',
                    transfer: 'TRANSFER',
                    qris: 'QRIS',
                };

                checkoutMethodCode.value = normalizedMethod;
                checkoutMethodLabel.value = methodLabelMap[normalizedMethod] || normalizedMethod.toUpperCase();

                checkoutMethodButtons.forEach((button) => {
                    const buttonMethod = button.getAttribute('data-method');
                    const isActive = buttonMethod === normalizedMethod;
                    button.classList.toggle('active', isActive);
                    if (isActive) {
                        button.classList.remove('btn-outline-success', 'btn-outline-info', 'btn-outline-dark');
                        if (buttonMethod === 'cash') {
                            button.classList.add('btn-success');
                        } else if (buttonMethod === 'transfer') {
                            button.classList.add('btn-info');
                        } else {
                            button.classList.add('btn-dark');
                        }
                    } else {
                        button.classList.remove('btn-success', 'btn-info', 'btn-dark');
                        if (buttonMethod === 'cash') {
                            button.classList.add('btn-outline-success');
                        } else if (buttonMethod === 'transfer') {
                            button.classList.add('btn-outline-info');
                        } else {
                            button.classList.add('btn-outline-dark');
                        }
                    }
                });

                if (normalizedMethod === 'cash') {
                    checkoutAmountPaid.readOnly = false;
                    checkoutChangeWrapper.classList.remove('d-none');
                    checkoutReferenceWrapper.classList.add('d-none');
                    if (checkoutPresetsWrapper) checkoutPresetsWrapper.classList.remove('d-none');
                    checkoutAmountPaid.value = grandTotal.toFixed(2);
                    updateChange(grandTotal, grandTotal);
                } else {
                    checkoutAmountPaid.readOnly = true;
                    checkoutChangeWrapper.classList.add('d-none');
                    checkoutReferenceWrapper.classList.remove('d-none');
                    if (checkoutPresetsWrapper) checkoutPresetsWrapper.classList.add('d-none');
                    checkoutAmountPaid.value = grandTotal.toFixed(2);
                }
            }

            function openPaymentModal() {
                if (!currentSnapshot || !currentSnapshot.totals) return;

                const grandTotal = Number(currentSnapshot.totals.grand_total || 0);
                if (grandTotal <= 0) return;

                checkoutTotalLabel.value = formatPrice(grandTotal);
                checkoutReference.value = '';
                checkoutError.classList.add('d-none');
                checkoutError.textContent = '';

                renderReceiptPreview(currentSnapshot);
                setPaymentMethod('cash');

                $(checkoutModalElement).modal('show');
                setTimeout(() => checkoutAmountPaid.focus(), 200);
            }

            function updateChange(amountPaid, grandTotal) {
                const change = Math.max(0, amountPaid - grandTotal);
                checkoutChangeLabel.value = formatPrice(change);
            }

            if (checkoutMethodButtons.length > 0) {
                checkoutMethodButtons.forEach((button) => {
                    button.addEventListener('click', function () {
                        const method = String(this.getAttribute('data-method') || 'cash');
                        setPaymentMethod(method);
                    });
                });
            }

            if (checkoutPresetsWrapper) {
                checkoutPresetsWrapper.addEventListener('click', function (event) {
                    const target = event.target.closest('.js-preset-amount');
                    if (!target) {
                        return;
                    }

                    const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                    const dataAmount = target.getAttribute('data-amount');

                    let amountToFill = 0;
                    if (dataAmount === 'uang-pas') {
                        amountToFill = grandTotal;
                    } else {
                        amountToFill = Number(dataAmount);
                    }

                    checkoutAmountPaid.value = amountToFill.toFixed(2);
                    updateChange(amountToFill, grandTotal);
                });
            }

            if (checkoutAmountPaid) {
                checkoutAmountPaid.addEventListener('input', function () {
                    const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                    const amountPaid = Number(this.value || 0);
                    updateChange(amountPaid, grandTotal);
                });
            }

            if (btnCheckout) {
                btnCheckout.addEventListener('click', function () {
                    openPaymentModal();
                });
            }

            if (checkoutSubmit) {
                checkoutSubmit.addEventListener('click', async function () {
                    const method = checkoutMethodCode.value;
                    const amountPaid = Number(checkoutAmountPaid.value || 0);
                    const reference = checkoutReference.value.trim();
                    const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                    if (amountPaid < grandTotal && method === 'cash') {
                        checkoutError.textContent = 'Pembayaran tunai harus mencukupi total belanja.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    if (!reference && (method === 'transfer' || method === 'qris')) {
                        checkoutError.textContent = 'Referensi pembayaran wajib diisi untuk non-tunai.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    checkoutSubmit.disabled = true;
                    checkoutSubmit.textContent = 'Memproses...';
                    checkoutError.classList.add('d-none');

                    try {
                        const payload = {
                            idempotency_key: generateIdempotencyKey(),
                            payment: {
                                method_code: method,
                                amount_paid: amountPaid,
                                reference: reference || null
                            }
                        };

                        const response = await jsonRequest(finalizeEndpoint, 'POST', payload);
                        if (!response) {
                            checkoutSubmit.disabled = false;
                            checkoutSubmit.textContent = 'Konfirmasi Pembayaran';
                            return;
                        }

                        $(checkoutModalElement).modal('hide');

                        if (successReceiptElement) successReceiptElement.textContent = 'No. Struk: ' + (response.receipt_number || '-');
                        if (successChangeElement) {
                            const change = Number(response.change_total || 0);
                            successChangeElement.textContent = change > 0 ? 'Kembalian: ' + formatPrice(change) : '';
                        }

                        window.lastCheckoutId = response.pos_checkout_id;
                        if (shortcutReprintBtn) shortcutReprintBtn.disabled = false;

                        $('#pos-success-modal').modal('show');

                        renderCart(null);
                        setCartStatus('Transaksi berhasil diselesaikan.', 'text-success');
                    } catch (error) {
                        checkoutError.textContent = error.message || 'Gagal memproses pembayaran.';
                        checkoutError.classList.remove('d-none');
                    } finally {
                        checkoutSubmit.disabled = false;
                        checkoutSubmit.textContent = 'Konfirmasi Pembayaran';
                    }
                });
            }

            window.printReceipt = function () {
                if (window.lastCheckoutId) {
                    const url = `{{ url('/pos/sell/checkout') }}/${window.lastCheckoutId}/receipt`;
                    window.open(url, '_blank');
                }
            };

            if (shortcutReprintBtn) {
                shortcutReprintBtn.addEventListener('click', function () {
                    if (window.lastCheckoutId) {
                        const url = `{{ url('/pos/sell/checkout') }}/${window.lastCheckoutId}/receipt/reprint`;
                        window.open(url, '_blank');
                    }
                });
            }

            refreshCart();
        })();
    </script>
@endsection
