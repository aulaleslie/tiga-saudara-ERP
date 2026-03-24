@extends('layouts.app')

@section('title', 'Detail Sesi POS')

@section('content')
    <div class="container-fluid py-4">
        @include('utils.alerts')

        {{-- Header / Breadcrumb area --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 bg-transparent p-0">
                        <li class="breadcrumb-item"><a href="{{ route('pos.sessions.index') }}">Sesi POS</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Summary</li>
                    </ol>
                </nav>
                <h1 class="h3 mb-0 text-gray-800">
                    Sesi #{{ $session_id }} - {{ $terminal_code }} {{ $terminal_name ? "($terminal_name)" : '' }}
                </h1>
            </div>
            <div>
                <a href="{{ route('pos.sessions.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Kembali ke Daftar
                </a>
            </div>
        </div>

        <div class="row g-4">
            {{-- Session Overview Card --}}
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm overflow-hidden h-100" style="border-radius: 15px; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px);">
                    <div class="card-header bg-primary text-white py-3 border-0">
                        <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>Ikhtisar Sesi</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-4 text-center">
                            @if($status === 'OPEN')
                                <span class="badge bg-success px-3 py-2" style="border-radius: 20px; font-size: 0.9rem;">AKTIF</span>
                            @elseif($status === 'CLOSING')
                                <span class="badge bg-warning text-dark px-3 py-2" style="border-radius: 20px; font-size: 0.9rem;">PROSES TUTUP</span>
                            @else
                                <span class="badge bg-secondary px-3 py-2" style="border-radius: 20px; font-size: 0.9rem;">SELESAI</span>
                            @endif
                        </div>

                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted">Terminal</span>
                            <span class="fw-bold">{{ $terminal_code }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted">Kasir</span>
                            <span class="fw-bold">{{ $cashier_user_id }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted">Durasi Sesi</span>
                            <span class="fw-bold">{{ $duration ?: '-' }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted">Total Penjualan</span>
                            <span class="fw-bold text-primary">{{ format_currency($sales_total) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted">Ekspektasi Kas</span>
                            <span class="fw-bold text-success">{{ format_currency($expected_cash_total) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted">Ambang Batas</span>
                            <span class="fw-bold">{{ format_currency($threshold_value) }}</span>
                        </div>

                        @if($is_threshold_breached)
                            <div class="alert alert-danger mt-4 border-0 shadow-sm" style="border-radius: 10px;">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> Saldo kas melebihi ambang batas!
                            </div>
                        @endif

                        <div class="mt-4 pt-4 d-grid gap-2">
                            @if($status === 'OPEN')
                                @if(auth()->user()->can('pos.sessions.close') || auth()->user()->can('pos.sessions.close-admin'))
                                    <button type="button" class="btn btn-danger py-2 shadow-sm" data-toggle="modal" data-target="#closeModal" data-bs-toggle="modal" data-bs-target="#closeModal" data-session-id="{{ $session_id }}" style="border-radius: 10px;">
                                        <i class="bi bi-power me-2"></i>Tutup Sesi
                                    </button>
                                @endif
                            @elseif($status === 'CLOSED')
                                @can('pos.supervisor.approval')
                                    <button type="button" class="btn btn-success py-2 shadow-sm" data-toggle="modal" data-target="#finalizeModal" data-bs-toggle="modal" data-bs-target="#finalizeModal" data-session-id="{{ $session_id }}" style="border-radius: 10px;">
                                        <i class="bi bi-check-circle-fill me-2"></i>Finalisasi Sesi
                                    </button>
                                @endcan
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Timeline & Transactions --}}
            <div class="col-lg-8">
                {{-- Cash Events Timeline --}}
                <div class="card border-0 shadow-sm mb-4" style="border-radius: 15px;">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0 text-dark"><i class="bi bi-clock-history me-2"></i>Timeline Kas</h5>
                        <div class="btn-group btn-group-sm rounded-pill p-1 bg-light border" role="group">
                            <button type="button" class="btn btn-sm btn-dark rounded-pill filter-btn active" data-filter="all">Semua</button>
                            <button type="button" class="btn btn-sm rounded-pill filter-btn" data-filter="CASH_SALE_IN">Penjualan</button>
                            <button type="button" class="btn btn-sm rounded-pill filter-btn" data-filter="SAFE_DROP_OUT">Pickup</button>
                            <button type="button" class="btn btn-sm rounded-pill filter-btn" data-filter="OPEN_FLOAT">Modal</button>
                        </div>
                    </div>
                    <div class="card-body p-0 overflow-auto" style="max-height: 400px;">
                        <ul class="list-group list-group-flush timeline">
                            @forelse($cash_events as $event)
                                <li class="list-group-item border-start border-4 py-3 event-row" data-event-type="{{ $event['event_type'] }}" style="border-left-color: {{ $event['direction'] === 'IN' ? '#10b981' : ($event['direction'] === 'OUT' ? '#ef4444' : '#6b7280') }} !important;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-bold">{{ str_replace('_', ' ', $event['event_type']) }}</div>
                                            <small class="text-muted">
                                                <i class="bi bi-person me-1"></i>{{ $event['performer'] }}
                                                @if($event['approver']) | <i class="bi bi-shield-check me-1"></i>{{ $event['approver'] }} @endif
                                                @if($event['notes']) | <span class="fst-italic">"{{ $event['notes'] }}"</span> @endif
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold {{ $event['direction'] === 'IN' ? 'text-success' : ($event['direction'] === 'OUT' ? 'text-danger' : '') }}">
                                                {{ $event['direction'] === 'OUT' ? '-' : '+' }}{{ format_currency($event['amount']) }}
                                            </div>
                                            <small class="text-muted">{{ \Carbon\Carbon::parse($event['timestamp'])->format('H:i') }}</small>
                                        </div>
                                    </div>
                                </li>
                            @empty
                                <li class="list-group-item text-center py-4 text-muted">Belum ada aktivitas kas.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                {{-- Transactions Table --}}
                <div class="card border-0 shadow-sm" style="border-radius: 15px;">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="card-title mb-0 text-dark"><i class="bi bi-receipt me-2"></i>Riwayat Transaksi Terakhir (50)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>No. Resi</th>
                                        <th>Kasir</th>
                                        <th>Metode</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-center">Waktu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($transactions as $tx)
                                        <tr class="transaction-row clickable" data-checkout-id="{{ $tx['id'] }}" style="cursor: pointer;">
                                            <td><span class="fw-bold">{{ $tx['receipt_number'] }}</span></td>
                                            <td>{{ $tx['cashier'] }}</td>
                                            <td><span class="badge bg-light text-dark border">{{ $tx['payment_method'] }}</span></td>
                                            <td class="text-end fw-bold">{{ format_currency($tx['amount']) }}</td>
                                            <td class="text-center text-muted"><small>{{ \Carbon\Carbon::parse($tx['timestamp'])->format('H:i') }}</small></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">Belum ada transaksi diposting.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="3" class="text-end">Total ({{ $total_transactions_count }} transaksi)</td>
                                        <td class="text-end text-primary">{{ format_currency($total_transactions_amount) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Checkout Detail Modal --}}
    <div class="modal fade" id="checkoutDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
                <div class="modal-header bg-light py-3" style="border-radius: 15px 15px 0 0;">
                    <h5 class="modal-title fw-bold" id="checkoutModalTitle">Detail Transaksi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-0" id="checkoutModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Tutup</button>
                    <a href="#" id="printReceiptBtn" target="_blank" class="btn btn-primary rounded-pill px-4">
                        <i class="bi bi-printer me-2"></i>Cetak Resi
                    </a>
                </div>
            </div>
        </div>
    </div>

    @include('pos::session._close-modal')
    @include('pos::session._finalize-modal')

@endsection

@push('styles')
    <style>
        .timeline .list-group-item:hover {
            background-color: #f8fafc;
        }
        .transaction-row:hover {
            background-color: #f1f5f9 !important;
        }
        .filter-btn.active {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .clickable {
            transition: all 0.2s ease;
        }
        .badge {
            font-weight: 500;
        }
        .table > :not(caption) > * > * {
            padding: 1rem 1rem;
        }
    </style>
@endpush

@push('scripts')
    <script>
        const SESSION_ID = @json($session_id);
    </script>
    <script src="{{ asset('js/pos-session-handlers.js') }}"></script>
    <script src="{{ asset('js/pos-session-detail-handlers.js') }}"></script>
@endpush
