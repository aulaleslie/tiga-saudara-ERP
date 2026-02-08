@php $status = strtoupper($item->status ?? 'DRAFT'); @endphp
@switch($status)
    @case('DRAFT')
        <span class="badge bg-dark text-white">Draf</span>
        @break
    @case('SUBMITTED')
        <span class="badge bg-info text-dark">Menunggu Persetujuan</span>
        @break
    @case('APPROVED')
        <span class="badge bg-success text-white">Disetujui</span>
        @break
    @case('APPROVED_AWAITING_RECEIVE')
        <span class="badge bg-warning text-dark">Menunggu Penerimaan</span>
        @break
    @case('RECEIVED')
        <span class="badge bg-success text-white">Diterima</span>
        @break
    @case('REJECTED')
        <span class="badge bg-danger text-white" title="{{ $item->rejection_reason }}">Ditolak</span>
        @break
    @default
        <span class="badge bg-secondary text-white">{{ $status }}</span>
@endswitch
