@if ($data->status == \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL)
    <span class="badge badge-info">
        {{ \Modules\Sale\Entities\Sale::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
@elseif ($data->status == \Modules\Sale\Entities\Sale::STATUS_DISPATCHED)
    <span class="badge badge-primary">
        {{ \Modules\Sale\Entities\Sale::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
@else
    <span class="badge badge-success">
        {{ \Modules\Sale\Entities\Sale::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
@endif
