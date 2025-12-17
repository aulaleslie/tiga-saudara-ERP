<div>
    <div class="modal fade {{ $showModal ? 'show d-block' : '' }}" tabindex="-1" style="background-color: {{ $showModal ? 'rgba(0,0,0,0.5)' : 'transparent' }};" wire:key="supplier-modal">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
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
                                <div class="col-md-12 mb-2">
                                    <h6 class="text-muted">Informasi Umum</h6>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contact_name" class="form-label">Nama Kontak <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('contact_name') is-invalid @enderror" id="contact_name" wire:model="contact_name" required>
                                    @error('contact_name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_name" class="form-label">Nama Perusahaan <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('supplier_name') is-invalid @enderror" id="supplier_name" wire:model="supplier_name" required>
                                    @error('supplier_name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="identity" class="form-label">Identitas</label>
                                    <select class="form-control @error('identity') is-invalid @enderror" id="identity" wire:model="identity">
                                        <option value="">-- Tidak ada Identitas --</option>
                                        <option value="KTP">KTP</option>
                                        <option value="SIM">SIM</option>
                                        <option value="Passport">Passport</option>
                                    </select>
                                    @error('identity') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="identity_number" class="form-label">Nomor Identitas</label>
                                    <input type="text" class="form-control @error('identity_number') is-invalid @enderror" id="identity_number" wire:model="identity_number">
                                    @error('identity_number') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_phone" class="form-label">Nomor Kontak</label>
                                    <input type="text" class="form-control @error('supplier_phone') is-invalid @enderror" id="supplier_phone" wire:model="supplier_phone">
                                    @error('supplier_phone') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="npwp" class="form-label">NPWP</label>
                                    <input type="text" class="form-control @error('npwp') is-invalid @enderror" id="npwp" wire:model="npwp">
                                    @error('npwp') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="billing_address" class="form-label">Alamat Penagihan</label>
                                    <input type="text" class="form-control @error('billing_address') is-invalid @enderror" id="billing_address" wire:model="billing_address">
                                    @error('billing_address') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="shipping_address" class="form-label">Alamat Pengiriman</label>
                                    <input type="text" class="form-control @error('shipping_address') is-invalid @enderror" id="shipping_address" wire:model="shipping_address">
                                    @error('shipping_address') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_email" class="form-label">Email</label>
                                    <input type="email" class="form-control @error('supplier_email') is-invalid @enderror" id="supplier_email" wire:model="supplier_email">
                                    @error('supplier_email') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_term_id" class="form-label">Syarat Pembayaran</label>
                                    <select class="form-control @error('payment_term_id') is-invalid @enderror" id="payment_term_id" wire:model="payment_term_id">
                                        <option value="">-- Pilih Syarat Pembayaran --</option>
                                        @foreach($paymentTerms as $term)
                                            <option value="{{ $term->id }}">{{ $term->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('payment_term_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mt-2 mb-2">
                                    <h6 class="text-muted">Info Bank</h6>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Nama Bank</label>
                                    <input type="text" class="form-control @error('bank_name') is-invalid @enderror" id="bank_name" wire:model="bank_name">
                                    @error('bank_name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_branch" class="form-label">Kantor Cabang Bank</label>
                                    <input type="text" class="form-control @error('bank_branch') is-invalid @enderror" id="bank_branch" wire:model="bank_branch">
                                    @error('bank_branch') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="account_number" class="form-label">Nomor Rekening</label>
                                    <input type="text" class="form-control @error('account_number') is-invalid @enderror" id="account_number" wire:model="account_number">
                                    @error('account_number') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="account_holder" class="form-label">Pemegang Akun Bank</label>
                                    <input type="text" class="form-control @error('account_holder') is-invalid @enderror" id="account_holder" wire:model="account_holder">
                                    @error('account_holder') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
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
</div>
