@can('purchaseReturns.approval')
    @if($approvalStatus === 'pending')
        <div class="modal fade" id="rejectPurchaseReturnModal" tabindex="-1" aria-labelledby="rejectPurchaseReturnModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('purchase-returns.reject', $purchase_return) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="rejectPurchaseReturnModalLabel">Konfirmasi Penolakan</h5>
                            <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Alasan Penolakan (opsional)</label>
                                <textarea name="reason" class="form-control" rows="3">{{ old('reason') }}</textarea>
                            </div>
                            <p class="mb-0">Apakah Anda yakin ingin menolak retur pembelian ini?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-outline-danger">Tolak</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan
