@extends('layouts.app')

@section('title', 'Daftar Penerimaan Barang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Daftar Penerimaan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Daftar Penerimaan Barang</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered" id="receivings-table">
                                <thead>
                                    <tr>
                                        <th></th> <!-- Expand Button -->
                                        <th>No. Delivery</th>
                                        <th>No. Pembelian Supplier</th>
                                        <th>No. PO</th>
                                        <th>Tanggal</th>
                                        <th>Lokasi</th>
                                        <th>Total Item</th>
                                        <th>Status</th>
                                        @can('purchases.receive.approval')
                                            <th>Aksi</th>
                                        @endcan
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $receivings = \Modules\Purchase\Entities\ReceivedNote::with(['purchase', 'location', 'receivedNoteDetails'])
                                            ->whereHas('purchase', function($q) {
                                                $settingId = session('setting_id');
                                                if ($settingId) {
                                                    $q->where('setting_id', $settingId);
                                                }
                                            })
                                            ->orderByDesc('created_at')
                                            ->limit(50)
                                            ->get();
                                    @endphp
                                    @forelse($receivings as $receivedNote)
                                        <tr>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-primary toggle-details"
                                                        data-details-target="details-{{ $receivedNote->id }}"
                                                        aria-expanded="false"
                                                        aria-controls="details-{{ $receivedNote->id }}">
                                                    <i class="bi bi-plus-circle"></i>
                                                </button>
                                            </td>
                                            <td>{{ $receivedNote->external_delivery_number ?? '-' }}</td>
                                            <td>{{ $receivedNote->purchase->supplier_purchase_number ?? '-' }}</td>
                                            <td>{{ $receivedNote->purchase->reference ?? '-' }}</td>
                                            <td>{{ optional($receivedNote->created_at)->format('Y-m-d') }}</td>
                                            <td>{{ $receivedNote->location->name ?? '-' }}</td>
                                            <td>{{ $receivedNote->receivedNoteDetails->sum('quantity_received') }}</td>
                                            <td>
                                                @if($receivedNote->isPending())
                                                    <span class="badge badge-warning">Menunggu Persetujuan</span>
                                                @elseif($receivedNote->isApproved())
                                                    <span class="badge badge-success">Disetujui</span>
                                                @elseif($receivedNote->isRejected())
                                                    <span class="badge badge-danger" title="{{ $receivedNote->rejection_reason }}">Ditolak</span>
                                                @endif
                                            </td>
                                            @can('purchases.receive.approval')
                                                <td>
                                                    @if($receivedNote->isPending())
                                                        <form action="{{ route('receivings.approve', $receivedNote) }}" method="POST" class="d-inline approve-receiving-form" onsubmit="handleApproveReceiving(this, event); return false;">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-success" title="Setujui">
                                                                <i class="bi bi-check-lg"></i>
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn btn-sm btn-danger" title="Tolak" 
                                                                data-toggle="modal" data-target="#rejectModal{{ $receivedNote->id }}">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                        
                                                        <!-- Reject Modal -->
                                                        <div class="modal fade" id="rejectModal{{ $receivedNote->id }}" tabindex="-1">
                                                            <div class="modal-dialog">
                                                                <div class="modal-content">
                                                                    <form action="{{ route('receivings.reject', $receivedNote) }}" method="POST">
                                                                        @csrf
                                                                        <div class="modal-header">
                                                                            <h5 class="modal-title">Tolak Penerimaan</h5>
                                                                            <button type="button" class="close" data-dismiss="modal">
                                                                                <span>&times;</span>
                                                                            </button>
                                                                        </div>
                                                                        <div class="modal-body">
                                                                            <div class="form-group">
                                                                                <label for="rejection_reason">Alasan Penolakan <span class="text-danger">*</span></label>
                                                                                <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                                                                            </div>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                                                            <button type="submit" class="btn btn-danger">Tolak</button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @elseif($receivedNote->isRejected())
                                                        <span class="text-muted small" title="{{ $receivedNote->rejection_reason }}">
                                                            <i class="bi bi-info-circle"></i> {{ Str::limit($receivedNote->rejection_reason, 20) }}
                                                        </span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                            @endcan
                                        </tr>

                                        <!-- Expandable Details Row -->
                                        <tr id="details-{{ $receivedNote->id }}" class="receiving-details-row d-none">
                                            <td colspan="{{ Gate::allows('purchases.receive.approval') ? 9 : 8 }}">
                                                @include('purchase::receivings.receiving-details', ['data' => $receivedNote])
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ Gate::allows('purchases.receive.approval') ? 8 : 7 }}" class="text-center text-muted">
                                                Belum ada data penerimaan barang.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Over-Receive Error Modal --}}
    @include('purchase::partials.over-receive-error-modal')

@push('page_scripts')
    <script>
        (function () {
            function initReceivingsToggle() {
                const table = document.getElementById('receivings-table');
                if (!table) {
                    return;
                }

                table.addEventListener('click', function (event) {
                    const button = event.target.closest('button.toggle-details');
                    if (!button) {
                        return;
                    }

                    const targetId = button.getAttribute('data-details-target');
                    if (!targetId) {
                        return;
                    }

                    const detailRow = document.getElementById(targetId);
                    if (!detailRow) {
                        return;
                    }

                    const icon = button.querySelector('i');
                    const isHidden = detailRow.classList.contains('d-none');

                    if (isHidden) {
                        detailRow.classList.remove('d-none');
                        button.setAttribute('aria-expanded', 'true');
                        if (icon) {
                            icon.classList.remove('bi-plus-circle');
                            icon.classList.add('bi-dash-circle');
                        }
                    } else {
                        detailRow.classList.add('d-none');
                        button.setAttribute('aria-expanded', 'false');
                        if (icon) {
                            icon.classList.remove('bi-dash-circle');
                            icon.classList.add('bi-plus-circle');
                        }
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initReceivingsToggle);
            } else {
                initReceivingsToggle();
            }
        })();
    </script>
@endpush
@endsection
