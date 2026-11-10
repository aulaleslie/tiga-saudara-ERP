@php
    $map = [
        'DRAFT' => ['secondary', 'DRAFT'], 'WAITING_APPROVAL' => ['warning', 'MENUNGGU PERSETUJUAN'], 'APPROVED' => ['info', 'DISETUJUI (DICADANGKAN)'],
        'REJECTED' => ['danger', 'DITOLAK'], 'CANCELLED' => ['dark', 'DIBATALKAN'], 'FINALIZED' => ['success', 'FINAL'], 'REVERSED' => ['dark', 'DI-REVERSAL'],
    ];
    [$class, $label] = $map[strtoupper($status)] ?? ['secondary', $status];
@endphp
<span class="badge badge-{{ $class }}">{{ $label }}</span>
