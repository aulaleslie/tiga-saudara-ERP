@can('purchaseReturns.dispatchRequest')
    <div class="modal fade" id="dispatchRequestConfirmModal" tabindex="-1" aria-labelledby="dispatchRequestConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dispatchRequestConfirmModalLabel">Konfirmasi Pengajuan Dispatch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Ajukan dispatch retur ini?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="dispatch-request-confirm-cancel">Batal</button>
                    <button type="button" class="btn btn-primary" id="dispatch-request-confirm-submit">Ajukan</button>
                </div>
            </div>
        </div>
    </div>
@endcan
