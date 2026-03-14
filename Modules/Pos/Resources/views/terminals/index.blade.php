@extends('layouts.app')

@section('title', 'Terminal POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card mb-3 border-info">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <i class="bi bi-info-circle text-info mr-3" style="font-size: 1.5rem;"></i>
                    <div>
                        <h6 class="mb-1">Konfigurasi Sumber Lokasi POS</h6>
                        <p class="mb-0 small text-muted">
                            Terminal di bawah terdaftar untuk bisnis <strong>{{ $setting->company_name }}</strong>.
                            Sumber stok dan tujuan penjualan POS ditentukan oleh
                            <a href="{{ route('sales-location-configurations.index') }}">Konfigurasi Lokasi Penjualan</a>.
                        </p>
                    </div>
                </div>
                <hr>
                <div class="small">
                    <strong>Lokasi Penjualan Aktif:</strong>
                    @forelse($saleLocations as $saleLocation)
                        <span class="badge bg-light text-dark mr-2 border">
                            {{ $loop->iteration }}. {{ $saleLocation->location->location_name }}
                        </span>
                    @empty
                        <span class="text-danger">Belum ada lokasi yang dikonfigurasi.</span>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Terminal POS</span>
                <a href="{{ route('pos.terminals.create') }}" class="btn btn-primary btn-sm">Tambah Terminal</a>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0 table-striped">
                    <thead>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Status</th>
                            <th>Kebijakan</th>
                            <th>Sesi Berjalan</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($terminals as $terminal)
                            <tr>
                                <td>{{ $terminal->code }}</td>
                                <td>
                                    <strong>{{ $terminal->name }}</strong><br>
                                    <a href="{{ route('pos.sessions.index', ['terminal_id' => $terminal->id]) }}" class="small text-decoration-none">
                                        <i class="bi bi-clock-history"></i> Lihat sesi
                                    </a>
                                </td>
                                <td>
                                    @if($terminal->is_active)
                                        <span class="badge bg-success">Aktif</span>
                                    @else
                                        <span class="badge bg-secondary">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">
                                    @if($terminal->policy)
                                        Wajib saldo awal: {{ $terminal->policy->require_opening_float ? 'Ya' : 'Tidak' }}<br>
                                        Batas Kas: {{ $terminal->policy->cash_threshold !== null ? number_format((float) $terminal->policy->cash_threshold, 0) : '-' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    @if($terminal->activeSessions->isEmpty())
                                        <span class="badge bg-light text-muted border">Tidak ada sesi aktif</span>
                                    @elseif($terminal->activeSessions->count() === 1)
                                        @php $activeSession = $terminal->activeSessions->first(); @endphp
                                        <span class="badge bg-success">
                                            Sedang digunakan - {{ $activeSession->cashier->name }}<br>
                                            <small>sejak {{ $activeSession->opened_at->format('H:i') }}</small>
                                        </span>
                                    @else
                                        <span class="badge bg-danger">Perlu cek ({{ $terminal->activeSessions->count() }} sesi)</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('pos.terminals.edit', $terminal->id) }}" class="btn btn-outline-primary btn-sm">Ubah</a>
                                    @if($terminal->is_active)
                                        <form action="{{ route('pos.terminals.destroy', $terminal->id) }}" method="POST" class="d-inline js-terminal-deactivate-form">
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

@push('page_scripts')
    <script>
        document.querySelectorAll('.js-terminal-deactivate-form').forEach(form => {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const result = await Swal.fire({
                    title: 'Nonaktifkan Terminal?',
                    text: 'Terminal ini tidak akan dapat digunakan untuk transaksi baru sampai diaktifkan kembali.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    confirmButtonText: 'Ya, Nonaktifkan',
                    cancelButtonText: 'Batal'
                });
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });
    </script>
@endpush
