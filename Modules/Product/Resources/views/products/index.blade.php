@extends('layouts.app')

@section('title', 'Products')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Produk</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can("products.create")
                            <a href="{{ route('products.create') }}" class="btn btn-primary">
                                Tambah Produk <i class="bi bi-plus"></i>
                            </a>
                            <a href="{{ route('products.upload') }}" class="btn btn-secondary">
                                Upload Produk <i class="bi bi-upload"></i>
                            </a>
                        @endcan

                        <hr>

                        <div class="table-responsive">
                            {!! $dataTable->table() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    {!! $dataTable->scripts() !!}
@endpush
