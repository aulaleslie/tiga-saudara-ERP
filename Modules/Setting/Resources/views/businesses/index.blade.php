@extends('layouts.app')

@section('title', 'Peran & Izin')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Peran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <!-- Button trigger modal -->
                        <a href="{{ route('businesses.create') }}" class="btn btn-primary">
                            Tambah Bisnis <i class="bi bi-plus"></i>
                        </a>

                        <hr>

                        <div class="table-responsive">
                            {!! $dataTable->table() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @include('components.delete-modal', [
    'title' => 'Penonaktifan Toko',
    'message' => 'Apakah anda yakin untuk menonaktifkan toko ini?'
    ])
@endsection

@push('page_scripts')
    {!! $dataTable->scripts() !!}
@endpush
