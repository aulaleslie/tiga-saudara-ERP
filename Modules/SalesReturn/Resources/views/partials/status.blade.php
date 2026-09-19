@php
    $status = (string) ($data->status ?? '');
    $statusLower = strtolower($status);
    $label = \Modules\SalesReturn\Entities\SaleReturn::statusLabel($status);
@endphp

@switch($statusLower)
    @case('pending approval')
    @case('pending')
        <span class="badge bg-warning text-dark text-uppercase">{{ $label }}</span>
        @break
    @case('awaiting receiving')
        <span class="badge bg-primary text-uppercase">{{ $label }}</span>
        @break
    @case('awaiting settlement')
        <span class="badge bg-info text-dark text-uppercase">{{ $label }}</span>
        @break
    @case('rejected')
        <span class="badge bg-danger text-uppercase">{{ $label }}</span>
        @break
    @case('completed')
        <span class="badge bg-success text-uppercase">{{ $label }}</span>
        @break
    @case('cancelled')
        <span class="badge bg-secondary text-uppercase">{{ $label }}</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ $label ?: 'Draf' }}</span>
@endswitch

