@php
    $status = strtolower($data->payment_status ?? 'unpaid');
@endphp

@switch($status)
    @case('paid')
        <span class="badge bg-success text-uppercase">Lunas</span>
        @break
    @case('partial')
        <span class="badge bg-warning text-dark text-uppercase">Sebagian</span>
        @break
    @default
        <span class="badge bg-danger text-uppercase">Belum Lunas</span>
@endswitch
