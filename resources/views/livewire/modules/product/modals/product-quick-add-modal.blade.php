<div>
    @if($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);" wire:ignore.self>
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Produk Baru</h5>
                        <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form wire:submit.prevent="save">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="product_name" class="form-label">Nama Produk <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="product_name" wire:model="product_name" required>
                                    @error('product_name') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="product_code" class="form-label">Kode Produk</label>
                                    <input type="text" class="form-control" id="product_code" wire:model="product_code">
                                    <small class="form-text text-muted">Biarkan kosong untuk auto-generate</small>
                                    @error('product_code') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="category_id" class="form-label">Kategori</label>
                                    <select class="form-control" id="category_id" wire:model="category_id">
                                        <option value="">Pilih kategori</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->category_name }}</option>
                                        @endforeach
                                    </select>
                                    @error('category_id') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="brand_id" class="form-label">Merek</label>
                                    <select class="form-control" id="brand_id" wire:model="brand_id">
                                        <option value="">Pilih merek</option>
                                        @foreach($brands as $brand)
                                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('brand_id') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="unit_id" class="form-label">Satuan <span class="text-danger">*</span></label>
                                    <select class="form-control" id="unit_id" wire:model="unit_id" required>
                                        <option value="">Pilih satuan</option>
                                        @foreach($units as $unit)
                                            <option value="{{ $unit->id }}">{{ $unit->unit_name }}</option>
                                        @endforeach
                                    </select>
                                    @error('unit_id') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Deskripsi</label>
                                <textarea class="form-control" id="description" rows="3" wire:model="description"></textarea>
                                @error('description') <div class="text-danger">{{ $message }}</div> @enderror
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_purchased" wire:model="is_purchased">
                                        <label class="form-check-label" for="is_purchased">
                                            Dapat Dibeli
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_sold" wire:model="is_sold">
                                        <label class="form-check-label" for="is_sold">
                                            Dapat Dijual
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeModal">Batal</button>
                        <button type="button" class="btn btn-primary" wire:click="save">
                            <span wire:loading.remove wire:target="save">Simpan</span>
                            <span wire:loading wire:target="save">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                Menyimpan...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>