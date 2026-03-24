@extends('layouts.app')

@section('title', 'Riwayat Sesi POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0 text-gray-800">Riwayat Sesi POS</h1>
            @can('pos.sessions.open')
                <a href="{{ route('pos.sessions.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Buka Sesi Baru
                </a>
            @endcan
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-wrap align-items-center">
                <div class="btn-group mb-2 mb-md-0 me-3">
                    <a href="{{ route('pos.sessions.index', ['terminal_id' => request('terminal_id')]) }}" class="btn btn-sm {{ is_null($status) ? 'btn-primary' : 'btn-outline-primary' }}">Semua</a>
                    <a href="{{ route('pos.sessions.index', ['status' => 'OPEN', 'terminal_id' => request('terminal_id')]) }}" class="btn btn-sm {{ $status === 'OPEN' ? 'btn-primary' : 'btn-outline-primary' }}">Aktif</a>
                    <a href="{{ route('pos.sessions.index', ['status' => 'CLOSED', 'terminal_id' => request('terminal_id')]) }}" class="btn btn-sm {{ $status === 'CLOSED' ? 'btn-primary' : 'btn-outline-primary' }}">Selesai</a>
                </div>

                @if($terminalFilter)
                    <span class="badge bg-info text-dark me-2 mb-2 mb-md-0 d-inline-flex align-items-center">
                        Terminal: {{ $terminalFilter->code }} - {{ $terminalFilter->name }}
                        <a href="{{ route('pos.sessions.index', ['status' => $status]) }}" class="text-dark ms-2 text-decoration-none" title="Hapus filter">
                            <i class="bi bi-x-circle-fill"></i>
                        </a>
                    </span>
                @endif
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 pos-sessions-table">
                        <thead class="table-light text-nowrap">
                            <tr>
                                <th>Terminal</th>
                                <th>Kasir</th>
                                <th>Status</th>
                                <th>Dibuka</th>
                                <th>Ditutup</th>
                                <th class="text-end">Saldo Awal</th>
                                <th class="text-end">Total Penjualan</th>
                                <th class="text-end">Kas</th>
                                <th class="text-end">Pengambilan Kas</th>
                                <th class="text-end">Metrik</th>
                                <th>Aktivitas Terakhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sessions as $session)
                                @php
                                    $nonTerminalLabel = 'Non-Terminal';
                                    $terminalCodeLabel = $session->terminal?->code ?? $nonTerminalLabel;
                                @endphp
                                <tr>
                                    <td>
                                        @if($session->terminal)
                                            <strong>{{ $session->terminal->code }}</strong><br>
                                            <small class="text-muted">{{ $session->terminal->name }}</small>
                                        @else
                                            <strong>{{ $nonTerminalLabel }}</strong><br>
                                            <small class="text-muted">Sesi tanpa terminal</small>
                                        @endif
                                    </td>
                                    <td>{{ $session->cashier->name }}</td>
                                    <td>
                                        @if($session->status === 'OPEN')
                                            <span class="badge bg-success">AKTIF</span>
                                        @elseif($session->status === 'CLOSING')
                                            <span class="badge bg-warning text-dark">PROSES TUTUP</span>
                                        @else
                                            <span class="badge bg-secondary">SELESAI</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $session->opened_at ? $session->opened_at->format('d/m/Y H:i') : '-' }}<br>
                                        <small class="text-muted">{{ $session->opened_at ? $session->opened_at->diffForHumans() : '' }}</small>
                                    </td>
                                    <td>
                                        {{ $session->closed_at ? $session->closed_at->format('d/m/Y H:i') : '-' }}
                                    </td>
                                    <td class="text-end">{{ format_currency($session->opening_float_total) }}</td>
                                    <td class="text-end">{{ format_currency($session->sales_total ?? 0) }}</td>

                                    {{-- Kas column: shows expected for OPEN, counted for CLOSED --}}
                                    <td class="text-end">
                                        @if($session->status === 'OPEN')
                                            {{ format_currency($session->expected_cash_total) }}
                                        @else
                                            {{ format_currency($session->counted_cash_total) }}
                                        @endif
                                    </td>

                                    <td class="text-end">{{ format_currency($session->cash_picked_up_total ?? 0) }}</td>

                                    {{-- Metrik column: shows transaction count for OPEN, variance for CLOSED --}}
                                    <td class="text-end">
                                        @if($session->status === 'OPEN')
                                            <span class="badge bg-info">{{ $session->transaction_count ?? 0 }}</span>
                                        @elseif($session->status === 'CLOSED' || $session->status === 'FINALIZED' || $session->status === 'CLOSING')
                                            <span class="{{ $session->variance_total != 0 ? 'text-danger fw-bold' : 'text-success' }}">
                                                {{ format_currency($session->variance_total) }}
                                            </span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>

                                    {{-- Aktivitas Terakhir: shows time for OPEN, dash for CLOSED --}}
                                    <td>
                                        @if($session->status === 'OPEN')
                                            {{ $session->last_activity ? \Carbon\Carbon::parse($session->last_activity)->format('H:i') : '-' }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>

                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="{{ route('pos.sessions.summary', $session) }}" class="btn btn-sm btn-outline-secondary" title="Lihat Detail">
                                                <i class="bi bi-eye"></i> Detail
                                            </a>
                                            @if($session->status === 'OPEN')
                                                @can('pos.sessions.close-admin')
                                                    <button type="button" class="btn btn-sm btn-outline-warning" data-toggle="modal" data-target="#closeAdminModal" data-session-id="{{ $session->id }}" data-session-code="{{ $terminalCodeLabel }}" title="Tutup Terminal (Admin)">
                                                        <i class="bi bi-lock"></i> Tutup
                                                    </button>
                                                @endcan
                                                @can('pos.supervisor.approval')
                                                    @if($session->terminal)
                                                        <button type="button" class="btn btn-sm btn-outline-success disabled" data-bs-toggle="tooltip" title="Tutup terminal terlebih dahulu sebelum finalisasi">
                                                            <i class="bi bi-check-circle"></i> Finalisasi
                                                        </button>
                                                    @endif
                                                @endcan
                                            @elseif($session->status === 'CLOSED' && $session->terminal)
                                                @can('pos.supervisor.approval')
                                                    <button type="button" class="btn btn-sm btn-outline-success" data-toggle="modal" data-target="#finalizeModal" data-session-id="{{ $session->id }}" data-session-code="{{ $terminalCodeLabel }}" title="Finalisasi Sesi">
                                                        <i class="bi bi-check-circle"></i> Finalisasi
                                                    </button>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="text-center py-4 text-muted">
                                        Tidak ada data sesi POS ditemukan.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($sessions->hasPages())
                <div class="card-footer py-3">
                    {{ $sessions->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Modals --}}
    @include('pos::session._close-admin-modal')
    @include('pos::session._finalize-modal')
@endsection

@push('styles')
    <style>
        /* Normalize POS sessions table columns */
        .pos-sessions-table {
            table-layout: fixed;
            width: 100%;
        }

        .pos-sessions-table th,
        .pos-sessions-table td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Placeholder dashes styling */
        .pos-sessions-table td .text-muted {
            opacity: 0.6;
        }

        /* Ensure action buttons don't wrap */
        .pos-sessions-table .btn-group {
            flex-wrap: nowrap;
            white-space: nowrap;
        }

        /* Responsive: Allow wrapping on smaller screens */
        @media (max-width: 768px) {
            .pos-sessions-table th,
            .pos-sessions-table td {
                white-space: normal;
            }
        }
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('js/pos-session-handlers.js') }}"></script>
@endpush
