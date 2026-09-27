@can('purchaseReturns.approval')
    <div class="modal fade" id="approvePurchaseReturnModalIndex" tabindex="-1" aria-labelledby="approvePurchaseReturnModalIndexLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="approvePurchaseReturnFormIndex" method="POST" action="">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="approvePurchaseReturnModalIndexLabel">Konfirmasi Persetujuan</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        Apakah Anda yakin ingin menyetujui retur pembelian ini?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success">Setujui</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan
