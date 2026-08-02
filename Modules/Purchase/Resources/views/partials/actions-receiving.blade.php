@php use Modules\Purchase\Entities\Purchase; @endphp
<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        @if ($data->status === Purchase::STATUS_APPROVED || $data->status === Purchase::STATUS_RECEIVED_PARTIALLY)
            @can('purchases.receive')
            <a href="{{ route('purchases.receive', $data->id) }}" class="dropdown-item text-primary">
                <i class="bi bi-box-arrow-in-down mr-2"></i> Menerima
            </a>
            @endcan
        @endif

        @if ($data->status === Purchase::STATUS_RECEIVED_PARTIALLY)
            @can('purchases.receive.complete_shortfall')
            <button type="button" class="dropdown-item text-success" wire:click="$dispatch('openReceivingCompletionModal', { purchase: {{ $data->id }} })">
                <i class="bi bi-check2-circle mr-2"></i> Selesaikan Penerimaan
            </button>
            @endcan
        @endif
    </div>
</div>
