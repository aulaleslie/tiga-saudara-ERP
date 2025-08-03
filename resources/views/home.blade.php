@extends('layouts.app')

@section('title', 'Home')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item active">Home</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            @can('reports.access')
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0">
                        <div class="card-body p-0 d-flex align-items-center shadow-sm">
                            <div class="bg-gradient-primary p-4 mfe-3 rounded-left">
                                <i class="bi bi-bar-chart font-2xl"></i>
                            </div>
                            <div>
                                <div class="text-value text-primary">{{ format_currency($revenue) }}</div>
                                <div class="text-muted text-uppercase font-weight-bold small">Pendapatan</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endcan

            @can('reports.access')
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0">
                        <div class="card-body p-0 d-flex align-items-center shadow-sm">
                            <div class="bg-gradient-warning p-4 mfe-3 rounded-left">
                                <i class="bi bi-arrow-return-left font-2xl"></i>
                            </div>
                            <div>
                                <div class="text-value text-warning">{{ format_currency($sale_returns) }}</div>
                                <div class="text-muted text-uppercase font-weight-bold small">Penjualan</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endcan

            @can('reports.access')
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0">
                        <div class="card-body p-0 d-flex align-items-center shadow-sm">
                            <div class="bg-gradient-success p-4 mfe-3 rounded-left">
                                <i class="bi bi-arrow-return-right font-2xl"></i>
                            </div>
                            <div>
                                <div class="text-value text-success">{{ format_currency($purchase_returns) }}</div>
                                <div class="text-muted text-uppercase font-weight-bold small">Pembelian</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endcan

{{--            <div class="col-md-6 col-lg-3">--}}
{{--                <div class="card border-0">--}}
{{--                    <div class="card-body p-0 d-flex align-items-center shadow-sm">--}}
{{--                        <div class="bg-gradient-info p-4 mfe-3 rounded-left">--}}
{{--                            <i class="bi bi-trophy font-2xl"></i>--}}
{{--                        </div>--}}
{{--                        <div>--}}
{{--                            <div class="text-value text-info">{{ format_currency($profit) }}</div>--}}
{{--                            <div class="text-muted text-uppercase font-weight-bold small">Management</div>--}}
{{--                        </div>--}}
{{--                    </div>--}}
{{--                </div>--}}
{{--            </div>--}}
        </div>

        @can('reports.access')
            <div class="row mb-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header">
                            Penjualan & Pembelian 7 Hari Terakhir
                        </div>
                        <div class="card-body">
                            <canvas id="salesPurchasesChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header">
                            Sekilas tentang {{ now()->format('F, Y') }}
                        </div>
                        <div class="card-body d-flex justify-content-center">
                            <div class="chart-container" style="position: relative; height:auto; width:280px">
                                <canvas id="currentMonthChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endcan

        @can('reports.access')
            <div class="row">
                <div class="col-lg-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header">
                            Arus Kas Bulanan (Pembayaran Dikirim & Diterima)
                        </div>
                        <div class="card-body">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        @endcan
    </div>
@endsection

@section('third_party_scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.5.0/chart.min.js"
            integrity="sha512-asxKqQghC1oBShyhiBwA+YgotaSYKxGP1rcSYTDrB0U6DxwlJjU59B67U8+5/++uFjcuVM8Hh5cokLjZlhm3Vg=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
@endsection

@push('page_scripts')
    @vite('resources/js/chart-config.js')
@endpush
