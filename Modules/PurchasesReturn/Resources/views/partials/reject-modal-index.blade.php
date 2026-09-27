@can('purchaseReturns.approval')
    <div class="modal fade" id="rejectPurchaseReturnModalIndex" tabindex="-1" aria-labelledby="rejectPurchaseReturnModalIndexLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="rejectPurchaseReturnFormIndex" method="POST" action="">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="rejectPurchaseReturnModalIndexLabel">Konfirmasi Penolakan</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Alasan Penolakan (opsional)</label>
                            <textarea name="reason" class="form-control" rows="3"></textarea>
                        </div>
                        <p class="mb-0">Apakah Anda yakin ingin menolak retur pembelian ini?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-outline-danger">Tolak</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
