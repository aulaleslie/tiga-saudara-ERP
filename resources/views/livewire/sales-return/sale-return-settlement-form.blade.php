@php use Illuminate\Support\Facades\Storage; @endphp
<div class="container-fluid py-3">
    <form wire:submit.prevent="submit" class="needs-validation" novalidate>
        @csrf

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex flex-wrap align-items-center">
                <div>
                    <h5 class="mb-1">Penyelesaian Retur Penjualan #{{ $saleReturn->reference }}</h5>
                    <p class="text-muted small mb-0">Tentukan metode penyelesaian setelah dokumen disetujui.</p>
                </div>
                <div class="ms-auto text-end">
                    <span class="badge bg-primary text-uppercase">{{ $saleReturn->approval_status }}</span>
                    <span class="badge bg-secondary text-uppercase">{{ $saleReturn->status }}</span>
                    @if($saleReturn->return_type)
                        <span class="badge bg-info text-uppercase">{{ $saleReturn->return_type }}</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted small mb-2">Pelanggan</h6>
                            <p class="mb-1 fw-semibold">{{ $saleReturn->customer_name ?? '-' }}</p>
                            <p class="mb-0 text-muted">{{ optional(optional($saleReturn->sale)->customer)->customer_email }}</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted small mb-2">Lokasi</h6>
                            <p class="mb-1 fw-semibold">{{ optional($saleReturn->location)->name ?? '-' }}</p>
                            <p class="mb-0 text-muted">{{ $saleReturn->date?->translatedFormat('d F Y') }}</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100 bg-light">
                            <h6 class="text-uppercase text-muted small mb-2">Total Retur</h6>
                            <p class="h5 mb-1 text-primary">{{ format_currency($total) }}</p>
                            <p class="mb-0 text-muted">Jumlah ini menjadi dasar perhitungan penyelesaian.</p>
                        </div>
                    </div>
                </div>

                @if($isReadOnly)
                    <div class="alert alert-success d-flex align-items-center gap-2 mt-3 mb-0" role="alert">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Metode penyelesaian sudah ditetapkan sebagai <strong>{{ $displayReturnType }}</strong>.</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-1">Pilih Metode Penyelesaian</h5>
                <p class="text-muted small mb-0">Tetapkan tindak lanjut untuk pelanggan.</p>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'cash_refund') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="settlement_cash_refund" value="cash_refund" wire:model.live="return_type" @disabled($isReadOnly)>
                            <label class="form-check-label ms-2" for="settlement_cash_refund">
                                <span class="d-block fw-semibold">Kembali Tunai</span>
                                <small class="text-muted">Dana dikembalikan ke pelanggan (butuh bukti).</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'repair') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="settlement_repair" value="repair" wire:model.live="return_type" @disabled($isReadOnly)>
                            <label class="form-check-label ms-2" for="settlement_repair">
                                <span class="d-block fw-semibold">Perbaikan</span>
                                <small class="text-muted">Barang akan diperbaiki (header-only).</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'unprocessed') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="settlement_unprocessed" value="unprocessed" wire:model.live="return_type" @disabled($isReadOnly)>
                            <label class="form-check-label ms-2" for="settlement_unprocessed">
                                <span class="d-block fw-semibold">Tidak Dapat Diproses</span>
                                <small class="text-muted">Retur ditolak atau tidak dapat ditindaklanjuti.</small>
                            </label>
                        </div>
                    </div>
                </div>

                @error('return_type')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </div>

        @if($return_type === 'cash_refund')
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-1">Bukti Pengembalian Tunai</h5>
                    <p class="text-muted small mb-0">Unggah dokumen pendukung seperti bukti transfer atau kuitansi.</p>
                </div>
                <div class="card-body">
                    <input type="file" id="cash_proof" class="form-control" wire:model="cash_proof" accept=".jpg,.jpeg,.png,.pdf" @disabled($isReadOnly)>
                    <small class="text-muted">Format yang diperbolehkan: JPG, PNG, atau PDF (maks. 4MB).</small>
                    @error('cash_proof')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror

                    @if($saleReturn->cash_proof_path)
                        <a href="{{ Storage::url($saleReturn->cash_proof_path) }}" target="_blank" class="btn btn-link mt-3">
                            <i class="bi bi-paperclip"></i> Lihat bukti saat ini
                        </a>
                    @endif
                </div>
            </div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-1">Detail Produk Retur</h5>
                <p class="text-muted small mb-0">Daftar barang yang dikembalikan oleh pelanggan.</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light text-muted text-uppercase small">
                            <tr>
                                <th>Produk</th>
                                <th class="text-center">Jumlah</th>
                                <th class="text-end">Harga Jual</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($details as $detail)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $detail->product_name }}</div>
                                        @if($detail->product_code)
                                            <span class="badge bg-light text-secondary border">{{ $detail->product_code }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-semibold">{{ $detail->quantity }}</span>
                                    </td>
                                    <td class="text-end">{{ format_currency($detail->unit_price) }}</td>
                                    <td class="text-end">{{ format_currency($detail->sub_total) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Tidak ada detail produk retur.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end text-muted">Total Retur</th>
                                <th class="text-end fw-semibold">{{ format_currency($total) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <a href="{{ route('sale-returns.show', $saleReturn->id) }}" class="btn btn-light border">Kembali</a>
            @unless($isReadOnly)
                <button type="submit" class="btn btn-primary ms-2" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan Penyelesaian</span>
                    <span wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                </button>
            @endunless
        </div>
    </form>
</div>
