    <div class="modal fade" id="pos-customer-create-modal" tabindex="-1" role="dialog" aria-labelledby="pos-customer-create-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form id="pos-customer-create-form">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pos-customer-create-modal-label">Tambah Pelanggan Baru</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div id="pos-customer-create-error" class="alert alert-danger d-none small"></div>
                        
                        <div class="form-group mb-3">
                            <label for="pos-new-customer-name" class="font-weight-bold">Nama Pelanggan <span class="text-danger">*</span></label>
                            <input type="text" id="pos-new-customer-name" class="form-control" placeholder="Masukkan nama pelanggan" required>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label for="pos-new-customer-phone" class="font-weight-bold">No. Telepon <span class="text-muted font-weight-normal">(Opsional)</span></label>
                            <input type="text" id="pos-new-customer-phone" class="form-control" placeholder="Masukkan nomor telepon">
                        </div>

                        <div class="form-group mb-0 d-none">
                            <label for="pos-new-customer-tier" class="font-weight-bold">Tier Pelanggan <span class="text-muted font-weight-normal">(Opsional)</span></label>
                            <select id="pos-new-customer-tier" class="form-control">
                                <!-- Options populated from Constants -->
                                @foreach(\App\Constants\CustomerTier::options() as $val => $label)
                                    <option value="{{ $val }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light p-3">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" id="pos-customer-create-submit" class="btn btn-primary d-inline-flex align-items-center">
                            <span class="spinner-border spinner-border-sm mr-2 d-none" role="status" aria-hidden="true" id="pos-customer-create-spinner"></span>
                            Simpan Pelanggan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
