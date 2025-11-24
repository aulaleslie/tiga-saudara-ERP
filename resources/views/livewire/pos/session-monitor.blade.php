<div class="card border-0 shadow-sm" wire:poll.15s="refreshSessions">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h5 class="mb-0">Dashboard Sesi POS</h5>
            <small class="text-muted">Pantau kasir, perangkat, dan kas di laci secara langsung.</small>
        </div>
        <div class="text-right">
            <span class="badge badge-info mr-1">Idle &gt; {{ $idleThresholdMinutes }} menit</span>
            <span class="badge badge-secondary">Ambang kas default: {{ format_currency($defaultCashThreshold ?? 0) }}</span>
        </div>
    </div>

    <div class="card-body">
        <div class="form-row align-items-end">
            <div class="col-md-3">
                <div class="form-group">
                    <label for="statusFilter" class="font-weight-bold">Status</label>
                    <select wire:model="statusFilter" id="statusFilter" class="form-control">
                        <option value="">Semua Status</option>
                        <option value="active">Aktif</option>
                        <option value="paused">Jeda</option>
                        <option value="closed">Tutup</option>
                    </select>
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-group">
                    <label for="locationFilter" class="font-weight-bold">Lokasi</label>
                    <select wire:model="locationFilter" id="locationFilter" class="form-control">
                        <option value="">Semua Lokasi</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-group mb-0">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="alertsOnly" wire:model="alertsOnly">
                        <label class="form-check-label" for="alertsOnly">Tampilkan yang diberi peringatan saja</label>
                    </div>
                </div>
            </div>

            <div class="col-md-3 text-md-right">
                <div class="small text-muted">Memperbarui otomatis setiap 15 detik.</div>
                <div class="small text-muted">Gunakan filter untuk mempersempit fokus supervisor.</div>
            </div>
        </div>

        <div class="table-responsive position-relative">
            <div wire:loading.flex class="position-absolute w-100 h-100 justify-content-center align-items-center"
                 style="top:0;left:0;background:rgba(255,255,255,0.7);z-index:10;">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
            </div>

            <table class="table table-striped table-hover mb-0">
                <thead>
                <tr>
                    <th>Kasir</th>
                    <th>Perangkat</th>
                    <th>Lokasi</th>
                    <th>Modal Kas</th>
                    <th>Total Penjualan</th>
                    <th>Estimasi Kas</th>
                    <th>Status</th>
                    <th>Aktivitas Terakhir</th>
                    <th>Ambang Kas</th>
                </tr>
                </thead>
                <tbody>
                @forelse($sessions as $session)
                    <tr wire:key="session-{{ $session['id'] }}"
                        @class(['table-warning' => $session['alerts']['idle'] || $session['alerts']['cash']])>
                        <td class="align-middle">
                            <div class="font-weight-bold">{{ $session['cashier'] }}</div>
                            <div class="small text-muted">Mulai: {{ optional($session['started_at'])->format('d M Y H:i') }}</div>
                        </td>
                        <td class="align-middle">{{ $session['device'] }}</td>
                        <td class="align-middle">{{ $session['location'] }}</td>
                        <td class="align-middle">{{ format_currency($session['cash_float']) }}</td>
                        <td class="align-middle">{{ format_currency($session['sales_total']) }}</td>
                        <td class="align-middle font-weight-bold">{{ format_currency($session['estimated_cash']) }}</td>
                        <td class="align-middle">
                            @php
                                $status = strtolower($session['status']);
                                $statusClass = $status === 'active' ? 'success' : ($status === 'paused' ? 'warning' : 'secondary');
                            @endphp
                            <span class="badge badge-{{ $statusClass }}">{{ strtoupper($session['status']) }}</span>
                            @if($session['alerts']['idle'])
                                <span class="badge badge-warning">Idle</span>
                            @endif
                            @if($session['alerts']['cash'])
                                <span class="badge badge-danger">Kas Tinggi</span>
                            @endif
                        </td>
                        <td class="align-middle">
                            <div class="small font-weight-bold">{{ $session['last_activity_for_humans'] }}</div>
                            <div class="small text-muted">{{ $session['last_activity_at'] ?? 'Belum ada aktivitas' }}</div>
                        </td>
                        <td class="align-middle">
                            @if($session['threshold'])
                                {{ format_currency($session['threshold']) }}
                            @else
                                <span class="text-muted">Tidak diatur</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted">Belum ada sesi POS untuk ditampilkan.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            window.addEventListener('pos-session-alert', function (event) {
                const detail = event.detail || {};

                if (typeof Swal === 'undefined') {
                    return;
                }

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 4000,
                    timerProgressBar: true,
                    icon: detail.type || 'info',
                    title: detail.message || 'Ada peringatan sesi POS',
                });
            });
        });
    </script>
@endpush
