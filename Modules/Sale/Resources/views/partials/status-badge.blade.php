@if ($data->status == 'DRAFTED')
    <span class="badge badge-secondary">
        Draft
    </span>
@elseif ($data->status == 'APPROVED')
    <span class="badge badge-warning">
        Disetujui
    </span>
@elseif ($data->status == 'DISPATCHED')
    <span class="badge badge-success">
        Dikirim
    </span>
@elseif ($data->status == 'RETURNED')
    <span class="badge badge-danger">
        Dikembalikan
    </span>
@else
    <span class="badge badge-light">
        {{ $data->status }}
    </span>
@endif