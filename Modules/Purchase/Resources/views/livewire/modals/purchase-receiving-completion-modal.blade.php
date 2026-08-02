<div>
@if($showModal && $purchase)
    <div class="modal show d-block" style="background-color: rgba(0,0,0,0.5);" tabindex="-1" wire:key="receiving-completion-modal-{{ $purchase->id }}" data-coreui-backdrop="false" data-coreui-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Selesaikan Penerimaan Barang - {{ $purchase->reference }}</h5>
                    <button type="button" class="btn-close" wire:click="closeModal"></button>
                </div>

                <div class="modal-body">
                    @if($error)
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Kesalahan:</strong> {{ $error }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    @if($preview)
                        <!-- Original vs Final Comparison -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3">Ringkasan Penyesuaian</h6>

                            <!-- Financial Summary -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body p-3">
                                            <small class="text-muted d-block">Total Sebelum</small>
                                            <strong class="fs-5">{{ number_format($preview['original']['total_amount'], 0, ',', '.') }}</strong>
                                            <br>
                                            <small class="text-muted">Pajak: {{ number_format($preview['original']['tax_amount'], 0, ',', '.') }}</small>
                                            <br>
                                            <small class="text-muted">Diskon: {{ number_format($preview['original']['discount_amount'], 0, ',', '.') }}</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-primary bg-opacity-10">
                                        <div class="card-body p-3">
                                            <small class="text-muted d-block">Total Sesudah</small>
                                            <strong class="fs-5 text-primary">{{ number_format($preview['final']['total_amount'], 0, ',', '.') }}</strong>
                                            <br>
                                            <small class="text-muted">Pajak: {{ number_format($preview['final']['tax_amount'], 0, ',', '.') }}</small>
                                            <br>
                                            <small class="text-muted">Diskon: {{ number_format($preview['final']['discount_amount'], 0, ',', '.') }}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if($preview['payment_analysis']['is_overpaid'])
                                <div class="alert alert-danger">
                                    <strong>⚠️ Perhatian:</strong> Total yang sudah dibayar ({{ number_format($preview['payment_analysis']['active_paid_amount'], 0, ',', '.') }}) melebihi total akhir. Sistem tidak dapat menyelesaikan penerimaan ini. Hubungi petugas keuangan untuk menyelesaikan pembayaran terlebih dahulu.
                                </div>
                            @endif

                            <!-- Line-by-line details -->
                            @if(count($preview['retained']) > 0 || count($preview['removed']) > 0)
                                <h6 class="text-muted mt-4 mb-3">Detail Perubahan Lini</h6>

                                @if(count($preview['retained']) > 0)
                                    <div class="mb-3">
                                        <small class="text-success fw-bold">✓ Dipertahankan ({{ count($preview['retained']) }} item)</small>
                                        <div class="table-responsive mt-2">
                                            <table class="table table-sm table-borderless mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Produk</th>
                                                        <th class="text-end">Pesan</th>
                                                        <th class="text-end">Diterima</th>
                                                        <th class="text-end">Final</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($preview['retained'] as $line)
                                                        <tr>
                                                            <td>{{ $line['product_name'] }}</td>
                                                            <td class="text-end">{{ $line['original_quantity'] }}</td>
                                                            <td class="text-end">{{ $line['received_quantity'] }}</td>
                                                            <td class="text-end">{{ $line['retained_quantity'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif

                                @if(count($preview['removed']) > 0)
                                    <div class="mb-3">
                                        <small class="text-danger fw-bold">✗ Dihapus ({{ count($preview['removed']) }} item)</small>
                                        <div class="table-responsive mt-2">
                                            <table class="table table-sm table-borderless mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Produk</th>
                                                        <th class="text-end">Pesan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($preview['removed'] as $line)
                                                        <tr>
                                                            <td>{{ $line['product_name'] }}</td>
                                                            <td class="text-end">{{ $line['original_quantity'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </div>

                        @if(!$preview['payment_analysis']['is_overpaid'])
                            <!-- Reason field -->
                            <div class="mb-3">
                                <label for="reason" class="form-label">Alasan Kekurangan <span class="text-danger">*</span></label>
                                <textarea
                                    class="form-control @error('reason') is-invalid @enderror"
                                    id="reason"
                                    wire:model="reason"
                                    placeholder="Jelaskan alasan mengapa penerimaan tidak dapat diselesaikan dengan jumlah penuh"
                                    rows="3"
                                ></textarea>
                                @error('reason')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        @endif
                    @else
                        <div class="d-flex justify-content-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeModal">Batal</button>
                    @if($preview && !$preview['payment_analysis']['is_overpaid'])
                        <button type="button" class="btn btn-primary" wire:click="submit" wire:loading.attr="disabled" @disabled($isSubmitting)>
                            <span wire:loading.remove>Selesaikan Penerimaan</span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm" role="status"></span>
                                Memproses...
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
</div>
