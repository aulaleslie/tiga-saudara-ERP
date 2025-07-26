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
                        @canany('purchase.create')
                            <a href="{{ route('purchases.create') }}" class="btn btn-primary">
                                Tambahkan Pembelian <i class="bi bi-plus"></i>
                            </a>
                        @endcanany

                        <hr>

                        <div class="table-responsive">
                            <livewire:purchase.purchase-table />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
