<div>
    @if($showModal)
        <div class="modal show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);" wire:ignore.self wire:key="customer-modal" data-coreui-backdrop="false" data-coreui-keyboard="false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Pelanggan Baru</h5>
                        <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form wire:submit.prevent="save">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="customer_name" class="form-label">Nama Pelanggan <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="customer_name" wire:model="customer_name" required>
                                    @error('customer_name') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="tier" class="form-label">Tier Pelanggan</label>
                                    <select class="form-control" id="tier" wire:model="tier">
                                        @foreach($tierOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('tier') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_name" class="form-label">Nama Kontak</label>
                                    <input type="text" class="form-control" id="contact_name" wire:model="contact_name">
                                    @error('contact_name') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="customer_email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="customer_email" wire:model="customer_email">
                                    @error('customer_email') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="customer_phone" class="form-label">Telepon</label>
                                    <input type="text" class="form-control" id="customer_phone" wire:model="customer_phone">
                                    @error('customer_phone') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_term_id" class="form-label">Syarat Pembayaran</label>
                                    <select class="form-control" id="payment_term_id" wire:model="payment_term_id">
                                        <option value="">Pilih syarat pembayaran</option>
                                        @foreach($paymentTerms as $term)
                                            <option value="{{ $term->id }}">{{ $term->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('payment_term_id') <div class="text-danger">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="address" class="form-label">Alamat</label>
                                <textarea class="form-control" id="address" rows="3" wire:model="address"></textarea>
                                @error('address') <div class="text-danger">{{ $message }}</div> @enderror
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