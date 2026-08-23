@extends('layouts.app')

@section('title', 'Akun Jurnal')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
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

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label for="status-filter" class="form-label font-weight-bold">Filter Status</label>
                                <select id="status-filter" class="form-control" onchange="window.location.href = this.value ? '{{ route('chart-of-account.index') }}?status=' + this.value : '{{ route('chart-of-account.index') }}'">
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
                                    <th class="align-middle">Account Number</th>
                                    <th class="align-middle">Name</th>
                                    <th class="align-middle">Category</th>
                                    <th class="align-middle">Parent Account</th>
                                    <th class="align-middle">Description</th>
                                    <th class="align-middle">Status</th>
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
                                            @if($account->is_active)
                                                <span class="badge badge-success">Aktif</span>
                                            @else
                                                <span class="badge badge-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            <a href="{{ route('chart-of-account.edit', $account) }}" class="btn btn-info btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            @if(auth()->user()->can('chartOfAccounts.edit') || auth()->user()->can('chartOfAccounts.delete'))
                                                @if($account->is_active)
                                                    <button type="button" class="btn btn-warning btn-sm" title="Nonaktifkan Akun"
                                                            onclick="if(confirm('Nonaktifkan akun &quot;{{ $account->name }}&quot;?')) document.getElementById('toggle-coa-{{ $account->id }}').submit();">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                @else
                                                    <button type="button" class="btn btn-success btn-sm" title="Aktifkan Kembali"
                                                            onclick="if(confirm('Aktifkan kembali akun &quot;{{ $account->name }}&quot;?')) document.getElementById('toggle-coa-{{ $account->id }}').submit();">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                @endif
                                                <form id="toggle-coa-{{ $account->id }}" class="d-none"
                                                      action="{{ route('chart-of-account.toggle-status', $account->id) }}" method="POST">
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
    <script type="text/javascript" src="{{ asset('vendor/datatables/datatables.min.js') }}"></script>
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
