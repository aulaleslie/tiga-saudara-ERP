@extends('layouts.app')

@section('title', 'Term Pembayaran')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Term Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <a href="{{ route('payment-terms.create') }}" class="btn btn-primary">
                            Buat Term Pembayaran <i class="bi bi-plus"></i>
                        </a>

                        <hr>

                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 text-center" id="data-table">
                                <thead>
                                <tr>
                                    <th class="align-middle">No.</th>
                                    <th class="align-middle">Nama Term Pembayaran</th>
                                    <th class="align-middle">Tempo (hari)</th>
                                    <th class="align-middle">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($payment_terms as $key => $payment_term)
                                    <tr>
                                        <td class="align-middle">{{ $key + 1 }}</td>
                                        <td class="align-middle">{{ $payment_term->name }}</td>
                                        <td class="align-middle">{{ $payment_term->longevity }}</td>
                                        <td class="align-middle">
                                            <a href="{{ route('payment-terms.edit', $payment_term) }}" class="btn btn-info btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button class="btn btn-danger btn-sm"
                                                    onclick="showDeleteModal({{ $payment_term->id }})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <form id="destroy{{ $payment_term->id }}" class="d-none"
                                                  action="{{ route('payment-terms.destroy', $payment_term) }}" method="POST">
                                                @csrf
                                                @method('delete')
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @include('components.delete-modal')
@endsection

@push('page_scripts')
    <script type="text/javascript"
            src="{{ asset('vendor/datatables/datatables.min.js') }}"></script>
    <script>
        var table = $('#data-table').DataTable({
            dom: "<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4 justify-content-end'f>>tr<'row'<'col-md-5'i><'col-md-7 mt-2'p>>",
            "buttons": [
                {extend: 'excel', text: '<i class="bi bi-file-earmark-excel-fill"></i> Excel'},
                {extend: 'csv', text: '<i class="bi bi-file-earmark-excel-fill"></i> CSV'},
                {
                    extend: 'print',
                    text: '<i class="bi bi-printer-fill"></i> Print',
                    title: "Term Pembayaran",
                    exportOptions: {
                        columns: [0, 1, 2] // Only export No., Nama, and Singkatan columns
                    },
                    customize: function (win) {
                        $(win.document.body).find('h1').css('font-size', '15pt');
                        $(win.document.body).find('h1').css('text-align', 'center');
                        $(win.document.body).find('h1').css('margin-bottom', '20px');
                        $(win.document.body).css('margin', '35px 25px');
                    }
                },
            ],
            ordering: false,
        });
    </script>
@endpush
