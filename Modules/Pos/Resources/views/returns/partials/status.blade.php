@php
    $status = (string) ($data->status ?? '');
    $normalized = strtolower(str_replace('_', ' ', $status));
@endphp

@switch($normalized)
    @case('pending approval')
        <span class="badge bg-warning text-dark text-uppercase">Menunggu Persetujuan</span>
        @break
    @case('approved')
        <span class="badge bg-success text-uppercase">Disetujui</span>
        @break
    @case('awaiting receiving')
        <span class="badge bg-primary text-uppercase">Menunggu Penerimaan</span>
        @break
    @case('awaiting settlement')
        <span class="badge bg-info text-dark text-uppercase">Menunggu Penyelesaian</span>
        @break
    @case('awaiting dispatch')
        <span class="badge bg-warning text-dark text-uppercase">Menunggu Pengiriman</span>
        @break
    @case('manual correction required')
        <span class="badge bg-danger text-uppercase">Koreksi Manual Diperlukan</span>
        @break
    @case('rejected')
        <span class="badge bg-danger text-uppercase">Ditolak</span>
        @break
    @case('cancelled')
        <span class="badge bg-danger text-uppercase">Dibatalkan</span>
        @break
    @case('completed')
        <span class="badge bg-success text-uppercase">Selesai</span>
        @break
    @case('archived')
        <span class="badge bg-secondary text-uppercase">Diarsipkan</span>
        @break
    @case('draft')
        <span class="badge bg-secondary text-uppercase">Draf</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ $status !== '' ? (\Modules\Pos\Entities\PosReturn::STATUS_LABELS[$status] ?? \Modules\Pos\Entities\PosReturn::STATUS_LABELS[$normalized] ?? str_replace('_', ' ', $status)) : 'Draf' }}</span>
@endswitch