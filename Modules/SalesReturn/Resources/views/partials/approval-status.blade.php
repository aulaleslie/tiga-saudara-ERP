@php
    $status = strtolower($data->approval_status ?? 'pending');
@endphp

@switch($status)
    @case('approved')
        <span class="badge bg-success text-uppercase">Disetujui</span>
        @break
    @case('rejected')
        <span class="badge bg-danger text-uppercase">Ditolak</span>
        @break
    @case('pending')
        <span class="badge bg-warning text-dark text-uppercase">Menunggu</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ ucfirst($status) }}</span>
@endswitch
