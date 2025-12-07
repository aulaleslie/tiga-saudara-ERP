<div>
@if($showModal)
    <div class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Term Pembayaran</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="mb-3">
                            <label for="payment_term_name" class="form-label">Nama <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control @error('name') is-invalid @enderror"
                                id="payment_term_name"
                                wire:model="name"
                                placeholder="Masukkan nama term pembayaran"
                                required
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="payment_term_longevity" class="form-label">Jangka Waktu (hari) <span class="text-danger">*</span></label>
                            <input
                                type="number"
                                class="form-control @error('longevity') is-invalid @enderror"
                                id="payment_term_longevity"
                                wire:model="longevity"
                                placeholder="0"
                                min="0"
                                max="365"
                                required
                            >
                            <small class="form-text text-muted">Jumlah hari setelah tanggal faktur untuk jatuh tempo pembayaran</small>
                            @error('longevity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">Batal</button>
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        <span wire:loading.remove>Simpan</span>
                        <span wire:loading>
                            <span class="spinner-border spinner-border-sm" role="status"></span>
                            Menyimpan...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
</div>