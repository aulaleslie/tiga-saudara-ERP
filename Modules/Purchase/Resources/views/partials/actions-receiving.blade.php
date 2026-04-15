<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        @if ($data->status === \Modules\Purchase\Entities\Purchase::STATUS_APPROVED || $data->status === \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED_PARTIALLY)
            @can('purchases.receive')
            <a href="{{ route('purchases.receive', $data->id) }}" class="dropdown-item text-primary">
                <i class="bi bi-box-arrow-in-down mr-2"></i> Menerima
            </a>
            @endcan
        @endif
    </div>
</div>
