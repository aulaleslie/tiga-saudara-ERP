<div>
@if($showModal)
    <div class="modal show d-block" style="background-color: rgba(0,0,0,0.5);" tabindex="-1" wire:key="expense-category-modal" data-coreui-backdrop="false" data-coreui-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Kategori Biaya</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="save" wire:key="expense-category-form-{{ $formResetVersion }}">
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control @error('category_name') is-invalid @enderror"
                                id="category_name"
                                wire:model="category_name"
                                placeholder="Masukkan nama kategori"
                                required
                            >
                            @error('category_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="category_description" class="form-label">Deskripsi</label>
                            <textarea
                                class="form-control @error('category_description') is-invalid @enderror"
                                id="category_description"
                                wire:model="category_description"
                                placeholder="Opsional"
                                rows="3"
                            ></textarea>
                            @error('category_description')
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
