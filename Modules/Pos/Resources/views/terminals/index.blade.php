@extends('layouts.app')

@section('title', 'Terminal POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Terminal POS</span>
                <a href="{{ route('pos.terminals.create') }}" class="btn btn-primary btn-sm">Tambah Terminal</a>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0 table-striped">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Lokasi</th>
                            <th>Status</th>
                            <th>Kebijakan</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($terminals as $terminal)
                            <tr>
                                <td>{{ $terminal->code }}</td>
                                <td>{{ $terminal->name }}</td>
                                <td>{{ optional($terminal->location)->name ?? '-' }}</td>
                                <td>
                                    @if($terminal->is_active)
                                        <span class="badge bg-success">Aktif</span>
                                    @else
                                        <span class="badge bg-secondary">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($terminal->policy)
                                        Sesi: {{ $terminal->policy->require_session_open ? 'Aktif' : 'Nonaktif' }}<br>
                                        Saldo Awal: {{ $terminal->policy->require_opening_float ? 'Aktif' : 'Nonaktif' }}<br>
                                        Batas Kas: {{ $terminal->policy->cash_threshold !== null ? number_format((float) $terminal->policy->cash_threshold, 0) : '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('pos.terminals.edit', $terminal->id) }}" class="btn btn-outline-primary btn-sm">Ubah</a>
                                    @if($terminal->is_active)
                                        <form action="{{ route('pos.terminals.destroy', $terminal->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Nonaktifkan terminal ini?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Nonaktifkan</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4">Belum ada terminal yang dikonfigurasi.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
