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
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'replacement') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="settlement_replacement" value="replacement" wire:model.live="return_type" @disabled($isReadOnly)>
                            <label class="form-check-label ms-2" for="settlement_replacement">
                                <span class="d-block fw-semibold">Penggantian Produk</span>
                                <small class="text-muted">Barang diganti dengan produk baru setara.</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'credit') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="settlement_credit" value="credit" wire:model.live="return_type" @disabled($isReadOnly)>
                            <label class="form-check-label ms-2" for="settlement_credit">
                                <span class="d-block fw-semibold">Konversi ke Kredit</span>
                                <small class="text-muted">Nilai retur disimpan sebagai kredit pelanggan.</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'cash') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="settlement_cash" value="cash" wire:model.live="return_type" @disabled($isReadOnly)>
                            <label class="form-check-label ms-2" for="settlement_cash">
                                <span class="d-block fw-semibold">Pengembalian Tunai</span>
                                <small class="text-muted">Dana dikembalikan sesuai nilai retur.</small>
                            </label>
                        </div>
                    </div>
                </div>

                @error('return_type')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </div>

        @if($return_type === 'replacement')
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white border-0 d-flex align-items-center">
                    <h5 class="mb-0">Produk Pengganti</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" wire:click="addReplacementGood" @disabled($isReadOnly)>
                        <i class="bi bi-plus-circle"></i> Tambah Produk
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive border rounded">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th style="width: 35%">Produk</th>
                                    <th style="width: 15%">Jumlah</th>
                                    <th style="width: 20%">Nilai Satuan</th>
                                    <th style="width: 20%">Subtotal</th>
                                    <th style="width: 10%"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($replacement_goods as $index => $replacement)
                                    <tr>
                                        <td>
                                            @if($isReadOnly)
                                                <div class="fw-semibold">{{ $replacement['product_name'] }}</div>
                                                <small class="text-muted">{{ $replacement['product_code'] }}</small>
                                            @else
                                                <livewire:sales-return.replacement-product-search :index="$index" wire:key="replacement-{{ $index }}" />
                                                @error("replacement_goods.$index.product_id")
                                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                                @enderror
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <input type="number" min="0" class="form-control text-center" wire:model.lazy="replacement_goods.{{ $index }}.quantity" wire:change="recalculateReplacement({{ $index }})" @disabled($isReadOnly)>
                                            @error("replacement_goods.$index.quantity")
                                                <span class="invalid-feedback d-block">{{ $message }}</span>
                                            @enderror
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" class="form-control text-end" wire:model.lazy="replacement_goods.{{ $index }}.unit_value" wire:change="recalculateReplacement({{ $index }})" @disabled($isReadOnly)>
                                        </td>
                                        <td class="text-end align-middle">
                                            <span class="fw-semibold">Rp {{ number_format($replacement['sub_total'] ?? 0, 2, ',', '.') }}</span>
                                        </td>
                                        <td class="text-center align-middle">
                                            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeReplacementGood({{ $index }})" @disabled($isReadOnly)>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Belum ada produk pengganti.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @error('replacement_goods')
                        <div class="invalid-feedback d-block mt-2">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        @elseif($return_type === 'credit')
            <div class="alert alert-info d-flex align-items-center gap-2 mb-4" role="alert">
                <i class="bi bi-piggy-bank"></i>
                <span>Nilai kredit pelanggan yang akan dibuat: <strong>{{ format_currency($creditAmount) }}</strong>.</span>
            </div>
        @elseif($return_type === 'cash')
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
