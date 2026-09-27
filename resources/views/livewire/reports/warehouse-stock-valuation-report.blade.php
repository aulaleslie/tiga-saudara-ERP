<div x-data="{ showDrawer: false, isApplying: false }" style="position: relative;">
    <div class="d-flex justify-content-between align-items-end mb-3 bg-white p-3 border shadow-sm rounded">
        <div>
            <h4 class="mb-1 text-dark fw-bold">Nilai stok gudang (dalam IDR)</h4>
            <div class="text-muted small">
                Menampilkan nilai persediaan barang per gudang dalam periode tertentu. 
                <strong>Catatan:</strong> Nilai persediaan menggunakan harga rata-rata produk.
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-3 align-items-end mb-3 bg-white p-3 border shadow-sm rounded">
        <div>
            <label class="form-label small">Periode</label>
            <select wire:model.live="periodPreset" class="form-select">
                <option value="">Pilih Periode</option>
                <option value="Hari ini">Hari Ini</option>
                <option value="Pekan ini">Pekan Ini</option>
                <option value="Bulan ini">Bulan Ini</option>
                <option value="Kuartal ini">Kuartal Ini</option>
                <option value="Tahun ini">Tahun Ini</option>
                <option value="Kemarin">Kemarin</option>
                <option value="Pekan lalu">Pekan Lalu</option>
                <option value="Bulan lalu">Bulan Lalu</option>
                <option value="Kuartal lalu">Kuartal Lalu</option>
                <option value="Tahun lalu">Tahun Lalu</option>
            </select>
        </div>
        <div>
            <label class="form-label small">Tanggal</label>
            <input type="date" wire:model.live="asOfDate" value="{{ $asOfDate }}" class="form-control">
        </div>
        <div class="ms-auto d-flex gap-2">
            <button wire:click="applyFilters" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="applyFilters" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i wire:loading.remove wire:target="applyFilters" class="bi bi-search"></i> Filter
            </button>
            <button type="button" @click="showDrawer = true" class="btn btn-outline-secondary">
                <i class="bi bi-funnel"></i> Filter lainnya
            </button>
            <div class="dropdown">
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu">
                    <li><button class="dropdown-item" wire:click="exportExcel">Excel</button></li>
                    <li><button class="dropdown-item" wire:click="exportCsv">CSV</button></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right-side Drawer for "Filter lainnya" -->
    <div class="offcanvas offcanvas-end" tabindex="-1" :class="showDrawer ? 'show' : ''"
         x-show="showDrawer" x-transition
         x-on:keydown.escape.window="showDrawer = false"
         style="visibility: visible; z-index: 1050; background: white; position: fixed; top: 0; right: 0; height: 100vh; width: 420px; box-shadow: -5px 0 15px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
         aria-labelledby="filterDrawerLabel" x-cloak>
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel">Filter laporan</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1;">

            <div class="mb-3">
                <label class="form-label small">Urutkan Nama Gudang</label>
                <select wire:model.live="warehouseNameOrder" class="form-select">
                    <option value="asc">A - Z</option>
                    <option value="desc">Z - A</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small">Gudang</label>
                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
                    @forelse($availableWarehouses as $warehouse)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="{{ $warehouse['id'] }}" wire:model="warehouseIds" id="wh_{{ $warehouse['id'] }}">
                            <label class="form-check-label" for="wh_{{ $warehouse['id'] }}">
                                {{ $warehouse['name'] }}
                            </label>
                        </div>
                    @empty
                        <div class="text-muted small">Belum ada Gudang yang tersedia di sistem.</div>
                    @endforelse
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small">Status Stok Produk</label>
                <select wire:model="productStockStatus" class="form-select">
                    <option value="">Semua produk</option>
                    <option value="available">Hanya produk dengan stok tersedia</option>
                    <option value="out_of_stock">Hanya produk yang habis</option>
                    <option value="below_minimum">Hanya produk di bawah batas minimum</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small">Mode Pencocokan Kategori</label>
                <select wire:model="categoryMatchMode" class="form-select">
                    <option value="any">Salah satu</option>
                    <option value="all">Mencakup semua</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small">Kategori</label>
                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
                    @forelse($availableCategories as $category)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="{{ $category['id'] }}" wire:model="categoryIds" id="cat_{{ $category['id'] }}">
                            <label class="form-check-label" for="cat_{{ $category['id'] }}">
                                {{ $category['category_name'] }}
                            </label>
                        </div>
                    @empty
                        <div class="text-muted small">Belum ada Kategori yang tersedia di sistem.</div>
                    @endforelse
                </div>
            </div>

        </div>
        <div class="offcanvas-footer p-3 border-top d-flex justify-content-between">
            <button type="button" wire:click="resetFilters" class="btn btn-link text-decoration-none px-0">Reset filter</button>
            <div>
                <button type="button" @click="showDrawer = false; $wire.cancelFilters()" class="btn btn-outline-secondary">Batalkan</button>
                <button type="button" wire:click="applyFilters" @click="isApplying = true; showDrawer = false" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </div>

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false; $wire.cancelFilters()"></div>

    <div class="table-responsive shadow-sm rounded" wire:loading.class="opacity-50">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 800px;">
            <thead class="table-light align-middle">
                <tr>
                    <th wire:click="sortBy('product_code')" style="cursor:pointer; white-space:nowrap">Kode produk / SKU {!! $this->sortIcon('product_code') !!}</th>
                    <th wire:click="sortBy('product_name')" style="cursor:pointer; white-space:nowrap">Nama produk {!! $this->sortIcon('product_name') !!}</th>
                    <th wire:click="sortBy('qty')" class="text-end" style="cursor:pointer; white-space:nowrap">Qty {!! $this->sortIcon('qty') !!}</th>
                    <th style="white-space:nowrap">Min. Qty</th>
                    <th style="white-space:nowrap">Satuan Produk</th>
                    <th wire:click="sortBy('average_cost')" class="text-end" style="cursor:pointer; white-space:nowrap">Harga Rata-rata {!! $this->sortIcon('average_cost') !!}</th>
                    <th wire:click="sortBy('stock_value')" class="text-end" style="cursor:pointer; white-space:nowrap">Nilai Persediaan {!! $this->sortIcon('stock_value') !!}</th>
                </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if(empty($availableWarehouses))
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Belum ada Gudang yang tersedia di sistem.
                    </td>
                </tr>
            @elseif($filterTriggered)
                @php $currentWarehouse = null; @endphp
                @forelse($paginator as $row)
                    @if($currentWarehouse !== $row->warehouse_id)
                        <tr class="table-secondary fw-bold">
                            <td colspan="7">{{ $row->warehouse_name }}</td>
                        </tr>
                        @php $currentWarehouse = $row->warehouse_id; @endphp
                    @endif
                    <tr>
                        <td>{{ $row->product_code ?: '-' }}</td>
                        <td>
                            @if(Route::has('products.show'))
                                <a href="{{ route('products.show', $row->product_id) }}" class="text-decoration-none">{{ $row->product_name }}</a>
                            @else
                                {{ $row->product_name }}
                            @endif
                        </td>
                        <td class="text-end fw-bold">{{ number_format((float)$row->qty, 2, ',', '.') }}</td>
                        <td>{{ number_format((float)$row->minimum_qty, 2, ',', '.') }}</td>
                        <td>{{ $row->product_unit }}</td>
                        <td class="text-end">{{ number_format((float)$row->average_cost, 2, ',', '.') }}</td>
                        <td class="text-end fw-bold text-primary">{{ number_format((float)$row->stock_value, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data persediaan yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
                @if($paginator->count() > 0)
                    <tr class="table-light fw-bold">
                        <td colspan="6" class="text-end">Total Nilai Persediaan Seluruh Produk:</td>
                        <td class="text-end text-primary">{{ number_format((float)$this->grandTotalValue, 2, ',', '.') }}</td>
                    </tr>
                @endif
            @else
                <tr>
                    <td colspan="{{ 7 + (count($displayWarehouses) * 2) }}" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Silakan atur filter dan klik <strong>Filter</strong> untuk menampilkan laporan.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if($filterTriggered && $paginator)
        <div class="mt-3 d-flex justify-content-between align-items-center">
            <div>
                Menampilkan {{ $paginator->firstItem() ?? 0 }} sampai {{ $paginator->lastItem() ?? 0 }} dari {{ $paginator->total() }} entri
            </div>
            <div>
                {{ $paginator->links() }}
            </div>
        </div>
    @endif
</div>
