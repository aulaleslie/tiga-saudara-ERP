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
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-light text-nowrap">
                            <tr>
                                <th>Terminal</th>
                                <th>Kasir</th>
                                <th>Status</th>
                                <th>Dibuka</th>
                                <th>Ditutup</th>
                                <th class="text-end">Saldo Awal</th>
                                <th class="text-end">Kas Akhir</th>
                                <th class="text-end">Selisih</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sessions as $session)
                                <tr>
                                    <td>
                                        <strong>{{ $session->terminal->code }}</strong><br>
                                        <small class="text-muted">{{ $session->terminal->name }}</small>
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
                                    <td class="text-end">{{ $session->status === 'CLOSED' ? format_currency($session->counted_cash_total) : '-' }}</td>
                                    <td class="text-end">
                                        @if($session->status === 'CLOSED')
                                            <span class="{{ $session->variance_total != 0 ? 'text-danger fw-bold' : 'text-success' }}">
                                                {{ format_currency($session->variance_total) }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                Aksi
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="{{ route('pos.sessions.summary', $session) }}">Detail Ringkasan</a></li>
                                                @if($session->status === 'OPEN' && auth()->id() == $session->cashier_user_id)
                                                    <li><a class="dropdown-item text-danger" href="{{ route('pos.sell') }}">Masuk Ke Kasir</a></li>
                                                @endif
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
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
@endsection
