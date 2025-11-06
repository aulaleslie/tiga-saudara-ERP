<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        @if ($data->status === 'APPROVED' || $data->status === 'RECEIVED_PARTIALLY')
            <a href="{{ route('purchases.receive', $data->id) }}" class="dropdown-item text-primary">
                <i class="bi bi-box-arrow-in-down mr-2"></i> Menerima
            </a>
        @endif
    </div>
</div>
