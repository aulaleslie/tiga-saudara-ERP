@extends('layouts.app')

@section('title', 'Home')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item active">Home</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h3 class="mb-0 text-primary font-weight-bold">{{ $greetingText }}</h3>
                    </div>
                </div>
            </div>
        </div>

        @php
            $currentSetting = settings();
            $posEnabled = (bool) ($currentSetting->pos_enabled ?? false);

            $canCreatePurchase = auth()->user()->can('purchases.create');
            $canCreateSale = auth()->user()->can('sales.create');
            $canOpenPosSession = $posEnabled && auth()->user()->can('pos.access') && auth()->user()->can('pos.sessions.open');
            $canGlobalPurchasePayment = auth()->user()->can('purchasePayments.global.access') && auth()->user()->can('purchasePayments.create');
            $canGlobalSalePayment = auth()->user()->can('salePayments.global.access') && auth()->user()->can('salePayments.create');

            $hasAnyQuickAccess = $canCreatePurchase || $canCreateSale || $canOpenPosSession || $canGlobalPurchasePayment || $canGlobalSalePayment;
        @endphp

        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header font-weight-bold">
                        Akses Cepat
                    </div>
                    <div class="card-body">
                        @if($hasAnyQuickAccess)
                            <div class="d-flex flex-wrap gap-2" style="gap: 10px;">
                                @if($canCreatePurchase)
                                    <a href="{{ route('purchases.create') }}" class="btn btn-primary">
                                        <i class="bi bi-journal-plus mr-1"></i> Buat Pembelian
                                    </a>
                                @endif

                                @if($canCreateSale)
                                    <a href="{{ route('sales.create') }}" class="btn btn-success">
                                        <i class="bi bi-journal-plus mr-1"></i> Buat Penjualan
                                    </a>
                                @endif

                                @if($canOpenPosSession)
                                    <a href="{{ route('pos.sessions.create') }}" class="btn btn-info text-white">
                                        <i class="bi bi-cash-stack mr-1"></i> Buka Sesi POS
                                    </a>
                                @endif

                                @if($canGlobalPurchasePayment)
                                    <a href="{{ route('purchases.global-payments.index') }}" class="btn btn-warning text-white">
                                        <i class="bi bi-cash-stack mr-1"></i> Buat Pembayaran Pembelian Global
                                    </a>
                                @endif

                                @if($canGlobalSalePayment)
                                    <a href="{{ route('sales.global-payments.index') }}" class="btn btn-secondary">
                                        <i class="bi bi-cash-stack mr-1"></i> Buat Pembayaran Penjualan Global
                                    </a>
                                @endif
                            </div>
                        @else
                            <p class="text-muted mb-0">Tidak ada tindakan akses cepat yang tersedia.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
