@extends('layouts.app')

@section('title', 'Lokasi')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Locations</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <a href="{{ route('locations.create') }}" class="btn btn-primary">
                            Tambahkan Lokasi <i class="bi bi-plus"></i>
                        </a>

                        <hr>

                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 text-center" id="data-table">
                                <thead>
                                <tr>
                                    <th class="align-middle">No.</th>
                                    <th class="align-middle">Nama</th>
                                    <th class="align-middle">Bisnis</th>
                                    <th class="align-middle">Ambang Kas</th>
                                    <th class="align-middle">POS</th>
                                    <th class="align-middle">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($locations as $key => $location)
                                    <tr>
                                        <td class="align-middle">{{ $key + 1 }}</td>
                                        <td class="align-middle">{{ $location->name }}</td>
                                        <td class="align-middle">{{ $location->setting->company_name }}</td>
                                        <td class="align-middle">
                                            @php
                                                $threshold = $location->pos_cash_threshold ?? $location->setting?->pos_default_cash_threshold ?? 0;
                                            @endphp
                                            {{ $threshold > 0 ? format_currency($threshold) : '—' }}
                                        </td>
                                        <td class="align-middle">
                                            @if($location->saleAssignment?->is_pos)
                                                <span class="badge badge-success">Ya</span>
                                            @else
                                                <span class="badge badge-secondary">Tidak</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            <a href="{{ route('locations.edit', $location) }}" class="btn btn-info btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <button type="button"
                                                    class="btn btn-danger btn-sm"
                                                    onclick="showDeleteModal({{ $location->id }}, 'Hapus lokasi &quot;{{ $location->name }}&quot;?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <form id="destroy{{ $location->id }}" class="d-none"
                                                  action="{{ route('locations.destroy', $location) }}" method="POST">
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

    {{-- Reusable delete modal --}}
    @include('components.delete-modal')
@endsection

@push('page_scripts')
    <script type="text/javascript"
            src="https://cdn.datatables.net/v/bs4/jszip-2.5.0/dt-1.10.24/b-1.7.0/b-html5-1.7.0/b-print-1.7.0/datatables.min.js"></script>
    <script>
        $('#data-table').DataTable({
            dom: "<'row'<'col-md-3'l><'col-md-5 mb-2'B><'col-md-4 justify-content-end'f>>tr<'row'<'col-md-5'i><'col-md-7 mt-2'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="bi bi-file-earmark-excel-fill"></i> Excel' },
                { extend: 'csv',   text: '<i class="bi bi-file-earmark-excel-fill"></i> CSV' },
                {
                    extend: 'print',
                    text: '<i class="bi bi-printer-fill"></i> Print',
                    title: "Locations",
                    exportOptions: { columns: [0, 1, 2] }, // No., Nama, POS
                    customize: function (win) {
                        $(win.document.body).find('h1').css('font-size', '15pt').css('text-align', 'center');
                        $(win.document.body).css('margin', '35px 25px');
                    }
                },
            ],
            ordering: false,
        });
    </script>
@endpush
