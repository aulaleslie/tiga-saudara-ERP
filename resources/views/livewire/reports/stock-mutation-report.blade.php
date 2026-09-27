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
            <label class="form-label small">Produk</label>
            <select wire:model.defer="productId" class="form-control">
                <option value="">-- Semua Produk --</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}">{{ $product->product_code }} - {{ $product->product_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <label class="form-label small">Lokasi</label>
            <select wire:model.defer="locationId" class="form-control">
                <option value="">-- Semua Lokasi --</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col">
            <label class="form-label small">Tipe Mutasi</label>
            <select wire:model.defer="mutationType" class="form-control">
                <option value="">-- Semua --</option>
                <option value="IN">Masuk</option>
                <option value="OUT">Keluar</option>
            </select>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-auto">
            <button wire:click="applyFilters" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="applyFilters" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="applyFilters" class="bi bi-search"></i> Tampilkan Laporan
            </button>
        </div>
        <div class="col-auto">
            <button wire:click="exportExcel" wire:loading.attr="disabled" class="btn btn-success">
                <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="exportExcel" class="bi bi-file-earmark-excel"></i> Export Excel
            </button>
        </div>
        <div class="col-auto">
            <button wire:click="exportCsv" wire:loading.attr="disabled" class="btn btn-secondary">
                <span wire:loading wire:target="exportCsv" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="exportCsv" class="bi bi-filetype-csv"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>Kode Produk</th>
                <th>Nama Produk</th>
                <th>Lokasi</th>
                <th>Tipe</th>
                <th class="text-end">Qty Masuk</th>
                <th class="text-end">Qty Keluar</th>
                <th>Referensi</th>
            </tr>
            </thead>
            <tbody>
            @forelse($mutations as $mutation)
                <tr>
                    <td>{{ $mutation['date'] }}</td>
                    <td>{{ $mutation['product_code'] }}</td>
                    <td>{{ $mutation['product_name'] }}</td>
                    <td>{{ $mutation['location'] }}</td>
                    <td>
                        @if(str_contains($mutation['type'], 'Masuk') || str_contains($mutation['type'], 'Tambah') || str_contains($mutation['type'], 'Penerimaan'))
                            <span class="badge bg-success">{{ $mutation['type'] }}</span>
                        @else
                            <span class="badge bg-danger">{{ $mutation['type'] }}</span>
                        @endif
                    </td>
                    <td class="text-end text-success">{{ $mutation['qty_in'] > 0 ? number_format($mutation['qty_in']) : '-' }}</td>
                    <td class="text-end text-danger">{{ $mutation['qty_out'] > 0 ? number_format($mutation['qty_out']) : '-' }}</td>
                    <td>{{ $mutation['reference'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center">
                        @if($filterTriggered)
                            Tidak ada data mutasi stok untuk periode ini.
                        @else
                            Klik "Tampilkan Laporan" untuk menampilkan data.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
