<div>
    <div class="row g-2 mb-3">
        <div class="col"><input type="date" wire:model.defer="startDate" class="form-control"></div>
        <div class="col"><input type="date" wire:model.defer="endDate" class="form-control"></div>
        <div class="col">
            <select wire:model.defer="supplierId" class="form-control">
                <option value="">-- Semua Pemasok --</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->supplier_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <select wire:model.defer="withTax" class="form-control">
                <option value="">-- Pajak --</option>
                <option value="1">Dengan Pajak</option>
                <option value="0">Tanpa Pajak</option>
            </select>
        </div>
        <div class="col">
            <select wire:model.defer="selectedTag" class="form-control">
                <option value="">-- Semua Tag --</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">{{ json_decode($tag->name)->en ?? '' }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <button wire:click="applyFilters" class="btn btn-primary w-100">Tampilkan Laporan</button>
        </div>
        <div class="col">
            <button wire:click="exportExcel" class="btn btn-success w-100">Export ke Excel</button>
        </div>
        <div class="col">
            <button wire:click="exportPdf" class="btn btn-danger w-100">Export ke PDF</button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>Tipe Transaksi</th>
                <th>Nomor Transaksi</th>
                <th>Nama Panggilan</th>
                <th>Status Hari Ini</th>
                <th>Memo</th>
                <th>Total</th>
                <th>Sisa Tagihan</th>
            </tr>
            </thead>
            <tbody>
            @forelse($purchases as $p)
                <tr>
                    <td>{{ $p->date }}</td>
                    <td>Pembelian</td>
                    <td>{{ $p->reference }}</td>
                    <td>{{ $p->supplier->nickname ?? $p->supplier->supplier_name ?? '-' }}</td>
                    <td>
                            <span class="badge bg-secondary">
                                {{ ucfirst(str_replace('_', ' ', strtolower($p->status))) }}
                            </span>
                    </td>
                    <td>{{ $p->note ?? '-' }}</td>
                    <td>{{ number_format($p->total_amount, 2) }}</td>
                    <td>{{ number_format($p->due_amount, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center">Tidak ada data pembelian.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $purchases->links() }}
</div>
