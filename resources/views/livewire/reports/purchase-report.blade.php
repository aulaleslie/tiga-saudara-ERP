<div>
    <div class="row g-2 mb-3">
        <div class="col">
            <label class="form-label small">Tanggal Mulai</label>
            <input type="date" wire:model.defer="startDate" class="form-control">
        </div>
        <div class="col">
            <label class="form-label small">Tanggal Akhir</label>
            <input type="date" wire:model.defer="endDate" class="form-control">
        </div>
        <div class="col">
            <label class="form-label small">Pemasok</label>
            <select wire:model.defer="supplierId" class="form-control">
                <option value="">-- Semua Pemasok --</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->supplier_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <label class="form-label small">Pajak</label>
            <select wire:model.defer="withTax" class="form-control">
                <option value="">-- Semua --</option>
                <option value="1">Dengan Pajak</option>
                <option value="0">Tanpa Pajak</option>
            </select>
        </div>
        <div class="col">
            <label class="form-label small">Tag</label>
            <select wire:model.defer="selectedTag" class="form-control">
                <option value="">-- Semua Tag --</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">{{ json_decode($tag->name)->en ?? '' }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col">
            <label class="form-label small">Status</label>
            <select wire:model.defer="status" class="form-control">
                <option value="">-- Semua Status --</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <label class="form-label small">Status Pembayaran</label>
            <select wire:model.defer="paymentStatus" class="form-control">
                <option value="">-- Semua --</option>
                <option value="Paid">Lunas</option>
                <option value="Unpaid">Belum Dibayar</option>
                <option value="Partial">Sebagian</option>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button wire:click="applyFilters" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="applyFilters" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="applyFilters" class="bi bi-search"></i> Tampilkan Laporan
            </button>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button wire:click="exportExcel" wire:loading.attr="disabled" class="btn btn-success">
                <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="exportExcel" class="bi bi-file-earmark-excel"></i> Export Excel
            </button>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button wire:click="exportCsv" wire:loading.attr="disabled" class="btn btn-secondary">
                <span wire:loading wire:target="exportCsv" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="exportCsv" class="bi bi-filetype-csv"></i> Export CSV
            </button>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button wire:click="exportPdf" wire:loading.attr="disabled" class="btn btn-danger">
                <span wire:loading wire:target="exportPdf" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="exportPdf" class="bi bi-file-earmark-pdf"></i> Export PDF
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>No. Referensi</th>
                <th>Pemasok</th>
                <th>Status</th>
                <th>Status Pembayaran</th>
                <th class="text-end">Total</th>
                <th class="text-end">Pajak</th>
                <th class="text-end">Sisa Tagihan</th>
            </tr>
            </thead>
            <tbody>
            @if($filterTriggered)
                @forelse($purchases as $p)
                    <tr>
                        <td>{{ $p->date }}</td>
                        <td>{{ $p->reference }}</td>
                        <td>{{ $p->supplier->nickname ?? $p->supplier->supplier_name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $statuses[$p->status] ?? ucfirst(str_replace('_', ' ', strtolower($p->status))) }}
                            </span>
                        </td>
                        <td>
                            @if(strtolower($p->payment_status) === 'paid')
                                <span class="badge bg-success">Lunas</span>
                            @elseif(strtolower($p->payment_status) === 'partial')
                                <span class="badge bg-warning">Sebagian</span>
                            @else
                                <span class="badge bg-danger">Belum Dibayar</span>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format($p->total_amount, 2) }}</td>
                        <td class="text-end">{{ number_format($p->tax_amount, 2) }}</td>
                        <td class="text-end">{{ number_format($p->due_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center">Tidak ada data pembelian untuk periode ini.</td>
                    </tr>
                @endforelse
            @else
                <tr>
                    <td colspan="8" class="text-center">Klik "Tampilkan Laporan" untuk menampilkan data.</td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if($filterTriggered && $purchases instanceof \Illuminate\Pagination\LengthAwarePaginator)
        {{ $purchases->links() }}
    @endif
</div>
