@can('purchaseReturns.dispatchExecute')
    @if($dispatchStatus === 'approved')
        <div class="modal fade" id="dispatchExecuteModal" tabindex="-1" aria-labelledby="dispatchExecuteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" action="{{ route('purchase-returns.dispatch', $purchase_return) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="dispatchExecuteModalLabel">Konfirmasi Dispatch Retur</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Dispatch barang retur ini?</p>
                            @include('purchasesreturn::partials.dispatch-info')
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Dispatch Return</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan
