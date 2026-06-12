@extends('layouts.app')

@section('title', 'Expenses')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Expenses</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <a href="{{ route('expenses.create') }}" class="btn btn-primary">
                                    Tambah Biaya <i class="bi bi-plus"></i>
                                </a>
                            </div>
                            <div class="col-md-6 text-right text-end">
                                <form action="{{ route('expenses.index') }}" method="GET" class="form-inline d-flex justify-content-end gap-2">
                                    <select name="status" class="form-control" onchange="this.form.submit()">
                                        <option value="">Semua Status</option>
                                        <option value="DRAFT" {{ request('status') == 'DRAFT' ? 'selected' : '' }}>Draft</option>
                                        <option value="SUBMITTED" {{ request('status') == 'SUBMITTED' ? 'selected' : '' }}>Diajukan</option>
                                        <option value="APPROVED" {{ request('status') == 'APPROVED' ? 'selected' : '' }}>Disetujui</option>
                                        <option value="REJECTED" {{ request('status') == 'REJECTED' ? 'selected' : '' }}>Ditolak</option>
                                    </select>
                                    <select name="archived" class="form-control" onchange="this.form.submit()">
                                        <option value="0" {{ request('archived', '0') == '0' ? 'selected' : '' }}>Aktif</option>
                                        <option value="1" {{ request('archived') == '1' ? 'selected' : '' }}>Diarsipkan</option>
                                    </select>
                                </form>
                            </div>
                        </div>

                        <hr>

                        <div class="table-responsive">
                            {!! $dataTable->table() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel">Tolak Pengeluaran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form id="rejectForm" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="rejection_reason">Alasan Penolakan <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Tolak Pengeluaran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Archive Modal -->
    <div class="modal fade" id="archiveModal" tabindex="-1" role="dialog" aria-labelledby="archiveModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="archiveModalLabel">Arsip Pengeluaran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form id="archiveForm" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="archive_reason" id="archive_reason_label">Alasan Arsip</label>
                            <textarea class="form-control" id="archive_reason" name="archive_reason" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Arsipkan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    {!! $dataTable->scripts() !!}
    <script>
        function showRejectModal(id) {
            var url = '{{ route("expenses.reject", ":id") }}';
            url = url.replace(':id', id);
            $('#rejectForm').attr('action', url);
            $('#rejectModal').modal('show');
        }

        function showArchiveModal(id, status) {
            var url = '{{ route("expenses.archive", ":id") }}';
            url = url.replace(':id', id);
            $('#archiveForm').attr('action', url);
            
            if (status === 'APPROVED') {
                $('#archive_reason_label').html('Alasan Arsip <span class="text-danger">*</span>');
                $('#archive_reason').attr('required', true);
            } else {
                $('#archive_reason_label').html('Alasan Arsip');
                $('#archive_reason').removeAttr('required');
            }
            
            $('#archiveModal').modal('show');
        }
    </script>
@endpush
