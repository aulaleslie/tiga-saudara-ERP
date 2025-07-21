@extends('layouts.app')

@section('title', 'Akun Jurnal')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/select/1.3.3/css/select.dataTables.min.css">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Daftar Nomor Akun</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <a href="{{ route('chart-of-account.create') }}" class="btn btn-primary">
                            Tambah Nomor Akun <i class="bi bi-plus"></i>
                        </a>

                        <hr>

                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 text-center" id="data-table">
                                <thead>
                                <tr>
                                    <th class="align-middle">No.</th>
                                    <th class="align-middle">Account Number</th>
                                    <th class="align-middle">Name</th>
                                    <th class="align-middle">Category</th>
                                    <th class="align-middle">Parent Account</th>
                                    <th class="align-middle">Description</th>
                                    <th class="align-middle">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($coa as $key => $account)
                                    <tr>
                                        <td class="align-middle">{{ $key + 1 }}</td>
                                        <td class="align-middle">{{ $account->account_number }}</td>
                                        <td class="align-middle">{{ $account->name }}</td>
                                        <td class="align-middle">{{ $account->category }}</td>
                                        <td class="align-middle">{{ $account->parentAccount->name ?? '-' }}</td>
                                        <td class="align-middle">{{ $account->description ?? '-' }}</td>
                                        <td class="align-middle">

                                            <a href="{{ route('chart-of-account.edit', $account) }}" class="btn btn-info btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
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
    <script type="text/javascript" src="https://cdn.datatables.net/v/bs4/jszip-2.5.0/dt-1.10.24/b-1.7.0/b-html5-1.7.0/b-print-1.7.0/datatables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js"></script>
    <script>
        var table = $('#data-table').DataTable({
            dom: "<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4 justify-content-end'f>>tr<'row'<'col-md-5'i><'col-md-7 mt-2'p>>",
            buttons: [
                {extend: 'excel', text: '<i class="bi bi-file-earmark-excel-fill"></i> Excel'},
                {extend: 'csv', text: '<i class="bi bi-file-earmark-excel-fill"></i> CSV'},
                {
                    extend: 'print',
                    text: '<i class="bi bi-printer-fill"></i> Print',
                    title: "Akun Jurnal",
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5]
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
