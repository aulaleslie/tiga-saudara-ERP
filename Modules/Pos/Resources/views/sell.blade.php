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
                                <label for="pos-customer-search" class="small font-weight-bold mb-1">Pelanggan <span class="text-danger">*</span></label>
                                <div class="pos-customer-search-anchor">
                                    <input id="pos-customer-search" type="text" class="form-control"
                                           placeholder="Cari nama / telepon pelanggan">
                                    <div id="pos-customer-search-results" class="list-group"></div>
                                </div>
                                <div class="d-flex mt-1" style="gap: 0.25rem;">
                                    <button id="pos-customer-create-btn" class="btn btn-sm btn-outline-primary btn-block" type="button">
                                        Tambah Baru
                                    </button>
                                </div>
                                <p id="pos-customer-resolution" class="small text-muted mt-2 mb-0"></p>
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
                                    <button id="pos-save-draft" class="btn btn-outline-primary btn-lg" type="button">
                                        Simpan dan Buka Baru
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
                                <!-- Phase 3D: Searchable dropdown instead of static buttons -->
                                <div class="position-relative">
                                    <input type="text" id="pos-checkout-method-search" class="form-control" 
                                           placeholder="Cari metode pembayaran..." autocomplete="off">
                                    <div id="pos-checkout-method-results" class="list-group position-absolute w-100" 
                                         style="top: 100%; left: 0; right: 0; z-index: 1000; max-height: 200px; overflow-y: auto; display: none;"></div>
                                </div>
                                <input type="hidden" id="pos-checkout-method-id" value="">
                                <input type="text" id="pos-checkout-method-label" class="form-control-plaintext font-weight-bold text-uppercase mt-2" readonly value="(Pilih Metode)">
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

    <!-- Modal Tambah Pelanggan Baru -->
    <div class="modal fade" id="pos-customer-create-modal" tabindex="-1" role="dialog" aria-labelledby="pos-customer-create-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form id="pos-customer-create-form">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pos-customer-create-modal-label">Tambah Pelanggan Baru</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div id="pos-customer-create-error" class="alert alert-danger d-none small"></div>
                        
                        <div class="form-group mb-3">
                            <label for="pos-new-customer-name" class="font-weight-bold">Nama Pelanggan <span class="text-danger">*</span></label>
                            <input type="text" id="pos-new-customer-name" class="form-control" placeholder="Masukkan nama pelanggan" required>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label for="pos-new-customer-phone" class="font-weight-bold">No. Telepon <span class="text-muted font-weight-normal">(Opsional)</span></label>
                            <input type="text" id="pos-new-customer-phone" class="form-control" placeholder="Masukkan nomor telepon">
                        </div>

                        <div class="form-group mb-0">
                            <label for="pos-new-customer-tier" class="font-weight-bold">Tier Pelanggan <span class="text-muted font-weight-normal">(Opsional)</span></label>
                            <select id="pos-new-customer-tier" class="form-control">
                                <!-- Options populated from Constants -->
                                @foreach(\App\Constants\CustomerTier::options() as $val => $label)
                                    <option value="{{ $val }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light p-3">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" id="pos-customer-create-submit" class="btn btn-primary d-inline-flex align-items-center">
                            <span class="spinner-border spinner-border-sm mr-2 d-none" role="status" aria-hidden="true" id="pos-customer-create-spinner"></span>
                            Simpan Pelanggan
                        </button>
                    </div>
                </form>
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
            const customerCreateButton = document.getElementById('pos-customer-create-btn');
            const customerResolutionElement = document.getElementById('pos-customer-resolution');
            const customerStatusElement = document.getElementById('pos-customer-action-status');

            const customerCreateModal = document.getElementById('pos-customer-create-modal');
            const customerCreateForm = document.getElementById('pos-customer-create-form');
            const customerCreateError = document.getElementById('pos-customer-create-error');
            const customerCreateSubmit = document.getElementById('pos-customer-create-submit');
            const customerCreateSpinner = document.getElementById('pos-customer-create-spinner');
            const newCustomerName = document.getElementById('pos-new-customer-name');
            const newCustomerPhone = document.getElementById('pos-new-customer-phone');
            const newCustomerTier = document.getElementById('pos-new-customer-tier');

            const btnCheckout = document.getElementById('pos-checkout-final');

            const checkoutModalElement = document.getElementById('pos-checkout-modal');
            const checkoutMethodLabel = document.getElementById('pos-checkout-method-label');
            const checkoutMethodId = document.getElementById('pos-checkout-method-id');
            const checkoutMethodSearch = document.getElementById('pos-checkout-method-search');
            const checkoutMethodResults = document.getElementById('pos-checkout-method-results');
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
            const scanResolveEndpoint = @json(url('/pos/sell/search/resolve'));
            const customerSearchEndpoint = @json(route('pos.sell.customers.search'));
            const cartShowEndpoint = @json(route('pos.sell.cart.show'));
            const cartStoreLineEndpoint = @json(route('pos.sell.cart.lines.store'));
            const cartClearEndpoint = @json(route('pos.sell.cart.clear'));
            const cartCustomerEndpoint = @json(route('pos.sell.cart.customer.update'));
            const customerStoreEndpoint = @json(route('pos.sell.customers.store'));
            const paymentMethodSearchEndpoint = @json(url('/pos/sell/payment-methods/search'));
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
            let cachedPaymentMethods = [];
            let selectedPaymentMethod = null;

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
                const selectedTier = selected && selected.tier ? selected.tier : null;
                const resolutionSource = customer.resolution_source || 'unresolved';

                if (resolutionSource === 'selected') {
                    // Phase 3C: Make selected customer display prominent
                    const tierBadge = selectedTier ? `<span class="badge badge-primary ml-2">${escapeHtml(selectedTier)}</span>` : '';
                    customerResolutionElement.innerHTML = `
                        <div class="card p-2 bg-light border-primary">
                            <div class="font-weight-bold" style="font-size: 1.1rem;">${escapeHtml(selectedName || 'Pelanggan terpilih')}${tierBadge}</div>
                            ${selectedPhone ? '<div class="small text-muted">' + escapeHtml(selectedPhone) + '</div>' : ''}
                        </div>
                    `;
                    return;
                }

                customerResolutionElement.innerHTML = '<div class="text-muted small">Belum ada pelanggan dipilih.</div>';
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
                    // Phase 3C: Increase customer suggestion item size
                    button.className = 'list-group-item list-group-item-action list-group-item-light py-2 px-3';

                    const displayName = escapeHtml(customer.display_name || customer.customer_name || '-');
                    const phone = escapeHtml(customer.customer_phone || '-');

                    button.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="font-weight-bold">${displayName}</div>
                                <div class="small text-muted">${phone}</div>
                            </div>
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
                const priceValid = line.price_valid !== false;
                const priceError = escapeHtml(line.price_error || '');

                // Phase 3B: Different rendering for serial vs non-serial lines
                let qtyCell = '';
                if (line.serial_number_required === true) {
                    // Serial line: editable qty + serial management
                    const assignedCount = Array.isArray(line.assigned_serials) ? line.assigned_serials.length : 0;
                    const serialChips = (line.assigned_serials || []).map(serial => `
                        <span class="badge badge-info mr-1" style="font-size: 0.75rem;">
                            ${escapeHtml(serial)}
                            <button type="button" class="btn btn-link p-0 ml-1 text-white js-serial-remove" 
                                    data-serial="${escapeHtml(serial)}" 
                                    style="font-size: 0.65rem; text-decoration: none; margin-left: 4px !important;">×</button>
                        </span>
                    `).join('');

                    qtyCell = `
                        <td class="pos-cart-serial-cell align-top" style="vertical-align: top; min-width: 200px;">
                            <div class="mb-2">
                                <div class="d-flex gap-1 align-items-center mb-2">
                                    <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty" 
                                           type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                           style="width: 60px;">
                                    <button type="button" class="btn btn-sm btn-outline-info js-serial-add" data-line-id="${lineId}">
                                        + Serial
                                    </button>
                                </div>
                                <small class="text-muted">${assignedCount} / ${qty} serial</small>
                            </div>
                            <div>${serialChips}</div>
                        </td>
                    `;
                } else {
                    // Non-serial line: read-only qty as badge/text
                    qtyCell = `
                        <td class="text-center align-middle">
                            <span class="badge badge-secondary" style="font-size: 0.9rem; padding: 0.4rem 0.6rem;">
                                ${qty}
                            </span>
                        </td>
                    `;
                }

                // Phase 3B: Price validity indicator
                const priceWarning = !priceValid ? `<div class="text-warning small font-weight-bold mb-1">⚠ ${priceError}</div>` : '';
                const rowClass = !priceValid ? 'bg-warning-light' : '';

                return `
                    <tr data-line-id="${lineId}" class="${rowClass}">
                        <td class="pos-cart-product align-middle">
                            ${priceWarning}
                            <div class="name">${productName}${serialBadge}</div>
                            <div class="meta">${productCode} | ${barcode}</div>
                            <div class="meta">Stok: ${availableQty}</div>
                        </td>
                        <td class="text-right align-middle">${formatPrice(line.unit_price || 0)}</td>
                        ${qtyCell}
                        <td class="text-right align-middle" style="vertical-align: top;">
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

                // Phase 3B: Enhanced checkout button guards
                const grandTotal = snapshot && snapshot.totals ? Number(snapshot.totals.grand_total || 0) : 0;
                const hasItems = snapshot && Array.isArray(snapshot.lines) && snapshot.lines.length > 0;
                const customer = snapshot && snapshot.customer ? snapshot.customer : {};
                const hasCustomer = customer.resolution_source === 'selected' || customer.resolution_source === 'default';
                
                // Check for price validity
                const allPricesValid = !snapshot || !Array.isArray(snapshot.lines) || 
                    snapshot.lines.every(line => line.price_valid !== false);
                
                // Check for serial count matching
                const allSerialsValid = !snapshot || !Array.isArray(snapshot.lines) ||
                    snapshot.lines.every(line => {
                        if (line.serial_number_required !== true) {
                            return true; // Non-serial lines are always valid
                        }
                        const assignedCount = Array.isArray(line.assigned_serials) ? line.assigned_serials.length : 0;
                        return assignedCount === line.qty;
                    });

                const canCheckout = hasItems && grandTotal > 0 && hasCustomer && allPricesValid && allSerialsValid;

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

            // Phase 3A: Handle serial scan result - append serial to cart line
            async function handleSerialScanResult(result) {
                const product = result.product;
                const serial = result.serial;

                if (!currentSnapshot || !Array.isArray(currentSnapshot.lines)) {
                    // If no cart, add product first then append serial
                    await addProductToCart(product, 'scan');
                    // After product is added, the snapshot is updated, so find the new line
                    if (currentSnapshot && Array.isArray(currentSnapshot.lines)) {
                        const newLine = currentSnapshot.lines.find(line => line.product_id === product.id);
                        if (newLine) {
                            await appendSerialToLine(newLine.line_id, serial.serial_number);
                        }
                    }
                    return;
                }

                // Try to find an existing line for this product with unfilled serial slots
                let targetLine = null;
                for (const line of currentSnapshot.lines) {
                    if (line.product_id === product.id && 
                        line.serial_number_required === true &&
                        (line.assigned_serials.length < line.qty)) {
                        targetLine = line;
                        break;
                    }
                }

                if (!targetLine) {
                    // No existing line with unfilled slots, add product first
                    await addProductToCart(product, 'scan');
                    if (currentSnapshot && Array.isArray(currentSnapshot.lines)) {
                        const newLine = currentSnapshot.lines.find(line => line.product_id === product.id);
                        if (newLine) {
                            await appendSerialToLine(newLine.line_id, serial.serial_number);
                        }
                    }
                } else {
                    // Found existing line with space, append serial to it
                    await appendSerialToLine(targetLine.line_id, serial.serial_number);
                }
            }

            // Phase 3A: Append serial to a cart line
            async function appendSerialToLine(lineId, serialNumber) {
                try {
                    const url = cartLinesBaseUrl + '/' + lineId + '/serials/append';
                    const response = await jsonRequest(url, 'POST', { serial_number: serialNumber });
                    if (response && response.cart_snapshot) {
                        renderCart(response.cart_snapshot);
                    }
                } catch (error) {
                    setCartStatus('Gagal menambahkan serial: ' + (error.message || 'Server error'), 'text-danger', true);
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

            // Phase 3A: Add Enter key handler for scan resolver
            searchInput.addEventListener('keydown', async function (event) {
                if (event.key !== 'Enter' && event.code !== 'Enter') {
                    return;
                }
                event.preventDefault();
                const query = (this.value || '').trim();
                if (!query) {
                    setSearchStatus('Masukkan kode produk atau nomor serial.', 'text-muted');
                    return;
                }

                clearResults();
                setSearchStatus('Memindai...', 'text-muted');

                try {
                    const response = await jsonRequest(scanResolveEndpoint, 'POST', { q: query });
                    if (!response) {
                        setSearchStatus('Pindai gagal.', 'text-danger');
                        return;
                    }

                    if (response.type === 'product_exact') {
                        await addProductToCart(response.product, 'scan');
                        searchInput.value = '';
                        setSearchStatus('Produk ditambahkan ke keranjang.', 'text-success');
                    } else if (response.type === 'serial_exact') {
                        await handleSerialScanResult(response);
                        searchInput.value = '';
                        setSearchStatus('Serial berhasil ditambahkan.', 'text-success');
                    } else if (response.type === 'ambiguous') {
                        // Run normal search to show suggestions
                        executeSearch(query);
                        setSearchStatus('Pilih produk dari daftar.', 'text-muted');
                    } else {
                        setSearchStatus('Produk tidak ditemukan.', 'text-muted');
                    }
                } catch (error) {
                    setSearchStatus('Pindai gagal: ' + (error.message || 'Server error'), 'text-danger');
                }
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

            if (customerCreateButton) {
                customerCreateButton.addEventListener('click', function () {
                    if (customerCreateError) customerCreateError.classList.add('d-none');
                    if (newCustomerName) newCustomerName.value = '';
                    if (newCustomerPhone) newCustomerPhone.value = '';
                    if (newCustomerTier) newCustomerTier.selectedIndex = 0;
                    $(customerCreateModal).modal('show');
                });
            }

            if (customerCreateForm) {
                customerCreateForm.addEventListener('submit', async function (e) {
                    e.preventDefault();

                    const name = (newCustomerName ? newCustomerName.value : '').trim();
                    if (!name) {
                        if (customerCreateError) {
                            customerCreateError.textContent = 'Nama pelanggan wajib diisi.';
                            customerCreateError.classList.remove('d-none');
                        }
                        return;
                    }

                    if (customerCreateSubmit) customerCreateSubmit.disabled = true;
                    if (customerCreateSpinner) customerCreateSpinner.classList.remove('d-none');
                    if (customerCreateError) customerCreateError.classList.add('d-none');

                    try {
                        const payload = {
                            customer_name: name,
                            customer_phone: newCustomerPhone ? newCustomerPhone.value.trim() || null : null,
                            tier: newCustomerTier ? newCustomerTier.value || null : null
                        };

                        const response = await jsonRequest(customerStoreEndpoint, 'POST', payload);
                        if (!response) return;

                        const newId = response && response.id ? response.id : null;
                        if (newId) {
                            await updateCustomerSelection(newId);
                            if (customerSearchInput) customerSearchInput.value = response.display_name || name;
                            setCustomerStatus('Pelanggan baru berhasil ditambahkan dan dipilih.', 'text-success');
                        }

                        $(customerCreateModal).modal('hide');
                    } catch (error) {
                        if (customerCreateError) {
                            customerCreateError.textContent = error.message || 'Gagal menyimpan pelanggan.';
                            customerCreateError.classList.remove('d-none');
                        }
                    } finally {
                        if (customerCreateSubmit) customerCreateSubmit.disabled = false;
                        if (customerCreateSpinner) customerCreateSpinner.classList.add('d-none');
                    }
                });
            }

            // Siap Pindai button removed in Phase 3A - Enter key now handles scan resolution

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

            // Phase 3B: Add serial chip event handlers
            cartBody.addEventListener('click', async function (event) {
                // Handle + Serial button click
                const addSerialBtn = event.target.closest('.js-serial-add');
                if (addSerialBtn) {
                    const lineId = Number(addSerialBtn.getAttribute('data-line-id'));
                    const serialInput = prompt('Masukkan nomor serial:');
                    if (serialInput && serialInput.trim()) {
                        await appendSerialToLine(lineId, serialInput.trim());
                    }
                    return;
                }

                // Handle serial chip remove button click
                const removeSerialBtn = event.target.closest('.js-serial-remove');
                if (removeSerialBtn) {
                    const row = event.target.closest('tr[data-line-id]');
                    if (!row) return;
                    
                    const lineId = Number(row.getAttribute('data-line-id'));
                    const serialNumber = removeSerialBtn.getAttribute('data-serial');
                    
                    if (lineId && serialNumber) {
                        try {
                            const url = cartLinesBaseUrl + '/' + lineId + '/serials/' + encodeURIComponent(serialNumber);
                            const response = await jsonRequest(url, 'DELETE');
                            if (response && response.cart_snapshot) {
                                renderCart(response.cart_snapshot);
                                setCartStatus('Serial berhasil dihapus.', 'text-success');
                            }
                        } catch (error) {
                            setCartStatus('Gagal menghapus serial: ' + (error.message || 'Server error'), 'text-danger', true);
                        }
                    }
                    return;
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

            // Phase 3D: Load payment methods from API
            async function loadPaymentMethods() {
                try {
                    const response = await jsonRequest(paymentMethodSearchEndpoint, 'GET');
                    if (response && Array.isArray(response.methods)) {
                        cachedPaymentMethods = response.methods;
                        return true;
                    }
                } catch (error) {
                    console.error('Failed to load payment methods:', error);
                }
                return false;
            }

            // Phase 3D: Render payment method search results
            function renderPaymentMethodResults(results) {
                if (!checkoutMethodResults) return;
                
                checkoutMethodResults.innerHTML = '';
                if (results.length === 0) {
                    checkoutMethodResults.style.display = 'none';
                    return;
                }

                results.forEach(method => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action';
                    button.innerHTML = `<div class="font-weight-bold">${escapeHtml(method.name)}</div>`;
                    button.addEventListener('click', function () {
                        selectPaymentMethod(method);
                    });
                    checkoutMethodResults.appendChild(button);
                });

                checkoutMethodResults.style.display = 'block';
            }

            // Phase 3D: Select a specific payment method
            function selectPaymentMethod(method) {
                selectedPaymentMethod = method;
                checkoutMethodId.value = method.id || '';
                checkoutMethodLabel.value = escapeHtml(method.name || 'Unknown');
                checkoutMethodSearch.value = '';
                
                if (checkoutMethodResults) {
                    checkoutMethodResults.style.display = 'none';
                }

                // Use the method's is_cash flag to toggle UI
                const isCash = method.is_cash === true;
                const requiresReference = method.requires_reference === true;
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                if (isCash) {
                    checkoutAmountPaid.readOnly = false;
                    checkoutChangeWrapper.classList.remove('d-none');
                    checkoutReferenceWrapper.classList.add('d-none');
                    if (checkoutPresetsWrapper) checkoutPresetsWrapper.classList.remove('d-none');
                    checkoutAmountPaid.value = grandTotal.toFixed(2);
                    updateChange(grandTotal, grandTotal);
                } else {
                    checkoutAmountPaid.readOnly = true;
                    checkoutChangeWrapper.classList.add('d-none');
                    if (requiresReference) {
                        checkoutReferenceWrapper.classList.remove('d-none');
                    } else {
                        checkoutReferenceWrapper.classList.add('d-none');
                    }
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
                
                // Phase 3D: Load payment methods before showing modal
                (async () => {
                    const loaded = await loadPaymentMethods();
                    if (loaded && cachedPaymentMethods.length > 0) {
                        // Auto-select first method or first cash method
                        const firstMethod = cachedPaymentMethods.find(m => m.is_cash === true) || cachedPaymentMethods[0];
                        if (firstMethod) {
                            selectPaymentMethod(firstMethod);
                        }
                    }
                })();

                $(checkoutModalElement).modal('show');
                setTimeout(() => checkoutMethodSearch.focus(), 200);
            }

            function updateChange(amountPaid, grandTotal) {
                const change = Math.max(0, amountPaid - grandTotal);
                checkoutChangeLabel.value = formatPrice(change);
            }

            // Phase 3D: Add payment method search input handler
            if (checkoutMethodSearch) {
                checkoutMethodSearch.addEventListener('input', function () {
                    const query = (this.value || '').trim().toLowerCase();
                    
                    if (query.length === 0) {
                        renderPaymentMethodResults(cachedPaymentMethods);
                        return;
                    }

                    const filtered = cachedPaymentMethods.filter(method => 
                        (method.name || '').toLowerCase().includes(query)
                    );
                    renderPaymentMethodResults(filtered);
                });

                checkoutMethodSearch.addEventListener('focus', function () {
                    if (cachedPaymentMethods.length > 0) {
                        renderPaymentMethodResults(cachedPaymentMethods);
                    }
                });
            }

            if (checkoutMethodResults) {
                checkoutMethodResults.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                });
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (checkoutMethodResults && 
                    !checkoutMethodResults.contains(e.target) && 
                    checkoutMethodSearch && 
                    !checkoutMethodSearch.contains(e.target)) {
                    checkoutMethodResults.style.display = 'none';
                }
            });

            if (checkoutMethodButtons.length > 0) {
                // Phase 3D: Old static button handlers removed - replaced with dynamic dropdown
                // checkoutMethodButtons.forEach((button) => {
                //     button.addEventListener('click', function () {
                //         const method = String(this.getAttribute('data-method') || 'cash');
                //         setPaymentMethod(method);
                //     });
                // });
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
                    // Phase 3D: Use selectedPaymentMethod for validation and payload
                    if (!selectedPaymentMethod) {
                        checkoutError.textContent = 'Pilih metode pembayaran terlebih dahulu.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    const method = selectedPaymentMethod;
                    const amountPaid = Number(checkoutAmountPaid.value || 0);
                    const reference = checkoutReference.value.trim();
                    const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                    // Phase 3D: Validation using is_cash flag
                    if (method.is_cash === true && amountPaid < grandTotal) {
                        checkoutError.textContent = 'Pembayaran ' + escapeHtml(method.name) + ' harus mencukupi total belanja.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    // Phase 3D: Validation using requires_reference flag
                    if (method.requires_reference === true && !reference) {
                        checkoutError.textContent = 'Referensi pembayaran wajib diisi untuk ' + escapeHtml(method.name) + '.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    checkoutSubmit.disabled = true;
                    checkoutSubmit.textContent = 'Memproses...';
                    checkoutError.classList.add('d-none');

                    try {
                        // Phase 5a: Send only payment_method_id in request
                        const payload = {
                            idempotency_key: generateIdempotencyKey(),
                            payment: {
                                payment_method_id: method.id,
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
