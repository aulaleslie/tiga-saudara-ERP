@php
    $status = $data->status;
@endphp

@switch($status)
    @case('Pending')
    @case('Pending Approval')
        <span class="badge badge-warning text-dark">{{ $status }}</span>
        @break
    @case('Rejected')
        <span class="badge badge-danger">{{ $status }}</span>
        @break
    @default
        <span class="badge badge-success">{{ $status }}</span>
@endswitch
