@php
    $status = $data->status;
@endphp

@switch($status)
    @case('Pending')
    @case('Pending Approval')
        <span class="badge bg-warning text-dark text-uppercase">{{ $status }}</span>
        @break
    @case('Rejected')
        <span class="badge bg-danger text-uppercase">{{ $status }}</span>
        @break
    @default
        <span class="badge bg-success text-uppercase">{{ $status }}</span>
@endswitch
