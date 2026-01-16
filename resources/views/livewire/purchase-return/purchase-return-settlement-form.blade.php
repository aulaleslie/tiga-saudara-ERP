@php use Illuminate\Support\Facades\Storage; @endphp
<div class="container-fluid py-3">
    <form wire:submit.prevent="submit" class="needs-validation" novalidate>
        @csrf

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex flex-wrap align-items-center">
                <div>
                    <h5 class="mb-1">Penyelesaian Retur #{{ $purchaseReturn->reference }}</h5>
                    <p class="text-muted small mb-0">Tetapkan metode penyelesaian setelah dokumen disetujui.</p>
                </div>
                <div class="ms-auto text-end">
                    <span class="badge bg-primary text-uppercase">{{ $purchaseReturn->approval_status }}</span>
                    <span class="badge bg-secondary text-uppercase">{{ $purchaseReturn->status }}</span>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted small mb-2">Pemasok</h6>
                            <p class="mb-1 fw-semibold">{{ optional($purchaseReturn->supplier)->supplier_name ?? '-' }}</p>
                            <p class="mb-0 text-muted">{{ optional($purchaseReturn->supplier)->supplier_email }}</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted small mb-2">Lokasi</h6>
                            <p class="mb-1 fw-semibold">{{ optional($purchaseReturn->location)->name ?? '-' }}</p>
                            <p class="mb-0 text-muted">{{ $purchaseReturn->date?->translatedFormat('d F Y') }}</p>
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
                <h5 class="mb-1">Penyelesaian Per Item</h5>
                <p class="text-muted small mb-0">Tentukan metode penyelesaian untuk setiap produk atau nomor seri.</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted text-uppercase small">
                            <tr class="text-center">
                                <th style="width: 40%" class="text-start">Produk / Nomor Seri</th>
                                <th style="width: 60%" class="text-start">Metode Penyelesaian</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($settlementLines as $index => $line)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $line['product_name'] }}</div>
                                        <div class="small text-muted">{{ $line['product_code'] }}</div>
                                        @if($line['serial_number'])
                                            <div class="mt-1 text-primary">
                                                <i class="bi bi-tag-fill me-1 small"></i>
                                                <span class="badge bg-light text-primary border border-primary">SN: {{ $line['serial_number'] }}</span>
                                            </div>
                                        @else
                                            <div class="mt-1 small text-muted">
                                                <i class="bi bi-box-seam me-1"></i>
                                                Jumlah: <strong>{{ $line['quantity'] }}</strong> unit
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($isReadOnly)
                                            <div class="p-2 border rounded bg-light border-dashed">
                                                @php
                                                    $methodLabel = $methods[$line['method']] ?? ($line['method'] ?: 'Belum ditentukan');
                                                @endphp
                                                <span class="fw-semibold text-dark">{{ $methodLabel }}</span>
                                            </div>
                                        @else
                                            <select class="form-select @error('settlementLines.'.$index.'.method') is-invalid @enderror" 
                                                wire:model.defer="settlementLines.{{ $index }}.method">
                                                <option value="">-- Pilih Metode --</option>
                                                @foreach($methods as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('settlementLines.'.$index.'.method')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-1">Bukti Pengembalian Tunai (Opsional)</h5>
                <p class="text-muted small mb-0">Unggah bukti jika ada penyelesaian berupa pengembalian dana.</p>
            </div>
            <div class="card-body">
                <input type="file" id="cash_proof" class="form-control mb-2" wire:model="cash_proof" accept=".jpg,.jpeg,.png,.pdf" @disabled($isReadOnly)>
                <small class="text-muted d-block mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Hanya diperlukan jika salah satu atau lebih item diselesaikan dengan <strong>Pengembalian Tunai</strong>.
                </small>
                @error('cash_proof')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror

                @if($purchaseReturn->settlement?->cash_proof_path)
                    <div class="mt-3 p-3 border rounded bg-light d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-file-earmark-check text-success fs-4"></i>
                            <div>
                                <small class="text-muted d-block">Bukti Saat Ini:</small>
                                <span class="fw-semibold">Tersedia di Server</span>
                            </div>
                        </div>
                        <a href="{{ Storage::url($purchaseReturn->settlement->cash_proof_path) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> Lihat Bukti
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <a href="{{ route('purchase-returns.show', $purchaseReturn->id) }}" class="btn btn-light border">Kembali</a>
            @unless($isReadOnly)
                <button type="submit" class="btn btn-primary ms-2" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan Penyelesaian</span>
                    <span wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                </button>
            @endunless
        </div>
    </form>
</div>
