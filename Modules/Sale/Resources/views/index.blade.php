@extends('layouts.app')

@section('title', 'Sales')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Penjualan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can("sales.create")
                        <a href="{{ route('sales.create') }}" class="btn btn-primary">
                            Tambahkan Penjualan <i class="bi bi-plus"></i>
                        </a>
                        @endcan
                        @can("sales.import")
                        <a href="{{ route('sales.imports.index') }}" class="btn btn-outline-primary">
                            Import Penjualan <i class="bi bi-upload"></i>
                        </a>
                        @endcan

                        <hr>

                        <livewire:sale.sale-summary-cards />

                        <div class="table-responsive">
                            <livewire:sale.sale-table />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

