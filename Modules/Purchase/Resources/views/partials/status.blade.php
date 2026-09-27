@if ($data->status == \Modules\Purchase\Entities\Purchase::STATUS_WAITING_APPROVAL)
    <span class="badge badge-info">
        {{ \Modules\Purchase\Entities\Purchase::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
@elseif ($data->status == \Modules\Purchase\Entities\Purchase::STATUS_APPROVED)
    <span class="badge badge-primary">
        {{ \Modules\Purchase\Entities\Purchase::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
@else
    <span class="badge badge-success">
        {{ \Modules\Purchase\Entities\Purchase::STATUS_LABELS[$data->status] ?? $data->status }}
    </span>
@endif
