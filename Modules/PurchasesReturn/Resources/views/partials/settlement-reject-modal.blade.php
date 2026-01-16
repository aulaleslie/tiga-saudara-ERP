@can('purchaseReturnSettlements.approve')
    @if($purchase_return->settlement && $purchase_return->settlement->status === 'pending')
        <div class="modal fade" id="rejectSettlementModal" tabindex="-1" aria-labelledby="rejectSettlementModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('purchase-return-settlements.reject', $purchase_return->settlement->id) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="rejectSettlementModalLabel">Konfirmasi Penolakan Settlement</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Alasan Penolakan (opsional)</label>
                                <textarea name="rejection_reason" class="form-control" rows="3">{{ old('rejection_reason') }}</textarea>
                            </div>
                            <p class="mb-0">Apakah Anda yakin ingin menolak penyelesaian ini?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-outline-danger">Tolak Settlement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan
