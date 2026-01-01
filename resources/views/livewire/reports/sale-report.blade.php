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
            <label class="form-label small">Pelanggan</label>
            <select wire:model.defer="customerId" class="form-control">
                <option value="">-- Semua Pelanggan --</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->customer_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <label class="form-label small">Status</label>
            <select wire:model.defer="saleStatus" class="form-control">
                <option value="">-- Semua Status --</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col">
            <label class="form-label small">Status Pembayaran</label>
            <select wire:model.defer="paymentStatus" class="form-control">
                <option value="">-- Semua --</option>
                <option value="Paid">Lunas</option>
                <option value="Unpaid">Belum Dibayar</option>
                <option value="Partial">Sebagian</option>
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
        <div class="col-auto d-flex align-items-end">
            <button wire:click="applyFilters" class="btn btn-primary">
                <i class="bi bi-search"></i> Tampilkan Laporan
            </button>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button wire:click="exportExcel" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </button>
        </div>
        <div class="col-auto d-flex align-items-end">
            <button wire:click="exportCsv" class="btn btn-secondary">
                <i class="bi bi-filetype-csv"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>No. Referensi</th>
                <th>Pelanggan</th>
                <th>Status</th>
                <th>Status Pembayaran</th>
                <th class="text-end">Total</th>
                <th class="text-end">Dibayar</th>
                <th class="text-end">Sisa Tagihan</th>
            </tr>
            </thead>
            <tbody>
            @if($filterTriggered)
                @forelse($sales as $sale)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($sale->date)->format('d/m/Y') }}</td>
                        <td>{{ $sale->reference }}</td>
                        <td>{{ $sale->customer->customer_name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $statuses[$sale->status] ?? $sale->status }}
                            </span>
                        </td>
                        <td>
                            @if($sale->payment_status === 'Paid')
                                <span class="badge bg-success">Lunas</span>
                            @elseif($sale->payment_status === 'Partial')
                                <span class="badge bg-warning">Sebagian</span>
                            @else
                                <span class="badge bg-danger">Belum Dibayar</span>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format($sale->total_amount, 2) }}</td>
                        <td class="text-end">{{ number_format($sale->paid_amount, 2) }}</td>
                        <td class="text-end">{{ number_format($sale->due_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center">Tidak ada data penjualan untuk periode ini.</td>
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

    @if($filterTriggered && $sales instanceof \Illuminate\Pagination\LengthAwarePaginator)
        {{ $sales->links() }}
    @endif
</div>
