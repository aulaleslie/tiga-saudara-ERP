@extends('layouts.app')

@section('title', 'Retur Pembelian')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Retur Pembelian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can('purchaseReturns.create')
                        <a href="{{ route('purchase-returns.create') }}" class="btn btn-primary">
                            Tambahkan Retur Pembelian <i class="bi bi-plus"></i>
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
    <script>
        function openApproveModal(url) {
            $('#approvePurchaseReturnFormIndex').attr('action', url);
            $('#approvePurchaseReturnModalIndex').modal('show');
        }

        function openRejectModal(url) {
            $('#rejectPurchaseReturnFormIndex').attr('action', url);
            $('#rejectPurchaseReturnModalIndex').modal('show');
        }
    </script>
@endpush

@include('purchasesreturn::partials.approve-modal-index')
@include('purchasesreturn::partials.reject-modal-index')
