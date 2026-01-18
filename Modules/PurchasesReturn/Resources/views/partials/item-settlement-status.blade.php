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
    @case('APPROVED_AWAITING_RECEIVE')
        <span class="badge bg-warning text-dark">Menunggu Penerimaan</span>
        @break
    @case('RECEIVED')
        <span class="badge bg-success">Diterima</span>
        @break
    @case('REJECTED')
        <span class="badge bg-danger" title="{{ $item->rejection_reason }}">Ditolak</span>
        @break
    @default
        <span class="badge bg-secondary">{{ $status }}</span>
@endswitch
