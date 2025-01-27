@extends('layouts.app')

@section('title', 'Purchases')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
@endsection

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
                            {!! $dataTable->table() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <style>
        .note-wrapper {
            max-height: 40px; /* Default height when collapsed */
            overflow: hidden;
            transition: max-height 0.3s ease-in-out;
        }

        .note-content {
            white-space: pre-wrap; /* Preserve whitespace and line breaks */
            word-wrap: break-word; /* Handle long words properly */
        }
    </style>
@endsection

@push('page_scripts')
    {!! $dataTable->scripts() !!}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Use event delegation to handle dynamically loaded "Read more" links
            document.querySelector('#purchases-table').addEventListener('click', function (event) {
                if (event.target && event.target.classList.contains('toggle-note')) {
                    const wrapper = event.target.previousElementSibling; // Get the note wrapper
                    const isCollapsed = wrapper.style.maxHeight === '40px';

                    if (isCollapsed) {
                        // Expand the note
                        wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
                        event.target.textContent = 'Sembunyikan'; // Change link text
                    } else {
                        // Collapse the note
                        wrapper.style.maxHeight = '40px';
                        event.target.textContent = 'Lihat selengkapnya'; // Change link text
                    }
                }
            });
        });
    </script>
@endpush
