<div>
    <div class="modal fade {{ $showModal ? 'show d-block' : '' }}" tabindex="-1" style="background-color: {{ $showModal ? 'rgba(0,0,0,0.5)' : 'transparent' }};" wire:key="supplier-modal">
        <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Pemasok Baru</h5>
                        <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if (session()->has('error'))
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                {{ session('error') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif
                        <form>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_name" class="form-label">Nama Pemasok <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('supplier_name') is-invalid @enderror" id="supplier_name" wire:model="supplier_name" required>
                                    @error('supplier_name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contact_name" class="form-label">Nama Kontak</label>
                                    <input type="text" class="form-control" id="contact_name" wire:model="contact_name">
                                    @error('contact_name') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" wire:model="email">
                                    @error('email') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Telepon</label>
                                    <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" wire:model="phone">
                                    @error('phone') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="address" class="form-label">Alamat</label>
                                <textarea class="form-control" id="address" rows="3" wire:model="address"></textarea>
                                @error('address') <div class="text-danger">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3">
                                <label for="payment_term_id" class="form-label">Syarat Pembayaran</label>
                                <select class="form-control" id="payment_term_id" wire:model="payment_term_id">
                                    <option value="">Pilih syarat pembayaran</option>
                                    @foreach($paymentTerms as $term)
                                        <option value="{{ $term->id }}">{{ $term->name }}</option>
                                    @endforeach
                                </select>
                                @error('payment_term_id') <div class="text-danger">{{ $message }}</div> @enderror
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
</div>