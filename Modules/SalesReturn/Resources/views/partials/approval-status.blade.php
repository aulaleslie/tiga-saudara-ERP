@php
    $status = strtolower($data->approval_status ?? 'pending');
    $label = \Modules\SalesReturn\Entities\SaleReturn::approvalStatusLabel($status);
@endphp

@switch($status)
    @case('approved')
        <span class="badge bg-success text-uppercase">{{ $label }}</span>
        @break
    @case('rejected')
        <span class="badge bg-danger text-uppercase">{{ $label }}</span>
        @break
    @case('pending')
        <span class="badge bg-warning text-dark text-uppercase">{{ $label }}</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ $label }}</span>
@endswitch

