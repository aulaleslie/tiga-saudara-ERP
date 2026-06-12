@extends('layouts.app')

@section('title', 'Rincian Biaya')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('expenses.index') }}">Biaya</a></li>
        <li class="breadcrumb-item active">Rincian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center">
                        <div>
                            Referensi: <strong>{{ $expense->reference }}</strong>
                        </div>
                        <div class="mfs-auto mfe-1 d-print-none">
                            @if($expense->status === \Modules\Expense\Entities\Expense::STATUS_DRAFT || $expense->status === \Modules\Expense\Entities\Expense::STATUS_REJECTED)
                                @can('expenses.edit')
                                    <a href="{{ route('expenses.edit', $expense) }}" class="btn btn-info btn-sm">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                @endcan
                                @can('expenses.create')
                                    <form action="{{ route('expenses.submit', $expense) }}" method="POST" class="d-inline-block">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="bi bi-send"></i> Ajukan
                                        </button>
                                    </form>
                                @endcan
                            @endif

                            @if($expense->status === \Modules\Expense\Entities\Expense::STATUS_SUBMITTED)
                                @can('expenses.approval')
                                    <form action="{{ route('expenses.approve', $expense) }}" method="POST" class="d-inline-block">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-check-circle"></i> Setujui
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-warning btn-sm" data-toggle="modal" data-target="#rejectModal">
                                        <i class="bi bi-x-circle"></i> Tolak
                                    </button>
                                @endcan
                            @endif

                            @if(in_array($expense->status, [\Modules\Expense\Entities\Expense::STATUS_SUBMITTED, \Modules\Expense\Entities\Expense::STATUS_APPROVED]) && empty($expense->archived_at))
                                @can('expenses.archive')
                                    <button type="button" class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#archiveModal">
                                        <i class="bi bi-archive"></i> Arsip
                                    </button>
                                @endcan
                            @endif
                            <a href="{{ route('expenses.index') }}" class="btn btn-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> Kembali
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($expense->archived_at)
                            <div class="alert alert-secondary">
                                <strong>Diarsipkan pada:</strong> {{ \Carbon\Carbon::parse($expense->archived_at)->format('d M Y H:i') }} oleh {{ $expense->archivedBy->name ?? 'Unknown' }}<br>
                                <strong>Alasan:</strong> {{ $expense->archive_reason ?? '-' }}
                            </div>
                        @endif
                        
                        @if($expense->status === \Modules\Expense\Entities\Expense::STATUS_REJECTED)
                            <div class="alert alert-warning">
                                <strong>Alasan Penolakan:</strong> {{ $expense->rejection_reason }}
                            </div>
                        @endif

                        <div class="row mb-4">
                            <div class="col-sm-4 mb-3">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Perusahaan</h5>
                                <div><strong>Nama Perusahaan:</strong> {{ \Modules\Setting\Entities\Setting::find($expense->setting_id)->company_name ?? '-' }}</div>
                            </div>

                            <div class="col-sm-4 mb-3">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Biaya</h5>
                                <div><strong>Referensi:</strong> {{ $expense->reference }}</div>
                                <div><strong>Tanggal:</strong> {{ \Carbon\Carbon::parse($expense->date)->format('d M Y') }}</div>
                                <div><strong>Kategori:</strong> {{ $expense->category->category_name }}</div>
                                @if(\Modules\Setting\Entities\Setting::find($expense->setting_id)->is_pkp)
                                <div><strong>Termasuk Pajak:</strong> {{ $expense->is_tax_included ? 'Ya' : 'Tidak' }}</div>
                                @endif
                            </div>

                            <div class="col-sm-4 mb-3">
                                <h5 class="mb-2 border-bottom pb-2">Status</h5>
                                <div>
                                    @php
                                        $badges = [
                                            'DRAFT' => 'badge-secondary',
                                            'SUBMITTED' => 'badge-primary',
                                            'APPROVED' => 'badge-success',
                                            'REJECTED' => 'badge-danger',
                                        ];
                                        $labels = [
                                            'DRAFT' => 'Draft',
                                            'SUBMITTED' => 'Diajukan',
                                            'APPROVED' => 'Disetujui',
                                            'REJECTED' => 'Ditolak',
                                        ];
                                    @endphp
                                    <span class="badge {{ $badges[$expense->status] ?? 'badge-info' }}">
                                        {{ $labels[$expense->status] ?? $expense->status }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive-sm">
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th class="align-middle">Nama Rincian</th>
                                    <th class="align-middle">Pajak</th>
                                    <th class="align-middle text-right">Jumlah</th>
                                </tr>
                                </thead>
                                <tbody>
                                @php $subtotal = 0; $taxTotal = 0; @endphp
                                @foreach($expense->details as $detail)
                                    @php
                                        $amount = $detail->amount;
                                        if ($expense->is_tax_included) {
                                            if ($detail->tax && $detail->tax->value > 0) {
                                                $base = $amount / (1 + ($detail->tax->value / 100));
                                                $tax = $amount - $base;
                                                $subtotal += $base;
                                                $taxTotal += $tax;
                                            } else {
                                                $subtotal += $amount;
                                            }
                                        } else {
                                            $subtotal += $amount;
                                            if ($detail->tax && $detail->tax->value > 0) {
                                                $taxTotal += ($amount * $detail->tax->value) / 100;
                                            }
                                        }
                                    @endphp
                                    <tr>
                                        <td class="align-middle">{{ $detail->name }}</td>
                                        <td class="align-middle">
                                            {{ $detail->tax ? $detail->tax->name . ' (' . (float)$detail->tax->value . '%)' : '-' }}
                                        </td>
                                        <td class="align-middle text-right">
                                            {{ format_currency($detail->amount) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="row">
                            <div class="col-lg-4 col-sm-5 ml-md-auto">
                                <table class="table table-clear">
                                    <tbody>
                                    <tr>
                                        <td class="left"><strong>Subtotal</strong></td>
                                        <td class="right">{{ format_currency($subtotal) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Pajak Total</strong></td>
                                        <td class="right">{{ format_currency($taxTotal) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Total Keseluruhan</strong></td>
                                        <td class="right"><strong>{{ format_currency($expense->amount) }}</strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        @if($expense->media && $expense->media->count() > 0)
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h5 class="mb-2 border-bottom pb-2">Lampiran</h5>
                                    <ul class="list-group list-group-flush">
                                        @foreach($expense->getMedia('attachments') as $media)
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <a href="{{ $media->getUrl() }}" target="_blank">
                                                    {{ $media->file_name }}
                                                </a>
                                                <span class="badge badge-primary badge-pill">{{ $media->humanReadableSize }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
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
                <form action="{{ route('expenses.reject', $expense) }}" method="POST">
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
                <form action="{{ route('expenses.archive', $expense) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="archive_reason">Alasan Arsip @if($expense->status === \Modules\Expense\Entities\Expense::STATUS_APPROVED) <span class="text-danger">*</span> @endif</label>
                            <textarea class="form-control" id="archive_reason" name="archive_reason" rows="3" @if($expense->status === \Modules\Expense\Entities\Expense::STATUS_APPROVED) required @endif></textarea>
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
