@foreach($purchase_return->settlementItems->where('status', 'SUBMITTED') as $item)
<div class="modal fade" id="rejectItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="rejectItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('purchase-return-settlements.item.reject', $item->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectItemModalLabel{{ $item->id }}">Tolak Item: {{ $item->detail?->product_name }}</h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Sebutkan alasan penolakan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Tolak Item</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach
