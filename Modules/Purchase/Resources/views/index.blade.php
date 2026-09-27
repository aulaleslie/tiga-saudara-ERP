@extends('layouts.app')

@section('title', 'Purchases')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Purchases</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can('purchases.create')
                            <a href="{{ route('purchases.create') }}" class="btn btn-primary">
                                Tambahkan Pembelian <i class="bi bi-plus"></i>
                            </a>
                        @endcan
                        @can('purchases.import')
                            <a href="{{ route('purchases.imports.index') }}" class="btn btn-secondary">
                                Upload Pembelian <i class="bi bi-upload"></i>
                            </a>
                        @endcan

                        <hr>

                        <livewire:purchase.purchase-summary-cards />

                        <div class="table-responsive" style="min-height: 300px;">
                            <livewire:purchase.purchase-table />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('purchases.receive.complete_shortfall')
        <livewire:purchase.modals.purchase-receiving-completion-modal />
    @endcan
@endsection
