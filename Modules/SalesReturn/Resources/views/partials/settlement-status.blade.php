@php
    $badgeClass = 'badge bg-secondary';
    $label = 'Belum Diproses';
    $description = null;
    $approvalStatus = strtolower($data->approval_status ?? '');

    $methodMap = [
        'Cash' => 'Pengembalian Tunai',
        'Replacement' => 'Penggantian Produk',
        'Customer Credit' => 'Kredit Pelanggan',
    ];

    if ($data->settled_at) {
        $badgeClass = 'badge bg-success';
        $label = 'Selesai';
        if ($data->payment_method) {
            $description = 'Metode: ' . ($methodMap[$data->payment_method] ?? $data->payment_method);
        }
    } elseif ($approvalStatus === 'rejected') {
        $badgeClass = 'badge bg-danger';
        $label = 'Ditolak';
        if ($data->rejection_reason) {
            $description = 'Alasan: ' . $data->rejection_reason;
        }
    } elseif ($data->status === 'Awaiting Settlement' || ($approvalStatus === 'approved' && ! $data->settled_at)) {
        $badgeClass = 'badge bg-info text-dark';
        $label = 'Menunggu Penyelesaian';
        if (empty($data->return_type)) {
            $description = 'Metode penyelesaian belum dipilih.';
        }
    } elseif ($approvalStatus !== 'approved') {
        $badgeClass = 'badge bg-warning text-dark';
        $label = 'Menunggu Persetujuan';
    }
@endphp

<span class="{{ $badgeClass }}">{{ $label }}</span>
@if($description)
    <div class="small text-muted mt-1">{{ $description }}</div>
@endif
