@extends('layouts.app')

@section('title', __('Penerimaan Barang'))

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">{{ __('Beranda') }}</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">{{ __('Pembelian') }}</a></li>
        <li class="breadcrumb-item active">{{ __('Penerimaan Barang') }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <!-- Purchases ready for receiving -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Pembelian Siap Diterima</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            {{ __('Menampilkan pembelian yang sudah disetujui atau sebagian diterima untuk diproses penerimaan barang.') }}
                        </p>

                        <div class="table-responsive">
                            <livewire:purchase.purchase-table :status-filter="[
                                \Modules\Purchase\Entities\Purchase::STATUS_APPROVED,
                                \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED_PARTIALLY,
                            ]" />
                        </div>
                    </div>
                </div>

                <!-- All Receivings List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Daftar Penerimaan Barang</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered" id="receivings-table">
                                <thead>
                                    <tr>
                                        <th>No. Delivery</th>
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
                                            <td>{{ $receivedNote->external_delivery_number ?? '-' }}</td>
                                            <td>
                                                <a href="{{ route('purchases.show', $receivedNote->purchase->id) }}">
                                                    {{ $receivedNote->purchase->reference ?? '-' }}
                                                </a>
                                            </td>
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
                                    @empty
                                        <tr>
                                            <td colspan="{{ Gate::allows('purchases.receive.approval') ? 7 : 6 }}" class="text-center text-muted">
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
@endsection

