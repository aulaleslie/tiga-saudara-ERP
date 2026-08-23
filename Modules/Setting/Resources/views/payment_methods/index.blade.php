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

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label for="status-filter" class="form-label font-weight-bold">Filter Status</label>
                                <select id="status-filter" class="form-control" onchange="window.location.href = this.value ? '{{ route('payment-methods.index') }}?status=' + this.value : '{{ route('payment-methods.index') }}'">
                                    <option value="">Semua Status</option>
                                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                                </select>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 text-center" id="data-table">
                                <thead>
                                <tr>
                                    <th class="align-middle">Nama</th>
                                    <th class="align-middle">Nomor Akun</th>
                                    <th class="align-middle">Metode Tunai</th>
                                    <th class="align-middle">Wajib Referensi</th>
                                    <th class="align-middle">Status</th>
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
                                            <span class="badge {{ $method->requires_reference ? 'bg-success' : 'bg-secondary' }}">{{ $method->requires_reference ? 'Ya' : 'Tidak' }}</span>
                                        </td>
                                        <td>
                                            @if($method->is_active)
                                                <span class="badge badge-success">Aktif</span>
                                            @else
                                                <span class="badge badge-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('payment-methods.edit', $method->id) }}" class="btn btn-info btn-sm"><i class="bi bi-pencil"></i></a>
                                            @if(auth()->user()->can('paymentMethods.edit') || auth()->user()->can('paymentMethods.delete'))
                                                @if($method->is_active)
                                                    <button type="button" class="btn btn-warning btn-sm" title="Nonaktifkan Metode Pembayaran" onclick="
                                                        event.preventDefault();
                                                        if (confirm('Nonaktifkan metode pembayaran &quot;{{ $method->name }}&quot;?')) {
                                                            document.getElementById('toggle-pm-{{ $method->id }}').submit();
                                                        }
                                                    ">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                @else
                                                    <button type="button" class="btn btn-success btn-sm" title="Aktifkan Kembali" onclick="
                                                        event.preventDefault();
                                                        if (confirm('Aktifkan kembali metode pembayaran &quot;{{ $method->name }}&quot;?')) {
                                                            document.getElementById('toggle-pm-{{ $method->id }}').submit();
                                                        }
                                                    ">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                @endif
                                                <form id="toggle-pm-{{ $method->id }}" class="d-none" action="{{ route('payment-methods.toggle-status', $method->id) }}" method="POST">
                                                    @csrf
                                                    @method('PATCH')
                                                </form>
                                            @endif
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
                        columns: [0, 1, 2]
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
