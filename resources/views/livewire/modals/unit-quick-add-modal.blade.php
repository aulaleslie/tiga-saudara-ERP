<div>
@if($showModal)
    <div class="modal show d-block" style="background-color: rgba(0,0,0,0.5);" tabindex="-1" wire:key="unit-modal" data-coreui-backdrop="false" data-coreui-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Unit</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="mb-3">
                            <label for="unit_name" class="form-label">Nama Unit <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control @error('name') is-invalid @enderror"
                                id="unit_name"
                                wire:model="name"
                                placeholder="Masukkan nama unit lengkap"
                                required
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="unit_short_name" class="form-label">Nama Singkat <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control @error('short_name') is-invalid @enderror"
                                id="unit_short_name"
                                wire:model="short_name"
                                placeholder="Masukkan nama singkat (contoh: kg, pcs, liter)"
                                required
                            >
                            <small class="form-text text-muted">Nama singkat akan ditampilkan di form produk</small>
                            @error('short_name')
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