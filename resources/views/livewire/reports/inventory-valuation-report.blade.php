<div>
    <div class="row g-2 mb-3">
        <div class="col">
            <label class="form-label small">Tanggal Mulai</label>
            <input type="date" wire:model.defer="startDate" class="form-control">
            @error('startDate')
            <span class="text-danger mt-1">{{ $message }}</span>
            @enderror
        </div>
        <div class="col">
            <label class="form-label small">Tanggal Akhir</label>
            <input type="date" wire:model.defer="endDate" class="form-control">
            @error('endDate')
            <span class="text-danger mt-1">{{ $message }}</span>
            @enderror
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
    </div>

    <div class="row g-2 mb-3">
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

    <div class="alert alert-info mb-0">
        Gunakan tombol Export Excel atau CSV untuk mengunduh laporan Valuasi Stok sesuai format penilaian persediaan.
    </div>
</div>
