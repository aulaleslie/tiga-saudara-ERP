<div>
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Edit Retur: {{ $return->reference }} (Transaksi: {{ $snapshot['header']['transaction_code'] }})</strong>
                        <span class="badge badge-warning ml-2">Draft</span>
                    </div>
                </div>
                <div class="card-body">
                    @include('livewire.modules.pos.pos-return.partials.form-surface', ['paymentHeading' => 'Pembayaran Asli:'])

                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="{{ route('pos.returns.show', $return->id) }}" class="btn btn-secondary btn-lg">Batal</a>
                        <button wire:click="submit" class="btn btn-success btn-lg" wire:loading.attr="disabled">
                            <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" role="status"></span>
                            Simpan Perubahan Retur
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
