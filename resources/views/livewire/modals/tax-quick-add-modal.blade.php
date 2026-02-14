<div>
@if($showModal)
    <div class="modal show d-block" style="background-color: rgba(0,0,0,0.5);" tabindex="-1" wire:key="tax-modal" data-coreui-backdrop="false" data-coreui-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Pajak</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="mb-3">
                            <label for="tax_name" class="form-label">Nama <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control @error('name') is-invalid @enderror"
                                id="tax_name"
                                wire:model="name"
                                placeholder="Masukkan nama pajak"
                                required
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="tax_value" class="form-label">Nilai (%) <span class="text-danger">*</span></label>
                            <input
                                type="number"
                                class="form-control @error('value') is-invalid @enderror"
                                id="tax_value"
                                wire:model="value"
                                placeholder="0.00"
                                min="0"
                                max="100"
                                step="0.01"
                                required
                            >
                            <small class="form-text text-muted">Persentase pajak (0-100)</small>
                            @error('value')
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