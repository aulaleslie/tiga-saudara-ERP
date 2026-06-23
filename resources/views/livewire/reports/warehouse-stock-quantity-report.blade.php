<div x-data="{ showDrawer: false, isApplying: false }" x-init="$watch('showDrawer', value => { if(!value && !isApplying) { $wire.cancelFilters(); } isApplying = false; })">
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3 align-items-end">
        <div>
            <label class="form-label small">Per</label>
            <select wire:model.live="periodPreset" class="form-control">
                <option value="">-- Pilih Preset --</option>
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
                <label class="form-label small">Gudang</label>
                <div style="max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
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

        </div>
        <div class="offcanvas-footer p-3 border-top d-flex justify-content-between">
            <button type="button" wire:click="resetFilters" class="btn btn-link text-decoration-none px-0">Reset filter</button>
            <div>
                <button type="button" @click="showDrawer = false" class="btn btn-outline-secondary">Batalkan</button>
                <button type="button" wire:click="applyFilters" @click="isApplying = true; showDrawer = false" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </div>

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false"></div>

    <div class="table-responsive shadow-sm rounded" wire:loading.class="opacity-50">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 800px;">
            <thead class="table-light">
                <tr>
                    <th wire:click="sortBy('product_code')" style="cursor:pointer; white-space:nowrap">Kode produk / SKU {!! $this->sortIcon('product_code') !!}</th>
                    <th wire:click="sortBy('product_name')" style="cursor:pointer; white-space:nowrap">Nama produk {!! $this->sortIcon('product_name') !!}</th>
                    @php
                        $selectedWarehouseIds = empty($appliedFilters['warehouseIds']) ? array_column($availableWarehouses, 'id') : $appliedFilters['warehouseIds'];
                        $activeWarehouses = array_filter($availableWarehouses, function($w) use ($selectedWarehouseIds) {
                            return in_array($w['id'], $selectedWarehouseIds);
                        });
                    @endphp
                    @foreach($activeWarehouses as $warehouse)
                        <th class="text-end" style="white-space:nowrap">{{ $warehouse['name'] }}</th>
                    @endforeach
                    <th wire:click="sortBy('total_quantity')" class="text-end" style="cursor:pointer; white-space:nowrap">Total stok {!! $this->sortIcon('total_quantity') !!}</th>
                    <th style="white-space:nowrap">Unit</th>
                </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if(empty($availableWarehouses))
                <tr>
                    <td colspan="{{ 4 + count($activeWarehouses) }}" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Belum ada Gudang yang tersedia di sistem.
                    </td>
                </tr>
            @elseif($filterTriggered)
                @forelse($paginator as $row)
                    <tr>
                        <td>{{ $row->product_code ?: '-' }}</td>
                        <td>
                            @if(Route::has('products.show'))
                                <a href="{{ route('products.show', $row->product_id) }}" class="text-decoration-none">{{ $row->product_name }}</a>
                            @else
                                {{ $row->product_name }}
                            @endif
                        </td>
                        @foreach($activeWarehouses as $warehouse)
                            <td class="text-end">{{ number_format((float)($row->{"qty_loc_{$warehouse['id']}"} ?? 0), 2, ',', '.') }}</td>
                        @endforeach
                        <td class="text-end fw-bold text-primary">{{ number_format((float)$row->total_quantity, 2, ',', '.') }}</td>
                        <td>{{ $row->product_unit }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 4 + count($activeWarehouses) }}" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data persediaan yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
            @else
                <tr>
                    <td colspan="{{ 4 + count($activeWarehouses) }}" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Silakan atur filter dan klik <strong>Filter</strong> untuk menampilkan laporan.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if($filterTriggered && $paginator)
        <div class="mt-3">
            {{ $paginator->links() }}
        </div>
    @endif
</div>
