<div>
@if($showModal)
    <div class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Kategori Produk</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>

                <div class="modal-body">
                    <form wire:submit.prevent="save">
                        <div class="mb-3">
                            <label for="category_code" class="form-label">Kode Kategori</label>
                            <input
                                type="text"
                                class="form-control @error('category_code') is-invalid @enderror"
                                id="category_code"
                                wire:model="category_code"
                                placeholder="Masukkan kode kategori (kosongkan untuk auto-generate)"
                            >
                            @error('category_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

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
                            <label for="parent_id" class="form-label">Kategori Induk</label>
                            <livewire:modules.product.category-search-dropdown
                                wire:key="category-parent-dropdown"
                                name="parent_id"
                                :options="$this->parentCategoryOptions"
                                :selected="$parent_id"
                                dispatch-to="modules.product.modals.category-quick-add-modal"
                                :root-only="true"
                                placeholder="Pilih kategori induk (opsional)"
                            />
                            <small class="form-text text-muted">Biarkan kosong jika ini adalah kategori utama</small>
                            @error('parent_id')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
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
