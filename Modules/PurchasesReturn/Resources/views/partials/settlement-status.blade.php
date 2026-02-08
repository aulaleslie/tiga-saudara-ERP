@php
    $badgeClass = 'badge bg-dark text-white';
    $label = 'Belum Diproses';
    $description = null;
    $approvalStatus = strtolower($data->approval_status ?? '');

    // Load settlement items if not already loaded
    $items = $data->relationLoaded('settlementItems') 
        ? $data->settlementItems 
        : $data->settlementItems()->get();

    $allApproved = $items->isNotEmpty() && $items->every(fn($i) => strtoupper($i->status) === 'APPROVED');
    $anyApproved = $items->contains(fn($i) => strtoupper($i->status) === 'APPROVED');
    $anySubmitted = $items->contains(fn($i) => strtoupper($i->status) === 'SUBMITTED');

    if ($allApproved) {
        $badgeClass = 'badge bg-success text-white';
        $label = 'Selesai';
        $methodLabels = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::settlementMethods();
        $methods = $items->pluck('method')->unique()->filter()->map(fn($m) => $methodLabels[$m] ?? $m)->implode(', ');
        if ($methods) {
            $description = 'Metode: ' . $methods;
        }
    } elseif ($anyApproved) {
        $badgeClass = 'badge bg-warning text-dark';
        $label = 'Selesai Sebagian';
        $approvedCount = $items->filter(fn($i) => strtoupper($i->status) === 'APPROVED')->count();
        $description = $approvedCount . ' dari ' . $items->count() . ' item disetujui';
    } elseif ($approvalStatus === 'rejected') {
        $badgeClass = 'badge bg-danger text-white';
        $label = 'Ditolak';
        if ($data->rejection_reason) {
            $description = 'Alasan: ' . $data->rejection_reason;
        }
    } elseif ($anySubmitted) {
        $badgeClass = 'badge bg-info text-dark';
        $label = 'Menunggu Persetujuan Item';
    } elseif ($data->status === 'Awaiting Settlement' || ($approvalStatus === 'approved' && $items->isEmpty())) {
        $badgeClass = 'badge bg-info text-dark';
        $label = 'Menunggu Penyelesaian';
    } elseif ($approvalStatus !== 'approved') {
        $badgeClass = 'badge bg-warning text-dark';
        $label = 'Menunggu Persetujuan';
    }
@endphp

<span class="{{ $badgeClass }}">{{ $label }}</span>
@if($description)
    <div class="small text-muted mt-1">{{ $description }}</div>
@endif
