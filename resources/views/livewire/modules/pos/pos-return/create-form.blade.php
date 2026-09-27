<div>
    @if(!$snapshot)
        <div class="card">
            <div class="card-body">
                <form wire:submit.prevent="lookup">
                    <div class="form-group">
                        <label for="identifier">Masukkan Kode Transaksi atau Nomor Struk POS</label>
                        <div class="input-group">
                            <input wire:model="identifier" type="text" id="identifier" class="form-control @error('identifier') is-invalid @enderror" placeholder="Contoh: TRX-2026..., RCP-2026...">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                                    <span wire:loading wire:target="lookup" class="spinner-border spinner-border-sm" role="status"></span>
                                    Cari Transaksi
                                </button>
                            </div>
                        </div>
                        @error('identifier') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </form>

                @if($error)
                    <div class="alert alert-danger mt-3">
                        {{ $error }}
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Snapshot Transaksi: {{ $snapshot['header']['transaction_code'] }}</strong>
                        </div>
                        <button wire:click="resetLookup" class="btn btn-sm btn-secondary">Cari Transaksi Lain</button>
                    </div>
                    <div class="card-body">
                        @include('livewire.modules.pos.pos-return.partials.form-surface', ['paymentHeading' => 'Pembayaran:'])

                        <div class="mt-4 d-flex justify-content-end gap-2">
                            <button wire:click="submit" class="btn btn-success btn-lg" wire:loading.attr="disabled">
                                <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" role="status"></span>
                                Simpan Draft Retur POS
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
