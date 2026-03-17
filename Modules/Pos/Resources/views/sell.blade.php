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
        width: 55px !important;
        margin: 0 !important;
        padding: 0.25rem 0.375rem !important;
        flex: 0 0 auto;
    }

    /* Phase 4: Serial UI Refinement */
    .pos-serial-wrapper {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        align-items: center;
    }

    .pos-serial-action {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }

    .pos-serial-action .bi {
        font-size: 0.85rem;
    }

    .pos-serial-action-label {
        font-size: 0.64rem;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .pos-serial-chip {
        display: inline-flex;
        align-items: center;
        background-color: #17a2b8;
        color: white;
        padding: 1px 6px;
        border-radius: 4px;
        font-size: 0.725rem;
        font-weight: 500;
        line-height: normal;
        border: 1px solid rgba(0,0,0,0.1);
    }

    .pos-serial-chip span {
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .pos-serial-chip .js-serial-remove {
        background: transparent;
        border: none;
        color: rgba(255,255,255,0.8);
        padding: 0;
        margin-left: 5px;
        font-size: 1rem;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: color 0.15s;
    }

    .pos-serial-chip .js-serial-remove:hover {
        color: #fff;
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

    /* Quantity Reduction Button */
    .pos-qty-reduce-btn {
        padding: 0.25rem 0.4rem;
        font-size: 0.8rem;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        min-height: 28px;
        white-space: nowrap;
    }

    .pos-qty-reduce-btn .bi {
        font-size: 0.9rem;
    }

    /* Task 3.1: Shared qty control strip with stable slot width across reduce/periksa/approved states */
    .pos-qty-control-strip {
        /* Reserve fixed minimum space for left slot to prevent jitter when button text/width changes */
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* Qty reduce/check buttons - square 32x32 for consistency everywhere */
    .pos-qty-reduce-btn {
        flex: 0 0 auto;
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        min-height: 32px !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 0.75rem !important;
        border-radius: 0.25rem !important;
        line-height: 1 !important;
    }

    .pos-qty-control-strip .pos-qty-reduce-btn {
        /* Ensure flex alignment in strip context */
        flex: 0 0 auto;
    }

    /* Task 2.1-2.3: Spinner button styling with semantic colors and fill-on-hover */
    .pos-qty-control-strip .btn.btn-sm.btn-outline-danger.js-qty-decrease,
    .pos-qty-control-strip .btn.btn-sm.btn-outline-primary.js-qty-increase {
        flex: 0 0 auto;
        width: 32px !important;
        height: 32px !important;
        padding: 0 !important;
        min-width: 32px !important;
        min-height: 32px !important;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.875rem;
        border-radius: 0.25rem !important;
        transition: all 0.15s ease-in-out;
    }

    .btn.btn-sm.btn-outline-danger.js-qty-decrease {
        color: #dc3545 !important;
        border-color: #dc3545 !important;
    }

    .btn.btn-sm.btn-outline-danger.js-qty-decrease:hover,
    .btn.btn-sm.btn-outline-danger.js-qty-decrease:focus {
        background-color: #dc3545 !important;
        color: #fff !important;
        border-color: #dc3545 !important;
    }

    .btn.btn-sm.btn-outline-primary.js-qty-increase {
        color: #007bff !important;
        border-color: #007bff !important;
    }

    .btn.btn-sm.btn-outline-primary.js-qty-increase:hover,
    .btn.btn-sm.btn-outline-primary.js-qty-increase:focus {
        background-color: #007bff !important;
        color: #fff !important;
        border-color: #007bff !important;
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

    #pos-customer-search-results .list-group-item {
        padding: 0.45rem 0.55rem;
        line-height: 1.35;
        font-size: 0.79rem;
        border-left: 0;
        border-right: 0;
    }

    #pos-customer-search-results .list-group-item:first-child {
        border-top: 0;
    }

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

        #pos-customer-search-results {
            max-height: 42dvh;
        }
    }

    /* Phase 3: Search card grid layout */
    .pos-search-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 0.75rem;
        padding: 1rem;
    }

    .pos-search-card {
        display: block;
        width: 100%;
        text-align: left;
        padding: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        background: white;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.875rem;
    }

    .pos-search-card:hover {
        border-color: #007bff;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        background-color: #f8f9ff;
    }

    .pos-search-card:focus {
        outline: 2px solid #007bff;
        outline-offset: -1px;
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
        $posTransactionsEnabled = (bool) (settings()->pos_transactions_enabled ?? false);
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
                                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="pos-nav-menu-dropdown"
                                         data-session-id="{{ $activeSession->id }}"
                                         data-terminal-code="{{ $activeSession->terminal->code ?? '-' }}"
                                         data-terminal-name="{{ $activeSession->terminal->name ?? '-' }}"
                                         data-cashier-name="{{ $activeSession->cashier->name ?? '-' }}"
                                         data-expected-cash="{{ $activeSession->expected_cash_total ?? 0 }}">
                                        <button type="button" id="pos-shortcut-reprint" class="dropdown-item" disabled>Reprint</button>

                                        @can('pos.reports.access')
                                            <a href="{{ route('pos.reports.index') }}" target="_blank" class="dropdown-item">Lap. Sales</a>
                                        @endcan
                                        @can('saleReturns.access')
                                            <a href="{{ route('sale-returns.index') }}" target="_blank" class="dropdown-item">Retur</a>
                                        @endcan

                                        @if(auth()->user()->canAny(['pos.sessions.view', 'pos.reconciliation.access', 'pos.terminals.access']))
                                            <div class="dropdown-divider"></div>
                                        @endif

                                        @can('pos.sessions.view')
                                            <a class="dropdown-item" href="{{ route('pos.sessions.index') }}" target="_blank">Sesi POS</a>
                                        @endcan
                                        @if($posTransactionsEnabled && auth()->user()->can('pos.transactions.view'))
                                            <a class="dropdown-item" href="{{ route('pos.transactions.index') }}" target="_blank">Transaksi POS</a>
                                        @endif
                                        @can('pos.reconciliation.access')
                                            <a class="dropdown-item" href="{{ route('pos.reconciliation.index') }}" target="_blank">Rekonsiliasi</a>
                                        @endcan
                                        @can('pos.terminals.access')
                                            <a class="dropdown-item" href="{{ route('pos.terminals.index') }}" target="_blank">Kelola Terminal</a>
                                        @endcan
                                        @can('pos.supervisor.approval')
                                            <a class="dropdown-item" href="{{ route('pos.supervisor.approval-requests.index') }}" target="_blank">Antrian Persetujuan</a>
                                        @endcan
                                        <div class="dropdown-divider"></div>
                                        @can('pos.sessions.close')
                                            <button type="button" id="pos-close-session-btn" class="dropdown-item">Tutup Sesi</button>
                                        @endcan
                                        <button type="button" id="pos-cash-pickup-btn" class="dropdown-item">Pengambilan Kas</button>
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
                            <h5 class="pos-section-title">Pindai Produk</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-search-grid">
                                <div class="pos-search-head">
                                    <div class="pos-search-main">
                                        <label for="pos-shell-search" class="small font-weight-bold">Pindai Barcode / Serial</label>
                                        <input id="pos-shell-search" type="text" class="form-control"
                                               placeholder="Pindai barcode atau ketik nomor serial"
                                               autocomplete="off">
                                    </div>
                                    <button id="pos-btn-cari-produk" type="button" class="btn btn-outline-primary">
                                        Cari Produk
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
                                            <th class="text-center">Aksi</th>
                                        </tr>
                                        </thead>
                                        <tbody id="pos-shell-cart-body">
                                        <tr id="pos-shell-cart-empty-row">
                                            <td colspan="5" class="text-muted text-center py-4">Keranjang kosong.</td>
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
                                    @if($posTransactionsEnabled && auth()->user()->can('pos.transactions.save'))
                                        <button id="pos-save-draft" class="btn btn-outline-primary btn-lg" type="button">
                                            Simpan dan Buka Baru
                                        </button>
                                    @else
                                        <button class="btn btn-outline-primary btn-lg" type="button" disabled
                                                title="Membutuhkan izin simpan transaksi POS.">
                                            Simpan dan Buka Baru
                                        </button>
                                    @endif
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

                            <!-- Task 4.1: Payment Composer Section -->
                            <div class="form-group mb-3">
                                <label class="font-weight-bold d-block mb-2">Metode Pembayaran</label>
                                <!-- Payment method search/picker -->
                                <div class="position-relative">
                                    <input type="text" id="pos-checkout-method-search" class="form-control"
                                           placeholder="Cari metode pembayaran..." autocomplete="off">
                                    <div id="pos-checkout-method-results" class="list-group position-absolute w-100"
                                         style="top: 100%; left: 0; right: 0; z-index: 1000; max-height: 200px; overflow-y: auto; display: none;"></div>
                                </div>
                            </div>

                            <!-- Task 4.1: Payment rows list -->
                            <div id="pos-checkout-payments-list" class="mb-3" style="max-height: 250px; overflow-y: auto;">
                                <!-- Payment rows rendered here -->
                            </div>

                            <!-- Task 4.1: Order Summary -->
                            <div class="form-group row mb-2">
                                <label class="col-sm-5 col-form-label font-weight-bold">Total Belanja</label>
                                <div class="col-sm-7">
                                    <input type="text" id="pos-checkout-total-label" class="form-control-plaintext font-weight-bold text-primary" readonly value="Rp0">
                                </div>
                            </div>

                            <div class="form-group row mb-2">
                                <label class="col-sm-5 col-form-label font-weight-bold">Total Dibayar</label>
                                <div class="col-sm-7">
                                    <input type="text" id="pos-checkout-amount-paid-summary" class="form-control-plaintext font-weight-bold text-info" readonly value="Rp0">
                                </div>
                            </div>

                            <div class="form-group row mb-2 bg-light p-2 rounded">
                                <label class="col-sm-5 col-form-label font-weight-bold">Sisa / Kembalian</label>
                                <div class="col-sm-7">
                                    <input type="text" id="pos-checkout-remaining-label" class="form-control-plaintext font-weight-bold h5 mb-0 text-right" readonly value="Rp0">
                                </div>
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

    <!-- Task 3.1 & 3.2: Staged Payment Modal - Multi-stage sequential payment flow -->
    <div class="modal fade" id="pos-staged-checkout-modal" tabindex="-1" role="dialog" aria-labelledby="pos-staged-checkout-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-staged-checkout-modal-label">Pembayaran Bertahap</h5>
                    <button type="button" id="staged-payment-close-btn" class="close" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <!-- Error Alert -->
                    <div id="staged-payment-error" class="alert alert-danger d-none"></div>

                    <!-- Task 3.3: Payment Chain Display -->
                    <div class="mb-4">
                        <label class="small font-weight-bold text-muted d-block mb-2">Pembayaran Sudah Diproses</label>
                        <div id="staged-payment-chain" class="d-flex flex-wrap gap-2">
                            <p class="text-muted small mb-0">Belum ada pembayaran</p>
                        </div>
                    </div>

                    <!-- Remainder Display -->
                    <div class="alert alert-info mb-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="font-weight-bold">Sisa Pembayaran:</span>
                            <span id="staged-remainder-amount" class="h4 mb-0 text-danger">Rp0</span>
                        </div>
                    </div>

                    <!-- Task 3.2: Payment Method Selection -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold d-block mb-2">Metode Pembayaran</label>
                        <div class="position-relative">
                            <!-- Task 1.1: Ensure payment method input has opaque white background with visible border
                                 Uses !important to override Bootstrap modal CSS that may render background as transparent.
                                 This ensures cashiers can clearly see and interact with the payment method dropdown. -->
                            <input type="text" id="staged-method-search" class="form-control"
                                   style="background-color: #fff !important; border: 1px solid #dee2e6 !important; color: #212529 !important;"
                                   placeholder="Pilih atau cari metode pembayaran..." autocomplete="off">
                            <div id="staged-method-results" class="list-group position-absolute w-100"
                                 style="top: 100%; left: 0; right: 0; z-index: 1000; max-height: 250px; overflow-y: auto; display: none; background-color: #fff !important; border: 1px solid #dee2e6 !important;"></div>
                        </div>
                    </div>

                    <!-- Amount Input -->
                    <div class="form-group mb-3">
                        <label for="staged-amount-input" class="font-weight-bold">Jumlah Pembayaran (Rp)</label>
                        <input type="text" id="staged-amount-input" class="form-control form-control-lg"
                               placeholder="0" inputmode="numeric">
                    </div>

                    <!-- Quick-Add Buttons -->
                    <div class="mb-3 d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="1000">+1.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="5000">+5.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="10000">+10.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="50000">+50.000</button>
                        <button type="button" class="btn btn-sm btn-warning js-quick-add-remainder">Sisa</button>
                    </div>

                    <!-- Task 3.5 & 3.6: EDC Reference Input (Conditional) -->
                    <div id="staged-edc-reference-container" class="form-group mb-3" style="display: none;">
                        <label for="staged-edc-reference" class="font-weight-bold">Nomor Referensi EDC</label>
                        <input type="text" id="staged-edc-reference" class="form-control"
                               placeholder="Masukkan nomor referensi (alphanumeric, max 20 karakter)"
                               maxlength="20">
                        <small class="form-text text-muted">Format: Alphanumeric, maksimal 20 karakter</small>
                    </div>

                    <!-- Processing Spinner -->
                    <div id="staged-payment-spinner" class="text-center" style="display: none;">
                        <div class="spinner-border mb-3" role="status">
                            <span class="sr-only">Memproses...</span>
                        </div>
                        <p class="text-muted">Memproses pembayaran... Jangan tutup atau muat ulang halaman.</p>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-lg" data-dismiss="modal">Batal</button>
                    <button type="button" id="staged-payment-submit" class="btn btn-primary btn-lg px-5" disabled>
                        Lanjut Pembayaran
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Task 6.4 & 6.5: Gratitude Modal -->
    <div class="modal fade" id="pos-gratitude-modal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
            <div class="modal-content text-center py-5">
                <div class="modal-body">
                    <div class="mb-4">
                        <i class="fas fa-hand-holding-heart text-primary fa-5x"></i>
                    </div>
                    <h4 class="mb-3 font-weight-bold">Jangan Lupa Ucapkan Terima Kasih!</h4>
                    <p id="gratitude-change-amount" class="h5 mb-4 text-success"></p>
                    <button type="button" id="pos-gratitude-continue-btn" class="btn btn-primary btn-lg btn-block">
                        Lanjut Jualan
                    </button>
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
                </div>
            </div>
        </div>
    </div>

    <!-- Search Results Modal (Phase 1-3) -->
    <div class="modal fade" id="pos-search-results-modal" tabindex="-1" role="dialog" aria-labelledby="pos-search-results-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-search-results-modal-label">Cari Produk</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body d-flex flex-column" style="max-height: 70vh; padding: 0;">
                    <!-- Phase 3: In-modal search input -->
                    <div class="p-3 border-bottom flex-shrink-0">
                        <div class="input-group">
                            <input id="pos-modal-search-input" type="text" class="form-control" 
                                   placeholder="Cari nama produk atau SKU..." 
                                   autocomplete="off">
                            <div class="input-group-append">
                                <button id="pos-modal-search-btn" class="btn btn-primary" type="button">Cari</button>
                            </div>
                        </div>
                    </div>
                    <!-- Phase 3: Card-grid results container -->
                    <div id="pos-search-modal-results" class="pos-search-card-grid flex-grow-1 overflow-auto"></div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Serial Input Modal (Phase 4) -->
    <div class="modal fade" id="pos-serial-modal" tabindex="-1" role="dialog" aria-labelledby="pos-serial-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-serial-modal-label">Input Nomor Serial</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="pos-serial-modal-info" class="alert alert-info py-2 mb-3">
                        <small id="pos-serial-modal-product-name" class="font-weight-bold d-block"></small>
                        <small id="pos-serial-modal-qty-info"></small>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label for="pos-serial-modal-input" class="small font-weight-bold">Scan atau ketik serial:</label>
                        <div class="input-group">
                            <input id="pos-serial-modal-input" type="text" class="form-control" autocomplete="off" placeholder="Nomor Serial...">
                            <div class="input-group-append">
                                <button id="pos-serial-modal-submit" type="button" class="btn btn-primary">Masukkan</button>
                            </div>
                        </div>
                        <small class="form-text text-muted">Tekan Enter atau klik Masukkan untuk menambah serial.</small>
                    </div>
                    
                    <div id="pos-serial-modal-status" class="small mb-3" style="min-height: 1.25rem;"></div>
                    
                    <div class="border-top pt-3">
                        <label class="small font-weight-bold mb-2">Serial Terinput:</label>
                        <div id="pos-serial-modal-list" class="d-flex flex-wrap" style="max-height: 150px; overflow-y: auto; gap: 4px;">
                            <!-- Serials will be listed here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quantity Reduction Modal (Non-Privileged Users) -->
    <div class="modal fade" id="pos-reduce-quantity-modal" tabindex="-1" role="dialog" aria-labelledby="pos-reduce-quantity-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-reduce-quantity-modal-label">Kurangi Jumlah</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="small font-weight-bold">Jumlah Saat Ini:</label>
                        <div id="pos-reduce-qty-current" class="form-control-plaintext font-weight-bold text-primary" style="font-size: 1.25rem;"></div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="pos-reduce-qty-new" class="small font-weight-bold">Jumlah Baru:</label>
                        <input id="pos-reduce-qty-new" type="number" class="form-control" min="1" max="1" value="1">
                        <small id="pos-reduce-qty-error" class="form-text text-danger" style="display: none;"></small>
                    </div>

                    <div class="form-group mb-0">
                        <label for="pos-reduce-qty-reason" class="small font-weight-bold">Alasan (Opsional):</label>
                        <textarea id="pos-reduce-qty-reason" class="form-control" rows="3" placeholder="Tulis alasan pengurangan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="button" id="pos-reduce-qty-submit" class="btn btn-primary" disabled>Minta Persetujuan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cash Pickup Modal -->
    <div class="modal fade" id="pos-cash-pickup-modal" tabindex="-1" role="dialog" aria-labelledby="pos-cash-pickup-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-cash-pickup-modal-label">Pengambilan Kas</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Step 1: Amount Input -->
                    <div id="pos-pickup-step-1">
                        <div class="mb-3">
                            <label class="form-label small font-weight-bold">Terminal:</label>
                            <div id="pos-pickup-terminal-info" class="form-control-plaintext text-muted small"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small font-weight-bold">Kasir:</label>
                            <div id="pos-pickup-cashier-info" class="form-control-plaintext text-muted small"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small font-weight-bold">Ekspektasi Kas:</label>
                            <div id="pos-pickup-expected-cash" class="form-control-plaintext font-weight-bold text-primary" style="font-size: 1.1rem;"></div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="pos-pickup-amount" class="form-label small font-weight-bold">Jumlah Pengambilan:</label>
                            <input type="number" id="pos-pickup-amount" class="form-control" min="0" step="0.01" placeholder="0">
                            <small id="pos-pickup-amount-error" class="form-text text-danger d-none"></small>
                        </div>
                    </div>

                    <!-- Step 2: Supervisor Credentials -->
                    <div id="pos-pickup-step-2" class="d-none">
                        <div class="alert alert-info mb-3 small">
                            <strong>Konfirmasi Pengambilan:</strong>
                            <div id="pos-pickup-confirmation-amount" class="font-weight-bold mt-2"></div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="pos-pickup-supervisor-email" class="form-label small font-weight-bold">Email Supervisor:</label>
                            <input type="email" id="pos-pickup-supervisor-email" class="form-control" placeholder="supervisor@example.com">
                        </div>
                        <div class="form-group mb-3">
                            <label for="pos-pickup-supervisor-password" class="form-label small font-weight-bold">Password:</label>
                            <input type="password" id="pos-pickup-supervisor-password" class="form-control" placeholder="••••••••">
                        </div>
                        <small id="pos-pickup-step2-error" class="form-text text-danger d-none d-block mb-3"></small>
                        <div id="pos-pickup-spinner" class="spinner-border spinner-border-sm text-primary d-none" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- Step 1 Footer -->
                    <div id="pos-pickup-step-1-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="button" id="pos-pickup-next-btn" class="btn btn-primary" disabled>Lanjut</button>
                    </div>
                    <!-- Step 2 Footer -->
                    <div id="pos-pickup-step-2-footer" class="d-none w-100 d-flex justify-content-between">
                        <button type="button" id="pos-pickup-back-btn" class="btn btn-secondary">Kembali</button>
                        <button type="button" id="pos-pickup-confirm-btn" class="btn btn-primary" disabled>Konfirmasi Pengambilan</button>
                    </div>
                </div>
            </div>
        </div>
    </div>


@push('page_scripts')
    <!-- Task 3.1: Include staged payment module -->
    <script src="{{ asset('js/pos-staged-payment.js') }}"></script>
    <script>
        (function () {
            const searchInput = document.getElementById('pos-shell-search');
            const statusElement = document.getElementById('pos-shell-search-status');

            const cariProdukButton = document.getElementById('pos-btn-cari-produk');
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
            const saveDraftButton = document.getElementById('pos-save-draft');

            const btnCheckout = document.getElementById('pos-checkout-final');

            const checkoutModalElement = document.getElementById('pos-checkout-modal');
            const checkoutMethodLabel = document.getElementById('pos-checkout-method-label');
            const checkoutMethodId = document.getElementById('pos-checkout-method-id');
            const checkoutMethodSearch = document.getElementById('pos-checkout-method-search');
            const checkoutMethodResults = document.getElementById('pos-checkout-method-results');
            const checkoutTotalLabel = document.getElementById('pos-checkout-total-label');
            const checkoutAmountPaidSummary = document.getElementById('pos-checkout-amount-paid-summary');
            const checkoutRemainingLabel = document.getElementById('pos-checkout-remaining-label');
            const checkoutSubmit = document.getElementById('pos-checkout-submit');
            const checkoutError = document.getElementById('pos-checkout-error');
            const checkoutPaymentsList = document.getElementById('pos-checkout-payments-list');

            const checkoutReceiptLines = document.getElementById('pos-checkout-receipt-lines');
            const checkoutReceiptTotal = document.getElementById('pos-checkout-receipt-total');

            const successReceiptElement = document.getElementById('pos-success-receipt');
            const successChangeElement = document.getElementById('pos-success-change');
            const shortcutReprintBtn = document.getElementById('pos-shortcut-reprint');

            // Phase 1-3: Search results modal elements
            const searchResultsModalElement = document.getElementById('pos-search-results-modal');
            const searchResultsModalContainer = document.getElementById('pos-search-modal-results');
            const modalSearchInput = document.getElementById('pos-modal-search-input');
            const modalSearchBtn = document.getElementById('pos-modal-search-btn');

            // Phase 4: Serial modal elements
            const serialModalElement = document.getElementById('pos-serial-modal');
            const serialModalProductName = document.getElementById('pos-serial-modal-product-name');
            const serialModalQtyInfo = document.getElementById('pos-serial-modal-qty-info');
            const serialModalInput = document.getElementById('pos-serial-modal-input');
            const serialModalSubmitButton = document.getElementById('pos-serial-modal-submit');
            const serialModalStatus = document.getElementById('pos-serial-modal-status');
            const serialModalList = document.getElementById('pos-serial-modal-list');

            // Quantity Reduction Modal elements
            const reduceQuantityModal = document.getElementById('pos-reduce-quantity-modal');
            const reduceQtyCurrent = document.getElementById('pos-reduce-qty-current');
            const reduceQtyNewInput = document.getElementById('pos-reduce-qty-new');
            const reduceQtyError = document.getElementById('pos-reduce-qty-error');
            const reduceQtyReason = document.getElementById('pos-reduce-qty-reason');
            const reduceQtySubmit = document.getElementById('pos-reduce-qty-submit');
            let pendingReduceLineId = null;
            let pendingReduceCurrentQty = null;
            let pendingReduceButton = null;

            // Track pending approval requests on the client side
            const clientPendingApprovals = {}; // { lineId: { requestId, requestedQty, status, token } }

            const searchEndpoint = @json(route('pos.sell.products.search'));
            const scanResolveEndpoint = @json(url('/pos/sell/search/resolve'));
            const customerSearchEndpoint = @json(route('pos.sell.customers.search'));
            const cartShowEndpoint = @json(route('pos.sell.cart.show'));
            const cartStoreLineEndpoint = @json(route('pos.sell.cart.lines.store'));
            const cartClearEndpoint = @json(route('pos.sell.cart.clear'));
            const saveAndNewEndpoint = @json(route('pos.sell.transactions.save-and-new'));
            const cartCustomerEndpoint = @json(route('pos.sell.cart.customer.update'));
            const customerStoreEndpoint = @json(route('pos.sell.customers.store'));
            const paymentMethodSearchEndpoint = @json(url('/pos/sell/payment-methods/search'));
            const finalizeEndpoint = @json(route('pos.sell.checkout.finalize'));
            const cartLinesBaseUrl = @json(url('/pos/sell/cart/lines'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const roleCapabilities = @json($roleCapabilities ?? []);
            console.log('[INIT] roleCapabilities: ' + JSON.stringify(roleCapabilities));
            const canCheckoutByRole = Boolean(roleCapabilities && roleCapabilities.can_checkout !== false);
            const canReduceQuantity = Boolean(
              typeof roleCapabilities?.can_reduce_quantity === 'boolean' ? roleCapabilities.can_reduce_quantity :
              typeof roleCapabilities?.direct_permissions?.qty_reduce === 'boolean' ? roleCapabilities.direct_permissions.qty_reduce :
              false
            );
            console.log('[INIT] canReduceQuantity: ' + canReduceQuantity + ' (can_reduce_quantity: ' + roleCapabilities?.can_reduce_quantity + ')');

            if (!searchInput || !statusElement || !cartBody || !searchEndpoint || !cartShowEndpoint) {
                return;
            }

            let latestRequestId = 0;
            let customerDebounceHandle = null;
            let latestCustomerRequestId = 0;
            let currentSnapshot = null;
            let cachedPaymentMethods = [];

            // Task 4.1: Payment composer state (multiple payment rows)
            let checkoutPayments = []; // Array of {id, method, amount, reference, errors}

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
                // Inline results removed in Phase 1; this function is kept as a no-op for backward compatibility
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

            // Task 4.1: Payment composer - add a payment row
            function addPaymentRow(method) {
                const paymentId = 'payment-' + Date.now();
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                const newPayment = {
                    id: paymentId,
                    method: method,
                    amount: 0,
                    reference: '',
                    errors: []
                };

                checkoutPayments.push(newPayment);
                checkoutMethodSearch.value = '';
                if (checkoutMethodResults) {
                    checkoutMethodResults.style.display = 'none';
                }

                renderPaymentsList();
                updatePaymentSummary();
            }

            // Task 4.1: Payment composer - render all payment rows
            function renderPaymentsList() {
                if (!checkoutPaymentsList) return;

                checkoutPaymentsList.innerHTML = '';
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                checkoutPayments.forEach((payment, index) => {
                    const methodName = payment.method ? escapeHtml(payment.method.name || 'Unknown') : 'Unknown';
                    const isCash = payment.method && payment.method.is_cash === true;
                    const requiresReference = payment.method && payment.method.requires_reference === true;

                    const row = document.createElement('div');
                    row.className = 'card mb-2 p-3';
                    row.style.backgroundColor = '#f8f9fa';

                    let referenceHtml = '';
                    if (requiresReference) {
                        const refValue = escapeHtml(payment.reference || '');
                        referenceHtml = `
                            <div class="form-group mb-2">
                                <label class="small font-weight-bold mb-1">Referensi</label>
                                <input type="text" class="form-control form-control-sm js-payment-reference"
                                       data-payment-id="${payment.id}" placeholder="Masukkan referensi"
                                       value="${refValue}">
                            </div>
                        `;
                    }

                    row.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong>${methodName}</strong>
                                ${payment.errors.length > 0 ? '<div class="small text-danger mt-1">' + payment.errors.join('; ') + '</div>' : ''}
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger js-remove-payment"
                                    data-payment-id="${payment.id}" aria-label="Hapus">×</button>
                        </div>
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-1">Jumlah (Rp)</label>
                            <input type="number" class="form-control form-control-sm js-payment-amount"
                                   data-payment-id="${payment.id}" step="1" min="0"
                                   value="${payment.amount}" placeholder="0">
                        </div>
                        ${referenceHtml}
                    `;

                    checkoutPaymentsList.appendChild(row);
                });

                // Add event listeners for payment rows
                document.querySelectorAll('.js-payment-amount').forEach(el => {
                    el.addEventListener('change', function () {
                        const paymentId = this.dataset.paymentId;
                        const amount = Number(this.value || 0);
                        const payment = checkoutPayments.find(p => p.id === paymentId);
                        if (payment) {
                            payment.amount = amount;
                            updatePaymentSummary();
                        }
                    });
                });

                document.querySelectorAll('.js-payment-reference').forEach(el => {
                    el.addEventListener('change', function () {
                        const paymentId = this.dataset.paymentId;
                        const reference = this.value.trim();
                        const payment = checkoutPayments.find(p => p.id === paymentId);
                        if (payment) {
                            payment.reference = reference;
                        }
                    });
                });

                document.querySelectorAll('.js-remove-payment').forEach(el => {
                    el.addEventListener('click', function () {
                        const paymentId = this.dataset.paymentId;
                        checkoutPayments = checkoutPayments.filter(p => p.id !== paymentId);
                        renderPaymentsList();
                        updatePaymentSummary();
                    });
                });
            }

            // Task 4.1: Update summary display
            function updatePaymentSummary() {
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                const totalPaid = checkoutPayments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                const remaining = grandTotal - totalPaid;

                if (checkoutAmountPaidSummary) {
                    checkoutAmountPaidSummary.value = formatPrice(totalPaid);
                }

                if (checkoutRemainingLabel) {
                    checkoutRemainingLabel.value = formatPrice(remaining);
                    checkoutRemainingLabel.classList.remove('text-success', 'text-danger', 'text-warning');
                    if (remaining > 0) {
                        checkoutRemainingLabel.classList.add('text-danger');
                    } else if (remaining < 0) {
                        checkoutRemainingLabel.classList.add('text-success');
                    } else {
                        checkoutRemainingLabel.classList.add('text-warning');
                    }
                }

                // Enable/disable submit button based on validation
                validatePaymentComposer();
            }

            // Task 4.3: Validate payment composer
            function validatePaymentComposer() {
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                const totalPaid = checkoutPayments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                let isValid = true;
                let errorMsg = '';

                // Check if we have any payments
                if (checkoutPayments.length === 0) {
                    isValid = false;
                    errorMsg = 'Tambahkan minimal satu metode pembayaran.';
                } else {
                    // Validate each payment row
                    checkoutPayments.forEach((payment, index) => {
                        payment.errors = [];

                        if (!payment.amount || payment.amount <= 0) {
                            payment.errors.push('Jumlah harus lebih dari 0');
                            isValid = false;
                        }

                        if (payment.method && payment.method.requires_reference && !payment.reference) {
                            payment.errors.push('Referensi wajib diisi');
                            isValid = false;
                        }
                    });

                    // Check if total paid matches grand total
                    if (isValid && totalPaid !== grandTotal) {
                        isValid = false;
                        const diff = grandTotal - totalPaid;
                        if (diff > 0) {
                            errorMsg = 'Total pembayaran kurang ' + formatPrice(diff);
                        } else {
                            errorMsg = 'Total pembayaran lebih ' + formatPrice(Math.abs(diff));
                        }
                    }

                    renderPaymentsList();
                }

                if (checkoutError) {
                    checkoutError.textContent = errorMsg;
                    checkoutError.classList.toggle('d-none', !errorMsg && isValid);
                }

                if (checkoutSubmit) {
                    checkoutSubmit.disabled = !isValid;
                }

                return isValid;
            }

            // Task 1.1: Canonical quantity-approval state mapper
            // Normalizes approval objects from mixed sources (client cache and server snapshot)
            // into a canonical shape for consistent rendering
            function normalizeQtyApprovalState(approvalObj) {
                if (!approvalObj) {
                    console.log('[normalizeQtyApprovalState] Input is null/undefined');
                    return null;
                }

                const normalized = {
                    request_id: approvalObj.request_id || approvalObj.requestId,
                    status: String(approvalObj.status || '').toUpperCase(),
                    requested_qty: approvalObj.requestedQty || approvalObj.payload?.qty || approvalObj.requested_qty,
                    token: approvalObj.token || approvalObj.approval_token,
                    approval_token: approvalObj.approval_token || approvalObj.token
                };
                console.log('[normalizeQtyApprovalState] Input: ' + JSON.stringify(approvalObj) + ' → Normalized: ' + JSON.stringify(normalized));
                return normalized;
            }

            // Task 1.1: Shared qty-approval state-to-button renderer
            // Maps normalized approval state to HTML button for rendering in either serial or non-serial rows.
            // Ensures both row types render equivalent UI for the same approval state.
            function renderQtyApprovalSlotButton(qtyReduceReq, lineId, currentQty) {
                if (!qtyReduceReq) {
                    // No approval request: render reduce button
                    return `<button type="button" class="btn btn-sm btn-outline-warning js-reduce-qty pos-qty-reduce-btn" data-line-id="${lineId}" data-current-qty="${currentQty}" title="Kurangi Jumlah" aria-label="Kurangi Jumlah">-</button>`;
                }

                if (qtyReduceReq.status === 'APPROVED') {
                    // Approved: show proceed button with approved qty
                    const approvedQty = qtyReduceReq.requested_qty || '?';
                    return `<button type="button" class="btn btn-sm btn-success js-check-qty-approval pos-qty-reduce-btn" data-line-id="${lineId}" data-approval-token="${qtyReduceReq.approval_token || ''}" data-approved-qty="${approvedQty}" title="Lanjutkan (qty: ${approvedQty})" aria-label="Lanjutkan">✓ ${approvedQty}</button>`;
                }

                if (qtyReduceReq.status !== 'REJECTED' && qtyReduceReq.status !== 'CANCELLED') {
                    // Pending or other active states: show check approval button
                    return `<button type="button" class="btn btn-sm btn-warning js-check-qty-approval pos-qty-reduce-btn" data-line-id="${lineId}" data-approval-pending="${qtyReduceReq.request_id}" title="Periksa Persetujuan" aria-label="Periksa Persetujuan">Periksa</button>`;
                }

                // Rejected or cancelled: render reduce button
                return `<button type="button" class="btn btn-sm btn-outline-warning js-reduce-qty pos-qty-reduce-btn" data-line-id="${lineId}" data-current-qty="${currentQty}" title="Kurangi Jumlah" aria-label="Kurangi Jumlah"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>`;
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
                    if (options.method === 'GET') {
                        const qs = new URLSearchParams(payload).toString();
                        url += (url.includes('?') ? '&' : '?') + qs;
                    } else {
                        options.body = JSON.stringify(payload);
                    }
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

            const ApprovalManager = {
                async wrapAction(btn, originalText, actionType, targetType, targetId, payload, actionFn) {
                    const pendingRequestId = btn.getAttribute('data-approval-pending');
                    if (pendingRequestId) {
                        await this.checkApproval(btn, originalText, pendingRequestId);
                        return false;
                    }

                    const token = btn.getAttribute('data-approval-token') || null;
                    if (token) {
                        const result = await Swal.fire({
                            title: 'Lanjutkan Aksi?',
                            text: 'Tekan Lanjutkan untuk mengeksekusi aksi, atau Batalkan untuk menghapus persetujuan.',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Lanjutkan',
                            cancelButtonText: 'Batalkan Persetujuan',
                            reverseButtons: true
                        });
                        
                        if (!result.isConfirmed) {
                            await this.cancelApproval(btn, originalText);
                            setCartStatus('Aksi dibatalkan tanpa perubahan keranjang.', 'text-muted');
                            return false;
                        }
                    }

                    try {
                        await actionFn(token);
                        this.resetBtn(btn, originalText);
                        return true;
                    } catch (error) {
                        const msg = error.message || '';
                        if (msg === 'APPROVAL_REQUIRED' || msg.includes('otorisasi') || msg.includes('APPROVAL')) {
                            await this.requestApproval(btn, originalText, actionType, targetType, targetId, payload);
                            return false;
                        }

                        setCartStatus(msg || 'Aksi gagal.', 'text-danger');
                        this.resetBtn(btn, originalText);
                        throw error;
                    }
                },

                async requestApproval(btn, originalText, actionType, targetType, targetId, payload) {
                    const { value: reasonInput, isDismissed } = await Swal.fire({
                        title: 'Permintaan Persetujuan',
                        text: 'Silakan masukkan alasan permintaan persetujuan (opsional):',
                        input: 'textarea',
                        inputPlaceholder: 'Tulis alasan di sini...',
                        showCancelButton: true,
                        confirmButtonText: 'Kirim Permintaan',
                        cancelButtonText: 'Batal',
                    });

                    if (isDismissed) {
                        return;
                    }

                    try {
                        const reqPayload = {
                            action_type: actionType,
                            target_type: targetType,
                            target_id: targetId,
                            payload: payload || {},
                            reason: reasonInput.trim() || null,
                        };
                        const res = await jsonRequest('/pos/sell/approval-requests', 'POST', reqPayload);
                        console.log('[requestApproval] Response received:', {request_id: res?.request_id, has_cart_snapshot: !!res?.cart_snapshot, cart_snapshot: res?.cart_snapshot});
                        if (res && res.request_id) {
                            console.log('[requestApproval] Success! Request ID:', res.request_id);
                            btn.setAttribute('data-approval-pending', res.request_id);
                            btn.setAttribute('data-approval-request-id', res.request_id);
                            if (btn.tagName === 'BUTTON') {
                                btn.textContent = 'Periksa Persetujuan';
                                btn.classList.remove('btn-danger', 'btn-outline-danger', 'btn-success');
                                btn.classList.add('btn-warning');
                                setCartStatus('Permintaan dikirim. Klik "Periksa Persetujuan" untuk cek status.', 'text-warning');
                            } else {
                                btn.classList.add('border-warning');
                                setCartStatus('Permintaan dikirim. Ulangi aksi untuk memeriksa status persetujuan.', 'text-warning');
                            }
                            // Render cart with snapshot to update approval state
                            if (res.cart_snapshot) {
                                console.log('[requestApproval] Calling renderCart with snapshot');
                                console.log('[requestApproval] Snapshot lines:', res.cart_snapshot.lines);
                                res.cart_snapshot.lines.forEach((line, idx) => {
                                    console.log(`[requestApproval] Line ${idx} (line_id=${line.line_id}):`, JSON.stringify({
                                        product_name: line.product_name,
                                        pending_approvals: line.pending_approvals
                                    }));
                                });
                                renderCart(res.cart_snapshot);
                            } else {
                                console.log('[requestApproval] WARNING: No cart_snapshot in response!');
                            }
                        } else {
                            console.log('[requestApproval] No request_id in response:', res);
                        }
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal meminta persetujuan.', 'text-danger');
                        this.resetBtn(btn, originalText);
                    }
                },

                async checkApproval(btn, originalText, requestId) {
                    try {
                        const res = await jsonRequest('/pos/sell/approval-requests/' + requestId, 'GET');
                        const state = String(res.state || res.status || '').toLowerCase();
                        const decisionReason = String(res.decision_reason || '').trim();

                        if (state === 'pending') {
                            setCartStatus('Status masih pending. Anda dapat klik lagi untuk cek ulang.', 'text-warning');
                            return;
                        }

                        if (state === 'approved' && (res.approval_token || res.token)) {
                            const token = res.approval_token || res.token;
                            btn.removeAttribute('data-approval-pending');
                            btn.setAttribute('data-approval-token', token);
                            if (btn.tagName === 'BUTTON') {
                                btn.textContent = 'Lanjutkan / Batalkan';
                                btn.classList.remove('btn-warning', 'btn-danger', 'btn-outline-danger');
                                btn.classList.add('btn-success');
                                setCartStatus('Permintaan disetujui. Klik tombol untuk Lanjutkan atau Batalkan.', 'text-success');
                            } else {
                                btn.classList.remove('border-warning');
                                btn.classList.add('border-success');
                                setCartStatus('Permintaan disetujui. Ulangi aksi untuk Lanjutkan atau Batalkan.', 'text-success');
                            }
                            return;
                        }

                        this.resetBtn(btn, originalText);
                        if (state === 'rejected') {
                            const suffix = decisionReason ? ' Alasan: ' + decisionReason : '';
                            setCartStatus('Permintaan ditolak.' + suffix, 'text-danger');
                            return;
                        }

                        if (state === 'cancelled') {
                            setCartStatus('Permintaan dibatalkan.', 'text-muted');
                            return;
                        }

                        if (state === 'expired') {
                            setCartStatus('Persetujuan kedaluwarsa. Ajukan ulang bila diperlukan.', 'text-danger');
                            return;
                        }

                        setCartStatus('Status persetujuan tidak dikenali. Ajukan ulang permintaan.', 'text-danger');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal memeriksa status persetujuan.', 'text-danger');
                    }
                },

                async cancelApproval(btn, originalText) {
                    const requestId = btn.getAttribute('data-approval-pending') || btn.getAttribute('data-approval-request-id');
                    if (!requestId) {
                        this.resetBtn(btn, originalText);
                        return;
                    }

                    try {
                        await jsonRequest('/pos/sell/approval-requests/' + requestId + '/cancel', 'POST');
                    } catch (error) {
                        // Request might already be consumed/cancelled; reset UI anyway.
                    }

                    this.resetBtn(btn, originalText);
                },

                resetBtn(btn, originalText) {
                    btn.removeAttribute('data-approval-pending');
                    btn.removeAttribute('data-approval-token');
                    btn.removeAttribute('data-approval-request-id');
                    btn.classList.remove('border-warning', 'border-success');
                    if (btn.tagName === 'BUTTON') {
                        btn.innerHTML = originalText;
                        btn.className = btn.getAttribute('data-original-class') || btn.className;
                    } else if (btn.tagName === 'INPUT') {
                        btn.value = originalText;
                    }
                    // For inline qty elements, reset logic will be handled manually.
                }
            };

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
                console.log('Building row for line:', JSON.stringify(line));
                const serialBadge = line.serial_number_required
                    ? '<span class="badge badge-warning ml-1">Perlu Serial</span>'
                    : '';

                const productName = escapeHtml(line.product_name || '-');
                const productCode = escapeHtml(line.product_code || '-');
                const barcode = escapeHtml(line.barcode || '-');
                const qty = Number(line.qty || 0);
                const lineId = Number(line.line_id || 0);
                const priceValid = line.price_valid !== false;
                const priceError = escapeHtml(line.price_error || '');

                // Pattern 1: Privilege-based quantity control rendering
                // For non-privileged users: increment-only input + reduce button + approval workflow (shows "Periksa Persetujuan")
                // For privileged users: standard editable qty input with +/- spinner
                // NOTE: The approval workflow only renders for users WITHOUT pos.cart.line.reduce permission
                let qtyCell = '';
                if (line.serial_number_required === true) {
                    // Serial line: editable qty + serial management
                    const assignedCount = Array.isArray(line.assigned_serials) ? line.assigned_serials.length : 0;
                    const serialChips = (line.assigned_serials || []).map(serial => `
                        <div class="pos-serial-chip">
                            <span title="${escapeHtml(serial)}">${escapeHtml(serial)}</span>
                            <button type="button" class="js-serial-remove" data-serial="${escapeHtml(serial)}">×</button>
                        </div>
                    `).join('');

                    // Fetch latest qty-reduce approval from server snapshot (shared for both paths)
                    const backendQtyReduceReq = (line.pending_approvals || [])
                        .slice()
                        .sort((a, b) => b.request_id - a.request_id)
                        .find(a => a.action_type === 'QTY_REDUCE');
                    const clientPending = clientPendingApprovals[lineId];
                    const qtyReduceRaw = backendQtyReduceReq || clientPending;
                    const qtyReduceReq = normalizeQtyApprovalState(qtyReduceRaw);

                    // For privileged users: full qty control with serial button
                    if (canReduceQuantity) {
                        // Privileged serial: direct decrease button, then qty, then increase, then serial action
                        qtyCell = `
                            <td class="pos-cart-serial-cell align-middle" style="min-width: 200px;">
                                <div class="d-flex flex-column align-items-center" style="gap: 2px;">
                                    <div class="d-flex align-items-center pos-qty-control-strip">
                                        <button type="button" class="btn btn-sm btn-outline-danger js-qty-decrease" data-line-id="${lineId}" title="Kurangi" aria-label="Kurangi">−</button>
                                        <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                               type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                               style="width: 55px;">
                                        <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 4px;">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info js-serial-add pos-serial-action"
                                                data-line-id="${lineId}"
                                                data-product-name="${productName}"
                                                title="Atur Serial"
                                                aria-label="Atur Serial">
                                            <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                            <span class="pos-serial-action-label">Serial</span>
                                        </button>
                                        <small class="text-muted font-weight-bold" style="font-size: 0.65rem;">${assignedCount}/${qty} Serial</small>
                                    </div>
                                    <div class="pos-serial-wrapper flex-grow-1">
                                        ${serialChips}
                                    </div>
                                </div>
                            </td>
                        `;
                    } else {
                        // Non-privileged serial: use shared control strip [Reduce/Periksa slot][qty input][+]
                        // Build shared control strip in order: [Reduce/Periksa slot][qty input][+]
                        const slotButtonHtml = renderQtyApprovalSlotButton(qtyReduceReq, lineId, qty);

                        qtyCell = `
                            <td class="pos-cart-serial-cell align-middle" style="min-width: 200px;">
                                <div class="d-flex flex-column align-items-center" style="gap: 2px;">
                                    <div class="d-flex align-items-center pos-qty-control-strip">
                                        ${slotButtonHtml}
                                        <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                               type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                               data-can-reduce="${canReduceQuantity}"
                                               style="width: 55px;">
                                        <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 4px;">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info js-serial-add pos-serial-action"
                                                data-line-id="${lineId}"
                                                data-product-name="${productName}"
                                                title="Atur Serial"
                                                aria-label="Atur Serial">
                                            <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                            <span class="pos-serial-action-label">Serial</span>
                                        </button>
                                        <small class="text-muted font-weight-bold" style="font-size: 0.65rem;">${assignedCount}/${qty} Serial</small>
                                    </div>
                                    <div class="pos-serial-wrapper flex-grow-1">
                                        ${serialChips}
                                    </div>
                                </div>
                            </td>
                        `;
                    }
                } else {
                    // Non-serial line: fetch approval state once for both paths
                    const backendQtyReduceReq = (line.pending_approvals || [])
                        .slice()
                        .sort((a, b) => b.request_id - a.request_id)
                        .find(a => a.action_type === 'QTY_REDUCE');
                    const clientPending = clientPendingApprovals[lineId];
                    const qtyReduceRaw = backendQtyReduceReq || clientPending;
                    const qtyReduceReq = normalizeQtyApprovalState(qtyReduceRaw);

                    if (canReduceQuantity) {
                        // Privileged non-serial: direct decrease, qty, increase in centered strip
                        qtyCell = `
                            <td class="text-center align-middle">
                                <div class="d-flex align-items-center justify-content-center pos-qty-control-strip">
                                    <button type="button" class="btn btn-sm btn-outline-danger js-qty-decrease" data-line-id="${lineId}" title="Kurangi" aria-label="Kurangi">−</button>
                                    <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                           type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                           data-can-reduce="${canReduceQuantity}"
                                           style="width: 55px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                </div>
                            </td>
                        `;
                    } else {
                        // Non-privileged non-serial: use shared control strip [Reduce/Periksa slot][qty input][+]
                        const slotButtonHtml = renderQtyApprovalSlotButton(qtyReduceReq, lineId, qty);

                        qtyCell = `
                            <td class="text-center align-middle">
                                <div class="d-flex align-items-center justify-content-center pos-qty-control-strip">
                                    ${slotButtonHtml}
                                    <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                           type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                           data-can-reduce="${canReduceQuantity}"
                                           style="width: 55px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                </div>
                            </td>
                        `;
                    }
                }

                // Phase 3B: Price validity indicator
                const priceWarning = !priceValid ? `<div class="text-warning small font-weight-bold mb-1">⚠ ${priceError}</div>` : '';
                const rowClass = !priceValid ? 'bg-warning-light' : '';

                // Phase 3C: Delete button with approval state
                // Sort by request_id DESC to get the latest request (defensive against ordering)
                const removeReq = (line.pending_approvals || [])
                    .slice()
                    .sort((a, b) => b.request_id - a.request_id)
                    .find(a => a.action_type === 'LINE_REMOVE');
                let deleteButtonHtml = '';
                if (removeReq) {
                    if (removeReq.status === 'APPROVED') {
                        deleteButtonHtml = `<button type="button" class="btn btn-link text-success p-0 small js-line-remove" data-original-class="btn btn-link text-danger p-0 small js-line-remove" data-approval-token="${removeReq.token || removeReq.approval_token || ''}" title="Lanjutkan" aria-label="Lanjutkan">Lanjutkan</button>`;
                    } else {
                        deleteButtonHtml = `<button type="button" class="btn btn-link text-warning p-0 small js-line-remove" data-original-class="btn btn-link text-danger p-0 small js-line-remove" data-approval-pending="${removeReq.request_id}" title="Periksa Persetujuan" aria-label="Periksa Persetujuan">Periksa</button>`;
                    }
                } else {
                    deleteButtonHtml = `<button type="button" class="btn btn-link text-danger p-0 small js-line-remove" data-original-class="btn btn-link text-danger p-0 small js-line-remove" title="Hapus" aria-label="Hapus">Hapus</button>`;
                }

                return `
                    <tr data-line-id="${lineId}" class="${rowClass}">
                        <td class="pos-cart-product align-middle">
                            ${priceWarning}
                            <div class="name">${productName}${serialBadge}</div>
                            <div class="meta">${productCode} | ${barcode}</div>
                        </td>
                        <td class="text-right align-middle">${formatPrice(line.unit_price || 0)}</td>
                        ${qtyCell}
                        <td class="text-right align-middle" style="vertical-align: top;">
                            <div class="font-weight-bold mb-1">${formatPrice(line.line_total || 0)}</div>
                        </td>
                        <td class="text-center align-middle">
                            ${deleteButtonHtml}
                        </td>
                    </tr>
                `;
            }

            function renderCart(snapshot) {
                console.log('[renderCart] Called with snapshot: ' + JSON.stringify({
                    hasSnapshot: !!snapshot,
                    lines: snapshot?.lines?.length || 0,
                    lineIds: snapshot?.lines?.map(l => l.line_id) || [],
                    cartItems: snapshot?.lines?.map(l => ({
                        line_id: l.line_id,
                        product_name: l.product_name,
                        pending_approvals_count: l.pending_approvals?.length || 0
                    })) || []
                }));
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
 
                 const canSaveDraft = hasItems && grandTotal > 0 && hasCustomer && allPricesValid && allSerialsValid;
                 const canCheckout = canSaveDraft && canCheckoutByRole;

                 if (btnCheckout) {
                     btnCheckout.disabled = !canCheckout;
                 }
 
                 if (saveDraftButton) {
                     saveDraftButton.disabled = !canSaveDraft;
                 }

                 if (clearCartButton) {
                     const isCustomerSelected = snapshot && snapshot.customer && snapshot.customer.resolution_source === 'selected';
                     const canClear = hasItems || isCustomerSelected;
                     clearCartButton.disabled = !canClear;

                     // Persist approval state for Kosongkan Keranjang
                     const clearReq = (snapshot && snapshot.pending_approvals || []).find(a => a.action_type === 'CART_CLEAR');
                     if (clearReq) {
                         clearCartButton.setAttribute('data-approval-pending', clearReq.request_id);
                         clearCartButton.setAttribute('data-approval-request-id', clearReq.request_id);
                         if (clearReq.status === 'APPROVED') {
                             clearCartButton.textContent = 'Lanjutkan / Batalkan';
                             clearCartButton.classList.remove('btn-outline-danger', 'btn-warning');
                             clearCartButton.classList.add('btn-success');
                         } else {
                             clearCartButton.textContent = 'Periksa Persetujuan';
                             clearCartButton.classList.remove('btn-outline-danger', 'btn-success');
                             clearCartButton.classList.add('btn-warning');
                         }
                     } else {
                         clearCartButton.removeAttribute('data-approval-pending');
                         clearCartButton.removeAttribute('data-approval-request-id');
                         clearCartButton.removeAttribute('data-approval-token');
                         clearCartButton.textContent = 'Kosongkan Keranjang';
                         clearCartButton.classList.remove('btn-warning', 'btn-success');
                         clearCartButton.classList.add('btn-outline-danger');
                     }
                 }

                 if (!canCheckoutByRole && hasItems) {
                     setCartStatus('Peran Anda tidak dapat menyelesaikan pembayaran. Gunakan "Simpan dan Buka Baru" untuk handoff.', 'text-muted');
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
            let currentSerialLineId = null;
            let serialAppendInFlight = false;

            function setSerialAppendInFlight(inFlight) {
                serialAppendInFlight = inFlight === true;
                if (serialModalSubmitButton) {
                    serialModalSubmitButton.disabled = serialAppendInFlight;
                }
            }

            function renderSerialModalList() {
                if (!serialModalList || !currentSnapshot || currentSerialLineId === null) return;
                
                const line = currentSnapshot.lines.find(l => Number(l.line_id) === currentSerialLineId);
                if (!line) return;

                const serials = Array.isArray(line.assigned_serials) ? line.assigned_serials : [];
                
                serialModalList.innerHTML = serials.map(serial => `
                    <div class="badge badge-primary d-flex align-items-center p-2">
                        <span class="mr-2">${escapeHtml(serial)}</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-white js-serial-remove" 
                                data-serial="${escapeHtml(serial)}" title="Hapus" aria-label="Hapus serial ${escapeHtml(serial)}">
                            &times;
                        </button>
                    </div>
                `).join('');

                if (serialModalQtyInfo) {
                    serialModalQtyInfo.textContent = `${serials.length} dari ${line.qty} serial terinput`;
                    serialModalQtyInfo.className = serials.length === line.qty ? 'text-success font-weight-bold' : 'text-primary';
                }
            }

            function openSerialModal(lineId, productName) {
                currentSerialLineId = lineId;
                if (serialModalProductName) serialModalProductName.textContent = productName;
                if (serialModalInput) serialModalInput.value = '';
                if (serialModalStatus) {
                    serialModalStatus.textContent = '';
                    serialModalStatus.className = 'small mb-3';
                }
                
                renderSerialModalList();
                $(serialModalElement).modal('show');
            }

            async function appendSerialToLine(lineId, serialNumber) {
                try {
                    const url = cartLinesBaseUrl + '/' + lineId + '/serials/append';
                    const response = await jsonRequest(url, 'POST', { serial_number: serialNumber });
                    if (response && response.cart_snapshot) {
                        renderCart(response.cart_snapshot);
                        
                        // If modal is open for this line, update it
                        if (currentSerialLineId === lineId) {
                            renderSerialModalList();
                            if (serialModalStatus) {
                                serialModalStatus.textContent = `Serial ${serialNumber} berhasil ditambahkan.`;
                                serialModalStatus.className = 'small mb-3 text-success';
                            }
                        }
                    }
                    return true;
                } catch (error) {
                    const errorMsg = error.message || 'Gagal menambahkan serial.';
                    if (currentSerialLineId === lineId) {
                        if (serialModalStatus) {
                            serialModalStatus.textContent = errorMsg;
                            serialModalStatus.className = 'small mb-3 text-danger';
                        }
                    } else {
                        setCartStatus(errorMsg, 'text-danger', true);
                    }
                    return false;
                }
            }

            async function submitSerialModalInput() {
                if (!serialModalInput || currentSerialLineId === null) {
                    return false;
                }

                if (serialAppendInFlight) {
                    return false;
                }

                const serialNumber = (serialModalInput.value || '').trim();
                if (!serialNumber) {
                    if (serialModalStatus) {
                        serialModalStatus.textContent = 'Serial tidak boleh kosong.';
                        serialModalStatus.className = 'small mb-3 text-danger';
                    }
                    serialModalInput.focus();
                    return false;
                }

                setSerialAppendInFlight(true);
                try {
                    const didAppend = await appendSerialToLine(currentSerialLineId, serialNumber);
                    if (didAppend) {
                        serialModalInput.value = '';
                        serialModalInput.focus();
                    }

                    return didAppend;
                } finally {
                    setSerialAppendInFlight(false);
                }
            }

            async function removeSerialFromLine(lineId, serialNumber, source) {
                const normalizedLineId = Number(lineId);
                const normalizedSerial = String(serialNumber || '').trim();

                if (!Number.isFinite(normalizedLineId) || normalizedLineId <= 0 || normalizedSerial.length === 0) {
                    const message = 'Data serial tidak valid.';
                    if (source === 'modal' && serialModalStatus) {
                        serialModalStatus.textContent = message;
                        serialModalStatus.className = 'small mb-3 text-danger';
                    }
                    setCartStatus(message, 'text-danger', true);
                    return;
                }

                try {
                    const url = cartLinesBaseUrl + '/' + normalizedLineId + '/serials/' + encodeURIComponent(normalizedSerial);
                    const response = await jsonRequest(url, 'DELETE');
                    if (response && response.cart_snapshot) {
                        renderCart(response.cart_snapshot);
                        if (currentSerialLineId === normalizedLineId) {
                            renderSerialModalList();
                        }

                        if (source === 'modal' && serialModalStatus) {
                            serialModalStatus.textContent = `Serial ${normalizedSerial} berhasil dihapus.`;
                            serialModalStatus.className = 'small mb-3 text-success';
                        }

                        setCartStatus('Serial berhasil dihapus.', 'text-success');
                    }
                } catch (error) {
                    const errorMessage = 'Gagal menghapus serial: ' + (error.message || 'Server error');
                    if (source === 'modal' && serialModalStatus) {
                        serialModalStatus.textContent = errorMessage;
                        serialModalStatus.className = 'small mb-3 text-danger';
                    }
                    setCartStatus(errorMessage, 'text-danger', true);
                }
            }

            async function addProductToCart(product, source) {
                latestRequestId += 1;
                clearResults();
                if (searchInput) {
                    searchInput.value = '';
                }

                try {
                    const payload = {
                        product_id: Number(product.id),
                        qty: 1,
                    };

                    // If product was resolved via conversion barcode, include conversion_id
                    if (product.conversion && product.conversion.id) {
                        payload.conversion_id = Number(product.conversion.id);
                    }

                    const response = await jsonRequest(cartStoreLineEndpoint, 'POST', payload);

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
                    } else if (source === 'scan') {
                        setSearchStatus('Produk ditambahkan dari pindai.', 'text-success');
                    } else {
                        setSearchStatus('Produk ditambahkan ke keranjang.', 'text-success');
                    }

                    setCartStatus('Keranjang berhasil diperbarui.', 'text-success');
                } catch (error) {
                    setCartStatus(error.message || 'Gagal menambahkan produk ke keranjang.', 'text-danger');
                }
            }



            // Phase 3: Render search results in card-grid layout
            function renderSearchResultsModal(data) {
                if (!searchResultsModalContainer) {
                    return;
                }

                searchResultsModalContainer.innerHTML = '';

                const results = Array.isArray(data.results) ? data.results : [];
                const autoSelectId = data.meta && data.meta.auto_select_product_id ? Number(data.meta.auto_select_product_id) : null;

                // Auto-select if exact barcode match from search endpoint
                if (autoSelectId) {
                    const autoSelected = results.find((item) => Number(item.id) === autoSelectId);
                    if (autoSelected) {
                        addProductToCart(autoSelected, 'auto');
                        return;
                    }
                }

                // Show "not found" message if no results
                if (results.length === 0) {
                    const notFoundDiv = document.createElement('div');
                    notFoundDiv.className = 'text-muted text-center py-5';
                    notFoundDiv.textContent = 'Produk tidak ditemukan.';
                    searchResultsModalContainer.appendChild(notFoundDiv);
                    return;
                }

                // Render each result as a card in the grid
                results.forEach((product) => {
                    const card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'pos-search-card';
                    card.style.cssText = 'display: block; width: 100%; text-align: left; padding: 1rem; border: 1px solid #dee2e6; border-radius: 4px; background: white; cursor: pointer; transition: all 0.2s ease;';

                    const productName = escapeHtml(product.product_name);
                    const productCode = escapeHtml(product.product_code || '-');
                    const barcode = escapeHtml(product.barcode || '-');
                    const price = formatPrice(product.sale_price);

                    card.innerHTML = `
                        <!-- Image placeholder for future use -->
                        <div style="width: 100%; height: 80px; background-color: #f8f9fa; border-radius: 4px; margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: center; color: #999;">
                            <small>Gambar</small>
                        </div>
                        <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.5rem;">${productName}</div>
                        <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.75rem;">
                            <div>SKU: ${productCode}</div>
                            <div>Barcode: ${barcode}</div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; align-items: center; font-weight: 500; padding-top: 0.75rem; border-top: 1px solid #eee;">
                            <div style="text-align: right;">
                                <div style="font-size: 0.75rem; color: #999;">${price}</div>
                            </div>
                        </div>\n                    `;

                    // Hover effect
                    card.addEventListener('mouseenter', function () {
                        this.style.borderColor = '#007bff';
                        this.style.boxShadow = '0 0.125rem 0.25rem rgba(0,0,0,0.075)';
                        this.style.backgroundColor = '#f8f9ff';
                    });
                    card.addEventListener('mouseleave', function () {
                        this.style.borderColor = '#dee2e6';
                        this.style.boxShadow = 'none';
                        this.style.backgroundColor = 'white';
                    });

                    card.addEventListener('click', async function () {
                        // Close modal and let addProductToCart handle cleanup
                        if (searchResultsModalElement) {
                            try {
                                if (typeof jQuery !== 'undefined') {
                                    jQuery(searchResultsModalElement).modal('hide');
                                } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                    const modal = bootstrap.Modal.getInstance(searchResultsModalElement);
                                    if (modal) {
                                        modal.hide();
                                    }
                                }
                            } catch (e) {
                                console.error('Error closing modal:', e);
                            }
                        }
                        await addProductToCart(product, 'manual');
                    });

                    searchResultsModalContainer.appendChild(card);
                });

                // Setup keyboard navigation for the cards
                setupSearchResultsModalKeyboard();
            }



            // Phase 1: Execute search and show results in modal
            async function executeSearchModal(query) {
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

                    renderSearchResultsModal(data);
                    setSearchStatus('', 'text-muted');
                } catch (error) {
                    if (requestId !== latestRequestId) {
                        return;
                    }

                    clearResults();
                    setSearchStatus('Pencarian gagal: ' + (error.message || 'Server error'), 'text-danger');
                }
            }

            // Phase 3: Setup keyboard navigation for search results modal
            function setupSearchResultsModalKeyboard() {
                const items = searchResultsModalContainer ? Array.from(searchResultsModalContainer.querySelectorAll('button.pos-search-card')) : [];
                if (items.length === 0) {
                    return;
                }

                let currentFocusIndex = 0;

                // Focus first item on setup
                items[0].focus();

                // Key navigation handler
                function handleKeyNav(event) {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        currentFocusIndex = (currentFocusIndex + 1) % items.length;
                        items[currentFocusIndex].focus();
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        currentFocusIndex = (currentFocusIndex - 1 + items.length) % items.length;
                        items[currentFocusIndex].focus();
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        items[currentFocusIndex].click();
                    }
                }

                // Add keydown listeners to all items
                items.forEach((item, index) => {
                    item.addEventListener('keydown', handleKeyNav);
                    item.addEventListener('focus', () => {
                        currentFocusIndex = index;
                    });
                });
            }

            // Phase 3: Wire up modal keyboard navigation
            if (searchResultsModalElement) {
                searchResultsModalElement.addEventListener('shown.bs.modal', setupSearchResultsModalKeyboard);
            }

            // Phase 1: Enter key handler for scan resolver
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
                    const response = await jsonRequest(scanResolveEndpoint + '?q=' + encodeURIComponent(query), 'GET');
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
                    } else {
                        setSearchStatus('Kode tidak ditemukan.', 'text-warning');
                    }
                } catch (error) {
                    setSearchStatus('Pindai gagal: ' + (error.message || 'Server error'), 'text-danger');
                }
            });

            // Phase 3: Cari Produk button click handler
            if (cariProdukButton) {
                cariProdukButton.addEventListener('click', function () {
                    // Clear modal search input and results
                    if (modalSearchInput) {
                        modalSearchInput.value = '';
                    }
                    if (searchResultsModalContainer) {
                        searchResultsModalContainer.innerHTML = '';
                        const placeholderDiv = document.createElement('div');
                        placeholderDiv.className = 'text-muted text-center py-5';
                        placeholderDiv.textContent = 'Ketik nama produk atau SKU lalu tekan Cari.';
                        searchResultsModalContainer.appendChild(placeholderDiv);
                    }
                    
                    if (searchResultsModalElement) {
                        // Try jQuery first (most likely available)
                        try {
                            if (typeof jQuery !== 'undefined') {
                                jQuery(searchResultsModalElement).modal('show');
                                return;
                            }
                        } catch (e) {}
                        
                        // Fallback to Bootstrap 5
                        try {
                            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                const modal = new bootstrap.Modal(searchResultsModalElement);
                                modal.show();
                                return;
                            }
                        } catch (e) {}
                    }
                    
                    // Refocus scanner field on modal close
                    if (searchResultsModalElement) {
                        searchResultsModalElement.addEventListener('hidden.bs.modal', function refocusScanner() {
                            if (searchInput) {
                                searchInput.focus();
                            }
                            searchResultsModalElement.removeEventListener('hidden.bs.modal', refocusScanner);
                        }, { once: true });
                    }
                });
                
                // Phase 3: Auto-focus modal search input when modal opens
                if (searchResultsModalElement) {
                    searchResultsModalElement.addEventListener('shown.bs.modal', function () {
                        if (modalSearchInput) {
                            modalSearchInput.focus();
                        }
                    });
                }

                // Phase 4: Auto-focus serial modal input when modal opens
                if (serialModalElement) {
                    serialModalElement.addEventListener('shown.bs.modal', function () {
                        if (serialModalInput) {
                            serialModalInput.focus();
                        }
                        setSerialAppendInFlight(false);
                    });
                    
                    serialModalElement.addEventListener('hidden.bs.modal', function() {
                        currentSerialLineId = null;
                        if (serialModalInput) serialModalInput.value = '';
                        setSerialAppendInFlight(false);
                        if (serialModalStatus) {
                            serialModalStatus.textContent = '';
                            serialModalStatus.className = 'small mb-3';
                        }
                        if (searchInput) searchInput.focus();
                    });
                }
            }

            // Phase 4: Serial modal input keyboard handler
            if (serialModalInput) {
                serialModalInput.addEventListener('keydown', async function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        await submitSerialModalInput();
                    }
                });
            }

            if (serialModalSubmitButton) {
                serialModalSubmitButton.addEventListener('click', async function (event) {
                    event.preventDefault();
                    await submitSerialModalInput();
                });
            }

            if (serialModalList) {
                serialModalList.addEventListener('click', async function (event) {
                    const removeSerialBtn = event.target.closest('.js-serial-remove');
                    if (!removeSerialBtn) {
                        return;
                    }

                    event.preventDefault();
                    if (currentSerialLineId === null) {
                        if (serialModalStatus) {
                            serialModalStatus.textContent = 'Baris serial tidak aktif.';
                            serialModalStatus.className = 'small mb-3 text-danger';
                        }
                        return;
                    }

                    const serialNumber = removeSerialBtn.getAttribute('data-serial');
                    await removeSerialFromLine(currentSerialLineId, serialNumber, 'modal');
                });
            }

            // Phase 3: Modal search input/button event handlers
            function executeModalSearch() {
                const query = (modalSearchInput ? modalSearchInput.value : '').trim();
                if (!query) {
                    setSearchStatus('Masukkan kode pencarian.', 'text-muted');
                    return;
                }
                executeSearchModal(query);
            }

            if (modalSearchBtn) {
                modalSearchBtn.addEventListener('click', executeModalSearch);
            }

            if (modalSearchInput) {
                modalSearchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        executeModalSearch();
                    }
                });
            }

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
                if (!clearCartButton.hasAttribute('data-original-class')) {
                    clearCartButton.setAttribute('data-original-class', clearCartButton.className);
                }
                clearCartButton.addEventListener('click', async function () {
                    const btn = this;
                    const originalText = 'Kosongkan Keranjang';
                    
                    ApprovalManager.wrapAction(btn, originalText, 'CART_CLEAR', 'pos_session', window.posSessionId || 0, {}, async (token) => {
                        const payload = token ? { approval_token: token } : undefined;
                        const response = await jsonRequest(cartClearEndpoint, 'DELETE', payload);
                        if (response) {
                            renderCart(response.cart_snapshot || null);
                            setCartStatus('Keranjang dikosongkan.', 'text-success');
                        }
                    });
                });
            }

            if (saveDraftButton) {
                saveDraftButton.addEventListener('click', async function () {
                    const originalText = saveDraftButton.textContent;
                    saveDraftButton.disabled = true;
                    saveDraftButton.textContent = 'Menyimpan...';

                    try {
                        const response = await jsonRequest(saveAndNewEndpoint, 'POST');
                        await refreshCart();

                        const code = response && response.transaction ? response.transaction.code : '-';
                        setCartStatus('Transaksi ' + code + ' disimpan. Keranjang baru siap dipakai.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal menyimpan transaksi.', 'text-danger', true);
                    } finally {
                        saveDraftButton.disabled = false;
                        saveDraftButton.textContent = originalText;
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
                const userCanReduce = canReduceQuantity; // Privilege check

                if (!Number.isFinite(newQty) || newQty < 1) {
                    qtyInput.value = prevQty;
                    setCartStatus('Qty harus minimal 1.', 'text-danger', true);
                    return;
                }

                if (newQty === prevQty) {
                    return;
                }

                // Pattern 1: For non-privileged users, prevent direct reduction via input
                if (!userCanReduce && newQty < prevQty) {
                    qtyInput.value = prevQty;
                    qtyInput.setAttribute('data-prev-qty', String(prevQty));
                    setCartStatus('Gunakan tombol Kurangi untuk mengurangi jumlah.', 'text-warning', true);
                    return;
                }

                const applyQtyUpdate = async (token) => {
                    const payload = { qty: newQty };
                    if (token) payload.approval_token = token;

                    const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', payload);
                    if (!response) {
                        throw new Error('Gagal memperbarui qty.');
                    }

                    renderCart(response.cart_snapshot || null);
                    setCartStatus('Qty berhasil diperbarui.', 'text-success');
                };

                try {
                    // For privileged users: reduction triggers approval workflow
                    // For non-privileged users: only increases reach here (decreases are blocked above)
                    if (newQty < prevQty) {
                        const executed = await ApprovalManager.wrapAction(
                            qtyInput,
                            String(prevQty),
                            'QTY_REDUCE',
                            'pos_cart_line',
                            lineId,
                            { qty: newQty },
                            applyQtyUpdate
                        );
                        if (!executed) {
                            qtyInput.value = prevQty;
                            qtyInput.setAttribute('data-prev-qty', String(prevQty));
                        }
                    } else {
                        // Increase: apply immediately without approval
                        await applyQtyUpdate(null);
                        qtyInput.setAttribute('data-prev-qty', String(newQty));
                    }
                } catch (error) {
                    qtyInput.value = prevQty;
                    qtyInput.setAttribute('data-prev-qty', String(prevQty));
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
                    ApprovalManager.wrapAction(button, 'Hapus', 'LINE_REMOVE', 'pos_cart_line', lineId, {}, async (token) => {
                        const payload = token ? { approval_token: token } : undefined;
                        const response = await jsonRequest(getLineEndpoint(lineId), 'DELETE', payload);
                        if (response) {
                            renderCart(response.cart_snapshot || null);
                            setCartStatus('Baris keranjang dihapus.', 'text-success');
                        }
                    });
                }

                // Handle Reduce Quantity button click (non-privileged users only)
                if (button.classList.contains('js-reduce-qty')) {
                    const currentQty = Number(button.getAttribute('data-current-qty') || 0);
                    if (currentQty < 1) {
                        setCartStatus('Jumlah saat ini tidak valid.', 'text-danger', true);
                        return;
                    }

                    pendingReduceLineId = lineId;
                    pendingReduceCurrentQty = currentQty;
                    pendingReduceButton = button;  // Store the reduce button for approval tracking

                    // Reset modal
                    if (reduceQtyCurrent) reduceQtyCurrent.textContent = currentQty;
                    if (reduceQtyNewInput) {
                        reduceQtyNewInput.min = 1;
                        reduceQtyNewInput.max = currentQty - 1;
                        reduceQtyNewInput.value = Math.max(1, currentQty - 1);
                    }
                    if (reduceQtyReason) reduceQtyReason.value = '';
                    if (reduceQtyError) {
                        reduceQtyError.textContent = '';
                        reduceQtyError.style.display = 'none';
                    }
                    if (reduceQtySubmit) reduceQtySubmit.disabled = false;

                    // Open modal
                    if (reduceQuantityModal) {
                        $(reduceQuantityModal).modal('show');
                    }
                    return;
                }

                // Handle Check Approval / Proceed button (separate from reduce button)
                if (button.classList.contains('js-check-qty-approval')) {
                    const pendingRequestId = button.getAttribute('data-approval-pending');
                    const approvalToken = button.getAttribute('data-approval-token');
                    const approvedQty = Number(button.getAttribute('data-approved-qty') || 0);

                    if (pendingRequestId) {
                        // Check approval status
                        await ApprovalManager.checkApproval(button, '⏳ Periksa', pendingRequestId);

                        // Task 2.1: Fetch fresh cart snapshot from server after approval check
                        // The button state is temporary (set by checkApproval); the snapshot is authoritative
                        try {
                            const freshResponse = await jsonRequest(cartShowEndpoint, 'GET');
                            // Task 2.2 & 2.3: Pass fresh snapshot so line row re-renders with latest approval state
                            renderCart(freshResponse && freshResponse.cart_snapshot ? freshResponse.cart_snapshot : null);
                        } catch (error) {
                            // Task 2.4: Fallback - re-render with current snapshot if refresh fails
                            console.error('Failed to fetch fresh snapshot after approval check:', error);
                            renderCart(currentSnapshot);
                        }
                    } else if (approvalToken && approvedQty > 0) {
                        // Approved - proceed with reduction
                        const applyQtyUpdate = async (token) => {
                            const payload = { qty: approvedQty };
                            if (token) payload.approval_token = token;

                            const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', payload);
                            if (!response) {
                                throw new Error('Gagal memperbarui qty.');
                            }

                            // Task 2.3: Clear client-side pending before re-render so we don't show stale state
                            delete clientPendingApprovals[lineId];

                            renderCart(response.cart_snapshot || null);
                            setCartStatus('Qty berhasil diperbarui.', 'text-success');
                        };

                        try {
                            await ApprovalManager.wrapAction(
                                button,
                                '✓ Lanjutkan',
                                'QTY_REDUCE',
                                'pos_cart_line',
                                lineId,
                                { qty: approvedQty },
                                applyQtyUpdate
                            );
                        } catch (error) {
                            setCartStatus(error.message || 'Gagal memproses pengurangan.', 'text-danger', true);
                        }
                    }
                    return;
                }

                // Handle Quantity Increment button (+ spinner button)
                if (button.classList.contains('js-qty-increase')) {
                    const qtyInput = row.querySelector('.js-line-qty');
                    if (qtyInput) {
                        const currentQty = Number(qtyInput.value || 0);
                        const newQty = currentQty + 1;
                        qtyInput.value = newQty;
                        qtyInput.setAttribute('data-prev-qty', String(currentQty));
                        // Trigger change event to handle the update
                        qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    return;
                }

                // Handle Quantity Decrement button (- spinner button)
                if (button.classList.contains('js-qty-decrease')) {
                    const qtyInput = row.querySelector('.js-line-qty');
                    if (qtyInput) {
                        const currentQty = Number(qtyInput.value || 0);
                        if (currentQty > 1) {
                            const newQty = currentQty - 1;
                            qtyInput.value = newQty;
                            qtyInput.setAttribute('data-prev-qty', String(currentQty));
                            // Trigger change event to handle the update
                            qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    return;
                }
            });

            // Phase 3B: Add serial chip event handlers
            cartBody.addEventListener('click', async function (event) {
                // Handle + Serial button click
                const addSerialBtn = event.target.closest('.js-serial-add');
                if (addSerialBtn) {
                    const lineId = Number(addSerialBtn.getAttribute('data-line-id'));
                    const productName = String(addSerialBtn.getAttribute('data-product-name') || '').trim() || 'Produk';
                    
                    if (lineId) {
                        openSerialModal(lineId, productName);
                    }
                    return;
                }

                // Handle serial chip remove button click
                const removeSerialBtn = event.target.closest('.js-serial-remove');
                if (removeSerialBtn) {
                    const row = removeSerialBtn.closest('tr[data-line-id]');
                    if (!row) return;
                    
                    const lineId = Number(row.getAttribute('data-line-id'));
                    const serialNumber = removeSerialBtn.getAttribute('data-serial');
                    await removeSerialFromLine(lineId, serialNumber, 'cart');
                    return;
                }
            });

            // Quantity Reduction Modal validation and submission
            if (reduceQtyNewInput) {
                reduceQtyNewInput.addEventListener('input', function () {
                    const newQty = Number(this.value || 0);
                    const maxQty = pendingReduceCurrentQty ? (pendingReduceCurrentQty - 1) : 0;

                    if (!Number.isFinite(newQty) || newQty < 1) {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = `Jumlah harus minimal 1.`;
                            reduceQtyError.style.display = 'block';
                        }
                        if (reduceQtySubmit) reduceQtySubmit.disabled = true;
                    } else if (newQty > maxQty) {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = `Jumlah harus kurang dari ${pendingReduceCurrentQty}.`;
                            reduceQtyError.style.display = 'block';
                        }
                        if (reduceQtySubmit) reduceQtySubmit.disabled = true;
                    } else {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = '';
                            reduceQtyError.style.display = 'none';
                        }
                        if (reduceQtySubmit) reduceQtySubmit.disabled = false;
                    }
                });
            }

            if (reduceQtySubmit) {
                reduceQtySubmit.addEventListener('click', async function () {
                    if (!Number.isFinite(pendingReduceLineId) || pendingReduceLineId <= 0) {
                        setCartStatus('Baris keranjang tidak valid.', 'text-danger', true);
                        if (reduceQuantityModal) $(reduceQuantityModal).modal('hide');
                        return;
                    }

                    const newQty = Number(reduceQtyNewInput ? reduceQtyNewInput.value : 0);
                    const reason = reduceQtyReason ? reduceQtyReason.value.trim() || null : null;

                    if (!Number.isFinite(newQty) || newQty < 1 || newQty >= pendingReduceCurrentQty) {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = 'Input jumlah tidak valid.';
                            reduceQtyError.style.display = 'block';
                        }
                        return;
                    }

                    // Close modal before submitting
                    if (reduceQuantityModal) $(reduceQuantityModal).modal('hide');

                    // Call ApprovalManager with reduction request
                    const applyQtyUpdate = async (token) => {
                        const payload = { qty: newQty };
                        if (token) payload.approval_token = token;

                        const response = await jsonRequest(getLineEndpoint(pendingReduceLineId), 'PATCH', payload);
                        if (!response) {
                            throw new Error('Gagal memperbarui qty.');
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Qty berhasil diperbarui.', 'text-success');
                    };

                    try {
                        // Use the actual reduce button so approval state is stored on it
                        const buttonForApproval = pendingReduceButton || reduceQtySubmit;
                        const originalButtonText = '↓ Kurangi';
                        const lineIdForStorage = pendingReduceLineId;
                        const requestedQtyForStorage = newQty;

                        await ApprovalManager.wrapAction(
                            buttonForApproval,
                            originalButtonText,
                            'QTY_REDUCE',
                            'pos_cart_line',
                            pendingReduceLineId,
                            { qty: newQty, reason: reason },
                            applyQtyUpdate
                        );

                        // After ApprovalManager sets the pending state, capture it and re-render cart
                        // to immediately show the "Periksa" button
                        const pendingRequestId = buttonForApproval.getAttribute('data-approval-pending');
                        if (pendingRequestId) {
                            // Store in client-side pending approvals
                            clientPendingApprovals[lineIdForStorage] = {
                                requestId: pendingRequestId,
                                requestedQty: requestedQtyForStorage,
                                status: 'PENDING'
                            };
                            // Task 2.1: Fetch fresh cart snapshot from server (includes pending_approvals)
                            // before re-rendering to ensure the button renders with correct state
                            try {
                                const freshResponse = await jsonRequest(cartShowEndpoint, 'GET');
                                // Task 2.1: Ensure we pass cart_snapshot, not the wrapper response
                                renderCart(freshResponse && freshResponse.cart_snapshot ? freshResponse.cart_snapshot : null);
                            } catch (error) {
                                // Task 2.2: On refresh failure, still re-render with current snapshot
                                console.error('Failed to fetch fresh cart snapshot:', error);
                                // Fallback: re-render with current snapshot and client-side tracking
                                renderCart(currentSnapshot);
                            }
                        }
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal memproses permintaan pengurangan.', 'text-danger', true);
                    } finally {
                        pendingReduceLineId = null;
                        pendingReduceCurrentQty = null;
                        pendingReduceButton = null;
                    }
                });
            }

            // Handle modal close/reset
            if (reduceQuantityModal) {
                reduceQuantityModal.addEventListener('hidden.bs.modal', function () {
                    pendingReduceLineId = null;
                    pendingReduceCurrentQty = null;
                    pendingReduceButton = null;
                    if (reduceQtyError) {
                        reduceQtyError.textContent = '';
                        reduceQtyError.style.display = 'none';
                    }
                });
            }

            // Cash Pickup Modal
            const pickupModalElement = document.getElementById('pos-cash-pickup-modal');
            const pickupBtn = document.getElementById('pos-cash-pickup-btn');
            const pickupStep1 = document.getElementById('pos-pickup-step-1');
            const pickupStep2 = document.getElementById('pos-pickup-step-2');
            const pickupStep1Footer = document.getElementById('pos-pickup-step-1-footer');
            const pickupStep2Footer = document.getElementById('pos-pickup-step-2-footer');
            const pickupTerminalInfo = document.getElementById('pos-pickup-terminal-info');
            const pickupCashierInfo = document.getElementById('pos-pickup-cashier-info');
            const pickupExpectedCash = document.getElementById('pos-pickup-expected-cash');
            const pickupAmountInput = document.getElementById('pos-pickup-amount');
            const pickupAmountError = document.getElementById('pos-pickup-amount-error');
            const pickupNextBtn = document.getElementById('pos-pickup-next-btn');
            const pickupBackBtn = document.getElementById('pos-pickup-back-btn');
            const pickupConfirmBtn = document.getElementById('pos-pickup-confirm-btn');
            const pickupSupervisorEmail = document.getElementById('pos-pickup-supervisor-email');
            const pickupSupervisorPassword = document.getElementById('pos-pickup-supervisor-password');
            const pickupConfirmationAmount = document.getElementById('pos-pickup-confirmation-amount');
            const pickupStep2Error = document.getElementById('pos-pickup-step2-error');
            const pickupSpinner = document.getElementById('pos-pickup-spinner');
            let currentSessionData = null;

            function showPickupStep1() {
                pickupStep1.classList.remove('d-none');
                pickupStep2.classList.add('d-none');
                pickupStep1Footer.classList.remove('d-none');
                pickupStep2Footer.classList.add('d-none');
            }

            function showPickupStep2() {
                pickupStep1.classList.add('d-none');
                pickupStep2.classList.remove('d-none');
                pickupStep1Footer.classList.add('d-none');
                pickupStep2Footer.classList.remove('d-none');
                pickupSupervisorEmail.focus();
            }

            function validatePickupAmount() {
                const amount = Number(pickupAmountInput.value || 0);
                const expectedCash = currentSessionData && currentSessionData.expected_cash ? Number(currentSessionData.expected_cash) : 0;

                pickupAmountError.classList.add('d-none');
                pickupNextBtn.disabled = true;

                if (amount <= 0) {
                    pickupAmountError.textContent = 'Jumlah pengambilan harus lebih dari 0.';
                    pickupAmountError.classList.remove('d-none');
                    return false;
                }

                if (amount > expectedCash) {
                    pickupAmountError.textContent = 'Jumlah pengambilan tidak boleh melebihi ekspektasi kas.';
                    pickupAmountError.classList.remove('d-none');
                    return false;
                }

                pickupNextBtn.disabled = false;
                return true;
            }

            function formatPrice(amount) {
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    maximumFractionDigits: 0
                }).format(amount || 0);
            }

            if (pickupAmountInput && pickupNextBtn) {
                pickupAmountInput.addEventListener('input', validatePickupAmount);
                pickupAmountInput.addEventListener('change', validatePickupAmount);
            }

            if (pickupNextBtn) {
                pickupNextBtn.addEventListener('click', function () {
                    if (!validatePickupAmount()) return;

                    const amount = Number(pickupAmountInput.value || 0);
                    pickupConfirmationAmount.innerHTML = `<span>Rp ${amount.toLocaleString('id-ID')}</span>`;

                    // Reset step 2 inputs
                    pickupSupervisorEmail.value = '';
                    pickupSupervisorPassword.value = '';
                    pickupStep2Error.classList.add('d-none');
                    pickupConfirmBtn.disabled = false;

                    showPickupStep2();
                });
            }

            if (pickupBackBtn) {
                pickupBackBtn.addEventListener('click', function () {
                    showPickupStep1();
                });
            }

            if (pickupConfirmBtn) {
                pickupConfirmBtn.addEventListener('click', async function () {
                    const email = (pickupSupervisorEmail.value || '').trim();
                    const password = (pickupSupervisorPassword.value || '').trim();

                    pickupStep2Error.classList.add('d-none');

                    if (!email) {
                        pickupStep2Error.textContent = 'Email supervisor wajib diisi.';
                        pickupStep2Error.classList.remove('d-none');
                        return;
                    }

                    if (!password) {
                        pickupStep2Error.textContent = 'Password wajib diisi.';
                        pickupStep2Error.classList.remove('d-none');
                        return;
                    }

                    pickupConfirmBtn.disabled = true;
                    if (pickupSpinner) pickupSpinner.classList.remove('d-none');

                    try {
                        const sessionId = currentSessionData && currentSessionData.session_id ? currentSessionData.session_id : null;
                        if (!sessionId) {
                            throw new Error('Session ID tidak ditemukan.');
                        }

                        const amount = Number(pickupAmountInput.value || 0);
                        const endpoint = `{{ url('/pos/sessions') }}/${sessionId}/pickup`;

                        const response = await jsonRequest(endpoint, 'POST', {
                            amount: amount,
                            supervisor_email: email,
                            supervisor_password: password
                        });

                        if (response) {
                            $(pickupModalElement).modal('hide');
                            const newExpectedCash = response.expected_cash_after || 0;
                            const message = `Pengambilan kas berhasil. Ekspektasi kas: ${formatPrice(newExpectedCash)}`;
                            showToast(message, 'success');

                            // Refresh cart to update expected cash display
                            refreshCart();
                        }
                    } catch (error) {
                        pickupStep2Error.textContent = error.message || 'Gagal memproses pengambilan kas.';
                        pickupStep2Error.classList.remove('d-none');
                    } finally {
                        pickupConfirmBtn.disabled = false;
                        if (pickupSpinner) pickupSpinner.classList.add('d-none');
                    }
                });
            }

            if (pickupBtn) {
                pickupBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    // Get dropdown menu element with session data attributes
                    const dropdownMenu = document.querySelector('.dropdown-menu[data-session-id]');
                    if (dropdownMenu) {
                        currentSessionData = {
                            session_id: Number(dropdownMenu.getAttribute('data-session-id')),
                            terminal_code: dropdownMenu.getAttribute('data-terminal-code') || '-',
                            terminal_name: dropdownMenu.getAttribute('data-terminal-name') || '-',
                            cashier_name: dropdownMenu.getAttribute('data-cashier-name') || '-',
                            expected_cash: Number(dropdownMenu.getAttribute('data-expected-cash') || 0)
                        };

                        const terminal = `${currentSessionData.terminal_code} - ${currentSessionData.terminal_name}`;
                        if (pickupTerminalInfo) pickupTerminalInfo.textContent = terminal;
                        if (pickupCashierInfo) pickupCashierInfo.textContent = currentSessionData.cashier_name;
                        if (pickupExpectedCash) pickupExpectedCash.textContent = formatPrice(currentSessionData.expected_cash);

                        pickupAmountInput.value = '';
                        pickupAmountInput.max = currentSessionData.expected_cash;
                        pickupAmountError.classList.add('d-none');
                        pickupNextBtn.disabled = true;

                        showPickupStep1();
                        if (pickupModalElement) {
                            if (typeof $ !== 'undefined') {
                                $(pickupModalElement).modal('show');
                            } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                new bootstrap.Modal(pickupModalElement).show();
                            }
                        }
                        if (pickupAmountInput) pickupAmountInput.focus();

                        // Close dropdown if it's open
                        const dropdownToggle = document.getElementById('pos-nav-menu-dropdown');
                        if (dropdownToggle && typeof $ !== 'undefined') {
                            $(dropdownToggle).dropdown('toggle');
                        }
                    }
                });
            }

            if (pickupModalElement) {
                pickupModalElement.addEventListener('hidden.bs.modal', function () {
                    currentSessionData = null;
                    pickupAmountInput.value = '';
                    pickupSupervisorEmail.value = '';
                    pickupSupervisorPassword.value = '';
                    pickupStep2Error.classList.add('d-none');
                    pickupAmountError.classList.add('d-none');
                    showPickupStep1();
                });
            }

            // Close Session Handler
            const closeSessionBtn = document.getElementById('pos-close-session-btn');
            let closeSessionData = null;

            if (closeSessionBtn) {
                closeSessionBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    console.log('[POS Session] Close session button clicked');
                    // Get dropdown menu element with session data attributes
                    const dropdownMenu = document.querySelector('.dropdown-menu[data-session-id]');
                    if (dropdownMenu) {
                        closeSessionData = {
                            session_id: Number(dropdownMenu.getAttribute('data-session-id')),
                        };

                        console.log('[POS Session] Closing session', closeSessionData);

                        // Close dropdown if it's open
                        const dropdownToggle = document.getElementById('pos-nav-menu-dropdown');
                        if (dropdownToggle && typeof $ !== 'undefined') {
                            $(dropdownToggle).dropdown('toggle');
                        }

                        const closeBtn = closeSessionBtn;
                        closeBtn.disabled = true;
                        const originalText = closeBtn.innerHTML;
                        closeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';

                        fetch(`/pos/sessions/${closeSessionData.session_id}/close`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        })
                            .then(response => {
                                console.log('[POS Session] Close session response', {
                                    status: response.status,
                                    statusText: response.statusText,
                                });

                                if (!response.ok) {
                                    return response.json().then(data => {
                                        throw new Error(data.message || 'Gagal menutup sesi');
                                    });
                                }
                                return response.json();
                            })
                            .then(data => {
                                console.log('[POS Session] Close session successful', data);
                                alert('Terminal berhasil dirilis. Silakan buka sesi baru atau keluar.');
                                window.location.href = '{{ route("home") }}';
                            })
                            .catch(error => {
                                console.error('[POS Session] Close session error', error);
                                alert(error.message || 'Gagal menutup sesi');
                                closeBtn.disabled = false;
                                closeBtn.innerHTML = originalText;
                            });
                    } else {
                        console.warn('[POS Session] Dropdown menu not found');
                    }
                });
            }

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

            // Task 4.2: Select payment method and add to composer (not replace)
            function selectPaymentMethod(method) {
                addPaymentRow(method);
            }

            function openPaymentModal() {
                if (!currentSnapshot || !currentSnapshot.totals) return;

                const grandTotal = Number(currentSnapshot.totals.grand_total || 0);
                if (grandTotal <= 0) return;

                checkoutTotalLabel.value = formatPrice(grandTotal);
                checkoutError.classList.add('d-none');
                checkoutError.textContent = '';

                // Task 4.1: Reset payment composer
                checkoutPayments = [];
                renderPaymentsList();
                updatePaymentSummary();

                renderReceiptPreview(currentSnapshot);

                // Load payment methods before showing modal
                (async () => {
                    const loaded = await loadPaymentMethods();
                    if (!loaded || cachedPaymentMethods.length === 0) {
                        checkoutError.textContent = 'Tidak ada metode pembayaran yang diaktifkan untuk unit ini. Atur di Konfigurasi Pembayaran POS.';
                        checkoutError.classList.remove('d-none');
                        if (checkoutSubmit) checkoutSubmit.disabled = true;
                    }
                })();

                $(checkoutModalElement).modal('show');
                setTimeout(() => checkoutMethodSearch.focus(), 200);
            }

            // Task 4.2: Payment method search handler for composer
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

            if (btnCheckout) {
                btnCheckout.addEventListener('click', function () {
                    console.log('[CHECKOUT] Button clicked', { currentSnapshot, PosStagedPayment: typeof PosStagedPayment });

                    // Wire to staged payment flow using cart token and grand total
                    if (currentSnapshot && currentSnapshot.totals) {
                        // Generate token if it doesn't exist yet
                        let cartToken = currentSnapshot.staged_payment_token;
                        if (!cartToken) {
                            cartToken = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                                const r = Math.random() * 16 | 0;
                                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                                return v.toString(16);
                            });
                            currentSnapshot.staged_payment_token = cartToken;
                            console.log('[CHECKOUT] Generated new token:', cartToken);
                        }

                        const grandTotal = currentSnapshot.totals.grand_total || 0;
                        console.log('[CHECKOUT] Opening modal with token:', cartToken, 'grandTotal:', grandTotal);

                        if (typeof PosStagedPayment !== 'undefined') {
                            PosStagedPayment.openModal(cartToken, grandTotal);
                        } else {
                            console.error('[CHECKOUT] PosStagedPayment module not loaded');
                        }
                    } else {
                        console.warn('[CHECKOUT] No snapshot or totals available');
                    }
                });
            }

            // Task 4.4: Checkout submit with multi-payment payload
            if (checkoutSubmit) {
                checkoutSubmit.addEventListener('click', async function () {
                    // Validate before submit
                    if (!validatePaymentComposer()) {
                        return;
                    }

                    checkoutSubmit.disabled = true;
                    checkoutSubmit.textContent = 'Memproses...';
                    checkoutError.classList.add('d-none');

                    try {
                        // Task 4.4: Build payments[] array payload (multi-payment support)
                        // Also include legacy payment field for fallback compatibility
                        const payments = checkoutPayments.map(p => ({
                            payment_method_id: p.method.id,
                            amount_paid: Number(p.amount),
                            reference: p.reference || null
                        }));

                        const payload = {
                            idempotency_key: generateIdempotencyKey(),
                            payments: payments,
                            // Legacy compatibility: use first payment method as fallback
                            payment: {
                                payment_method_id: checkoutPayments[0]?.method?.id,
                                amount_paid: checkoutPayments[0]?.amount || 0,
                                reference: checkoutPayments[0]?.reference || null
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

            // Initialize Staged Payment Module
            if (typeof PosStagedPayment !== 'undefined') {
                PosStagedPayment.initialize({
                    modalElement: document.getElementById('pos-staged-checkout-modal'),
                    methodSearchInput: document.getElementById('staged-method-search'),
                    methodResults: document.getElementById('staged-method-results'),
                    paymentChainList: document.getElementById('staged-payment-chain'),
                    remainderLabel: document.getElementById('staged-remainder-amount'),
                    amountInput: document.getElementById('staged-amount-input'),
                    edcRefInput: document.getElementById('staged-edc-reference'),
                    edcRefContainer: document.getElementById('staged-edc-reference-container'),
                    submitButton: document.getElementById('staged-payment-submit'),
                    spinner: document.getElementById('staged-payment-spinner'),
                    errorAlert: document.getElementById('staged-payment-error'),
                });

                // Load payment methods
                if (typeof window.POS_PAYMENT_METHODS !== 'undefined') {
                    PosStagedPayment.setPaymentMethods(window.POS_PAYMENT_METHODS);
                } else {
                    // Fallback: load payment methods from API
                    PosStagedPayment.loadPaymentMethods();
                }

                // Set onComplete callback to trigger finalize
                PosStagedPayment.setOnComplete(async function(changeAmount) {
                    const gratitudeModal = document.getElementById('pos-gratitude-modal');
                    const gratitudeBtn = document.getElementById('pos-gratitude-continue-btn');

                    console.log('[GRATITUDE SETUP] Button found:', !!gratitudeBtn, 'Button element:', gratitudeBtn);

                    if (gratitudeBtn) {
                        gratitudeBtn.addEventListener('click', async function(e) {
                            console.log('[GRATITUDE] Button clicked!');
                            e.preventDefault();

                            // Call finalize endpoint
                            try {
                                console.log('[GRATITUDE] Finalizing with cart_token:', currentSnapshot?.staged_payment_token);

                                const response = await fetch('/pos/sell/checkout/finalize', {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        cart_token: currentSnapshot?.staged_payment_token,
                                        idempotency_key: 'FINALIZE-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9),
                                    }),
                                });

                                const data = await response.json();
                                console.log('[GRATITUDE] Finalize response:', { status: response.status, ok: response.ok, data });

                                if (response.ok && response.status === 201) {
                                    // Success: close modal, open receipt, refresh cart
                                    $(gratitudeModal).modal('hide');

                                    // Open receipt in new tab - check for checkout ID in different possible locations
                                    const checkoutId = data.payload?.checkout?.id || data.checkout?.id || data.pos_checkout_id;
                                    if (checkoutId) {
                                        window.open(`/pos/sell/checkout/${checkoutId}/receipt`, '_blank');
                                    }

                                    // Refresh cart to clear it
                                    await refreshCart();
                                } else {
                                    // Show error
                                    console.error('[GRATITUDE] Finalize failed:', data);
                                    alert('Gagal menyelesaikan pembayaran: ' + (data.message || 'Kesalahan tidak diketahui'));
                                }
                            } catch (error) {
                                console.error('[GRATITUDE] Error during finalize:', error);
                                alert('Terjadi kesalahan: ' + error.message);
                            }
                        });
                    }
                });

                // Wire close button to clear payment chain
                const closeBtn = document.getElementById('staged-payment-close-btn');
                if (closeBtn) {
                    closeBtn.addEventListener('click', async function() {
                        if (currentSnapshot?.staged_payment_token) {
                            try {
                                await fetch('/pos/sell/checkout/payment-chain', {
                                    method: 'DELETE',
                                    headers: {
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        cart_token: currentSnapshot.staged_payment_token,
                                    }),
                                });
                            } catch (error) {
                                console.error('Error clearing payment chain:', error);
                            }
                        }
                        document.getElementById('pos-staged-checkout-modal').modal('hide');
                    });
                }

                // Check for reload recovery on page load
                if (currentSnapshot && currentSnapshot.staged_payment_token) {
                    fetch(`/pos/sell/checkout/payment-chain?cart_token=${encodeURIComponent(currentSnapshot.staged_payment_token)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.has_chain && data.payment_chain) {
                                PosStagedPayment.openModal(
                                    currentSnapshot.staged_payment_token,
                                    currentSnapshot.totals?.grand_total || 0
                                );
                            }
                        })
                        .catch(e => console.error('Reload recovery error:', e));
                }
            }

            refreshCart();
        })();
    </script>
@endpush
@endsection
