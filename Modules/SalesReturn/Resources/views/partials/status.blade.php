@php
    $status = (string) ($data->status ?? '');
    $statusLower = strtolower($status);
@endphp

@switch($statusLower)
    @case('pending approval')
    @case('pending')
        <span class="badge bg-warning text-dark text-uppercase">{{ $status ?: 'Pending' }}</span>
        @break
    @case('awaiting receiving')
        <span class="badge bg-primary text-uppercase">{{ $status }}</span>
        @break
    @case('awaiting settlement')
        <span class="badge bg-info text-dark text-uppercase">{{ $status }}</span>
        @break
    @case('rejected')
        <span class="badge bg-danger text-uppercase">{{ $status }}</span>
        @break
    @case('completed')
        <span class="badge bg-success text-uppercase">{{ $status }}</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ $status ?: 'Draft' }}</span>
@endswitch
