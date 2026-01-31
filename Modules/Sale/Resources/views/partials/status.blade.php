@if ($data->status == \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL)
    <span class="badge badge-info">
        {{ $data->status }}
    </span>
@elseif ($data->status == \Modules\Sale\Entities\Sale::STATUS_DISPATCHED)
    <span class="badge badge-primary">
        {{ $data->status }}
    </span>
@else
    <span class="badge badge-success">
        {{ $data->status }}
    </span>
@endif
