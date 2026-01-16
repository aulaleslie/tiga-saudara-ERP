@can('purchaseReturns.dispatchApproval')
    @if($dispatchStatus === 'pending_approval')
        <div class="modal fade" id="rejectDispatchModal" tabindex="-1" aria-labelledby="rejectDispatchModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('purchase-returns.dispatch-reject', $purchase_return) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="rejectDispatchModalLabel">Konfirmasi Penolakan Dispatch</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Alasan Penolakan (opsional)</label>
                                <textarea name="reason" class="form-control" rows="3">{{ old('reason') }}</textarea>
                            </div>
                            <p class="mb-0">Apakah Anda yakin ingin menolak dispatch retur ini?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-outline-danger">Tolak Dispatch</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan
