@extends('layouts.app')

@section('title', 'Metode Pembayaran')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/select/1.3.3/css/select.dataTables.min.css">
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <a href="{{ route('payment-methods.create') }}" class="btn btn-primary">
                            Tambahkan Metode Pembayaran <i class="bi bi-plus"></i>
                        </a>

                        <hr>

                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 text-center" id="data-table">
                                <thead>
                                <tr>
                                    <th class="align-middle">Nama</th>
                                    <th class="align-middle">Nomor Akun</th>
                                    <th class="align-middle">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($paymentMethods as $method)
                                    <tr>
                                        <td>{{ $method->name }}</td>
                                        <td>{{ $method->chartOfAccount->name ?? 'N/A' }}</td>
                                        <td>
                                            <a href="{{ route('payment-methods.edit', $method->id) }}" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i></a>
                                            <form action="{{ route('payment-methods.destroy', $method->id) }}" method="POST" style="display: inline-block;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this payment method?');"><i class="bi bi-trash"></i></button>
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
            src="https://cdn.datatables.net/v/bs4/jszip-2.5.0/dt-1.10.24/b-1.7.0/b-html5-1.7.0/b-print-1.7.0/datatables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js"></script>
    <script>
        var table = $('#data-table').DataTable({
            dom: "<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4 justify-content-end'f>>tr<'row'<'col-md-5'i><'col-md-7 mt-2'p>>",
            "buttons": [
                {extend: 'excel', text: '<i class="bi bi-file-earmark-excel-fill"></i> Excel'},
                {extend: 'csv', text: '<i class="bi bi-file-earmark-excel-fill"></i> CSV'},
                {
                    extend: 'print',
                    text: '<i class="bi bi-printer-fill"></i> Print',
                    title: "Locations",
                    exportOptions: {
                        columns: [0, 1, 2] // Only export No., Nama, and Singkatan columns
                    },
                    customize: function (win) {
                        $(win.document.body).find('h1').css('font-size', '15pt');
                        $(win.document.body).find('h1').css('text-align', 'center');
                        $(win.document.body).css('margin', '35px 25px');
                    }
                },
            ],
            ordering: false,
        });
    </script>
@endpush
