@php
    $status = $data->unified_status;
    $label = $data->unified_status_label;
@endphp

@switch($status)
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_DRAFT)
        <span class="badge bg-secondary text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_PENDING_APPROVAL)
        <span class="badge bg-warning text-dark text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_REJECTED)
        <span class="badge bg-danger text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_AWAITING_DISPATCH)
        <span class="badge bg-info text-dark text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL)
        <span class="badge bg-warning text-dark text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_IN_RETURN)
        <span class="badge bg-primary text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_PARTIAL_SETTLEMENT)
        <span class="badge bg-warning text-dark text-uppercase">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_COMPLETED)
        <span class="badge bg-success text-uppercase">{{ $label }}</span>
        @break
    @default
        <span class="badge bg-secondary text-uppercase">{{ $label }}</span>
@endswitch
