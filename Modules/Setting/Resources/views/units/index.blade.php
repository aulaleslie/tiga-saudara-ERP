@extends('layouts.app')

@section('title', 'Units')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Units</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <a href="{{ route('units.create') }}" class="btn btn-primary">
                            Tambahkan Unit <i class="bi bi-plus"></i>
                        </a>

                        <hr>

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label for="status-filter" class="form-label font-weight-bold">Filter Status</label>
                                <select id="status-filter" class="form-control" onchange="window.location.href = this.value ? '{{ route('units.index') }}?status=' + this.value : '{{ route('units.index') }}'">
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
                                    <th class="align-middle">No.</th>
                                    <th class="align-middle">Nama</th>
                                    <th class="align-middle">Singkatan</th>
                                    <th class="align-middle">Status</th>
                                    <th class="align-middle">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($units as $key => $unit)
                                    <tr>
                                        <td class="align-middle">{{ $key + 1 }}</td>
                                        <td class="align-middle">{{ $unit->name }}</td>
                                        <td class="align-middle">{{ $unit->short_name }}</td>
                                        <td class="align-middle">
                                            @if($unit->is_active)
                                                <span class="badge badge-success">Aktif</span>
                                            @else
                                                <span class="badge badge-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            <a href="{{ route('units.edit', $unit) }}" class="btn btn-info btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            @if(auth()->user()->can('units.edit') || auth()->user()->can('units.delete'))
                                                @if($unit->is_active)
                                                    <button type="button" class="btn btn-warning btn-sm" title="Nonaktifkan Satuan"
                                                            onclick="if(confirm('Nonaktifkan satuan &quot;{{ $unit->name }}&quot;?')) document.getElementById('toggle-unit-{{ $unit->id }}').submit();">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                @else
                                                    <button type="button" class="btn btn-success btn-sm" title="Aktifkan Kembali"
                                                            onclick="if(confirm('Aktifkan kembali satuan &quot;{{ $unit->name }}&quot;?')) document.getElementById('toggle-unit-{{ $unit->id }}').submit();">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                @endif
                                                <form id="toggle-unit-{{ $unit->id }}" class="d-none"
                                                      action="{{ route('units.toggle-status', $unit) }}" method="POST">
                                                    @csrf
                                                    @method('patch')
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
                    title: "Units",
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
