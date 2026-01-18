@extends('layouts.app')

@section('title', 'Metode Pembayaran')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
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
                                    <th class="align-middle">Metode Tunai</th>
                                    <th class="align-middle">Tersedia di POS</th>
                                    <th class="align-middle">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($paymentMethods as $method)
                                    <tr>
                                        <td>{{ $method->name }}</td>
                                        <td>{{ $method->chartOfAccount->name ?? 'N/A' }}</td>
                                        <td>
                                            <span class="badge {{ $method->is_cash ? 'bg-success' : 'bg-secondary' }}">{{ $method->is_cash ? 'Ya' : 'Tidak' }}</span>
                                        </td>
                                        <td>
                                            <span class="badge {{ $method->is_available_in_pos ? 'bg-success' : 'bg-secondary' }}">{{ $method->is_available_in_pos ? 'Ya' : 'Tidak' }}</span>
                                        </td>
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
                    title: "Locations",
                    exportOptions: {
                        columns: [0, 1, 2, 3]
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
