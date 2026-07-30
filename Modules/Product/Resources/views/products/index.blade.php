@extends('layouts.app')

@section('title', 'Products')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@push('page_css')
    <style>
        .dataTables_scroll {
            --image-col-width: 80px;
            --code-col-left: 80px;
        }

        #product-table td:nth-child(1),
        #product-table th:nth-child(1) {
            position: sticky;
            left: 0;
            width: var(--image-col-width);
            z-index: 1;
            background-color: #fff;
        }

        @media (prefers-color-scheme: dark) {
            #product-table td:nth-child(1),
            #product-table th:nth-child(1) {
                background-color: #1f2937;
            }
        }

        #product-table td:nth-child(2),
        #product-table th:nth-child(2) {
            position: sticky;
            left: var(--code-col-left);
            z-index: 1;
            background-color: #fff;
        }

        @media (prefers-color-scheme: dark) {
            #product-table td:nth-child(2),
            #product-table th:nth-child(2) {
                background-color: #1f2937;
            }
        }

        .dataTables_scrollHead table td:nth-child(1),
        .dataTables_scrollHead table th:nth-child(1) {
            position: sticky;
            left: 0;
            width: var(--image-col-width);
            z-index: 1;
            background-color: #fff;
        }

        @media (prefers-color-scheme: dark) {
            .dataTables_scrollHead table td:nth-child(1),
            .dataTables_scrollHead table th:nth-child(1) {
                background-color: #1f2937;
            }
        }

        .dataTables_scrollHead table td:nth-child(2),
        .dataTables_scrollHead table th:nth-child(2) {
            position: sticky;
            left: var(--code-col-left);
            z-index: 1;
            background-color: #fff;
        }

        @media (prefers-color-scheme: dark) {
            .dataTables_scrollHead table td:nth-child(2),
            .dataTables_scrollHead table th:nth-child(2) {
                background-color: #1f2937;
            }
        }
    </style>
@endpush

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
                            <a href="{{ route('products.imports.index') }}" class="btn btn-secondary">
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
