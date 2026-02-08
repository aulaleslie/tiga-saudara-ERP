@php
    $status = $data->unified_status;
    $label = $data->unified_status_label;
@endphp

@switch($status)
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_DRAFT)
        <span class="badge bg-dark text-white">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_PENDING_APPROVAL)
        <span class="badge bg-warning text-dark">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_REJECTED)
        <span class="badge bg-danger text-white">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_AWAITING_DISPATCH)
        <span class="badge bg-info text-dark">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL)
        <span class="badge bg-warning text-dark">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_IN_RETURN)
        <span class="badge bg-primary text-white">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_SETTLEMENT_CONFIRMATION_PENDING)
        <span class="badge bg-info text-dark">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_WAITING_REPLACEMENT_GOODS)
        <span class="badge bg-primary text-white">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_PARTIAL_SETTLEMENT)
        <span class="badge bg-warning text-dark">{{ $label }}</span>
        @break
    @case(\Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_COMPLETED)
        <span class="badge bg-success text-white">{{ $label }}</span>
        @break
    @default
        <span class="badge bg-secondary text-white">{{ $label }}</span>
@endswitch
