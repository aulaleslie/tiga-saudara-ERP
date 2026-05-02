@php
    $status = (string) ($data->status ?? '');
    $normalized = strtolower(str_replace('_', ' ', $status));
@endphp

@switch($normalized)
    @case('pending approval')
        <span class="badge bg-warning text-dark text-uppercase">Pending Approval</span>
        @break
    @case('approved')
        <span class="badge bg-success text-uppercase">Approved</span>
        @break
    @case('awaiting receiving')
        <span class="badge bg-primary text-uppercase">Awaiting Receiving</span>
        @break
    @case('awaiting settlement')
        <span class="badge bg-info text-dark text-uppercase">Awaiting Settlement</span>
        @break
    @case('awaiting dispatch')
        <span class="badge bg-warning text-dark text-uppercase">Awaiting Dispatch</span>
        @break
    @case('manual correction required')
        <span class="badge bg-danger text-uppercase">Manual Correction Required</span>
        @break
    @case('rejected')
    @case('cancelled')
        <span class="badge bg-danger text-uppercase">{{ str_replace('_', ' ', $status) }}</span>
        @break
    @case('completed')
        <span class="badge bg-success text-uppercase">Completed</span>
        @break
    @case('archived')
        <span class="badge bg-secondary text-uppercase">Archived</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ $status !== '' ? str_replace('_', ' ', $status) : 'Draft' }}</span>
@endswitch