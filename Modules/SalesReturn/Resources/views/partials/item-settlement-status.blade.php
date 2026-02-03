@php $status = strtoupper($item->status ?? 'DRAFT'); @endphp
@switch($status)
    @case('DRAFT')
        <span class="badge bg-secondary">Draft</span>
        @break
    @case('SUBMITTED')
        <span class="badge bg-info">Menunggu Persetujuan</span>
        @break
    @case('APPROVED')
        <span class="badge bg-success">Disetujui</span>
        @break
    @case('APPROVED_AWAITING_DISPATCH')
        <span class="badge bg-warning text-dark">Menunggu Pengiriman</span>
        @break
    @case('DISPATCH_REQUESTED')
        <span class="badge bg-primary">Pengiriman Diminta</span>
        @break
    @case('DISPATCHED')
        <span class="badge bg-success">Terkirim</span>
        @break
    @case('REJECTED')
        <span class="badge bg-danger" title="{{ $item->rejection_reason }}">Ditolak</span>
        @break
    @default
        <span class="badge bg-secondary">{{ $status }}</span>
@endswitch
