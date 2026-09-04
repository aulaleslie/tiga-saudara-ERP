<div x-data="{ showDrawer: false }"
     x-on:clear-search-inputs.window="
        const target = $event.detail?.target || ($event.detail && $event.detail[0]?.target);
        if (target === 'all' || target === 'search') {
            if ($refs.searchInput) $refs.searchInput.value = '';
        }
        if (target === 'all' || target === 'category') {
            if ($refs.categorySearchInput) $refs.categorySearchInput.value = '';
        }
        if (target === 'all' || target === 'brand') {
            if ($refs.brandSearchInput) $refs.brandSearchInput.value = '';
        }
     ">
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            {{-- Filter Bar --}}
            <div class="row g-3 align-items-end mb-3">
                {{-- Search Box --}}
                <div class="col-md-3">
                    <label class="form-label small text-muted">Pencarian</label>
                    <div class="position-relative">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text"
                                   x-ref="searchInput"
                                   wire:model.live.debounce.400ms="search"
                                   class="form-control border-start-0 pe-4"
                                   placeholder="Cari nama, kode, barcode, atau serial...">
                            @if($search)
                                <button class="btn btn-outline-secondary border-start-0 border-end-0" type="button" wire:click="clearSearch">
                                    <i class="bi bi-x"></i>
                                </button>
                            @endif
                        </div>
                        <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="search">
                            <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                        </div>
                    </div>
                </div>

                {{-- Business Multi-Select (Reusing business-source-selector) --}}
                <div class="col-md-4">
                    @include('livewire.reports.business-source-selector', [
                        'label' => 'Pilih Bisnis / Cabang',
                        'availableSettings' => $availableSettings,
                        'selectedValues' => $selectedSettingIds,
                        'selectId' => 'cross-business-select',
                        'livewireProperty' => 'selectedSettingIds',
                        'placeholder' => 'Pilih bisnis...',
                    ])
                </div>

                {{-- Availability Filter --}}
                <div class="col-md-2">
                    <label class="form-label small text-muted">Ketersediaan Stok</label>
                    <select wire:model.live="availability" class="form-select form-control">
                        <option value="all">Semua Stok</option>
                        <option value="available">Tersedia (> 0)</option>
                        <option value="non_available">Kosong (<= 0)</option>
                    </select>
                </div>

                {{-- Actions: Filter Lainnya, Reset, Excel Export --}}
                <div class="col-md-3 d-flex gap-2 justify-content-end">
                    <button type="button" @click="showDrawer = true" class="btn btn-outline-secondary position-relative">
                        <i class="bi bi-funnel"></i> Filter Lainnya
                        @if(count($categoryIds) > 0 || count($brandIds) > 0)
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary">
                                {{ count($categoryIds) + count($brandIds) }}
                            </span>
                        @endif
                    </button>
                    <button type="button" wire:click="resetFilters" class="btn btn-outline-danger" title="Reset Filter">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" class="btn btn-outline-success">
                        <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm me-1" role="status"></span>
                        <i wire:loading.remove wire:target="exportExcel" class="bi bi-file-earmark-excel"></i> Ekspor Excel
                    </button>
                </div>
            </div>

            {{-- Selected Filter Badges --}}
            @if(count($categoryIds) > 0 || count($brandIds) > 0)
                <div class="d-flex flex-wrap gap-1 mb-3 align-items-center">
                    <span class="text-muted small me-1">Filter Aktif:</span>
                    @foreach($categoryIds as $id)
                        <span class="badge bg-info text-dark d-inline-flex align-items-center">
                            Kategori: {{ $categoryLabels[$id] ?? $id }}
                            <button type="button" class="btn-close ms-2" style="font-size: 0.5rem" wire:click="removeCategory({{ $id }})"></button>
                        </span>
                    @endforeach
                    @foreach($brandIds as $id)
                        <span class="badge bg-secondary d-inline-flex align-items-center">
                            Merek: {{ $brandLabels[$id] ?? $id }}
                            <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeBrand({{ $id }})"></button>
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- Table with Two-Tier Header, Sticky First Column, and Horizontal Scroll --}}
            <div class="table-responsive border rounded" style="max-height: 700px; overflow-x: auto; overflow-y: auto;" wire:loading.class="opacity-50">
                <table class="table table-hover table-bordered mb-0 align-middle text-nowrap" style="font-size: 0.875rem;">
                    <thead class="table-light sticky-top" style="z-index: 20;">
                        {{-- Tier 1 Header --}}
                        <tr>
                            <th rowspan="2" class="sticky-col bg-light align-middle text-start" style="left: 0; z-index: 25; min-width: 250px;">
                                Produk / Barang
                            </th>
                            <th rowspan="2" class="align-middle text-center" style="min-width: 120px;">Kategori</th>
                            <th rowspan="2" class="align-middle text-center" style="min-width: 120px;">Merek</th>

                            @forelse($businesses as $b)
                                @php
                                    $isExpanded = $expandedBusinesses[$b['setting_id']] ?? false;
                                    $locCount = count($b['locations']);
                                    $colspan = $isExpanded ? max(1, $locCount) * 2 : 2;
                                @endphp
                                <th colspan="{{ $colspan }}" class="text-center border-start border-end business-header-th">
                                    <div class="d-flex align-items-center justify-content-center gap-2 py-1">
                                        <span class="fw-bold">{{ $b['company_name'] }}</span>
                                        @if($b['is_pkp'])
                                            <span class="badge bg-primary-subtle text-primary border border-primary px-1" style="font-size: 0.65rem;">PKP</span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary border px-1" style="font-size: 0.65rem;">Non-PKP</span>
                                        @endif
                                        @if($locCount > 1)
                                            <button type="button"
                                                    wire:click="toggleBusinessExpand({{ $b['setting_id'] }})"
                                                    class="btn btn-sm py-0 px-1 {{ $isExpanded ? 'btn-outline-primary' : 'btn-light border' }}"
                                                    title="{{ $isExpanded ? 'Tutup rincian lokasi' : 'Buka rincian per lokasi' }}">
                                                <i class="bi {{ $isExpanded ? 'bi-chevron-double-left' : 'bi-chevron-double-right' }}" style="font-size: 0.75rem;"></i>
                                                <span style="font-size: 0.7rem;">{{ $isExpanded ? 'Ringkas' : 'Rincian (' . $locCount . ' Lokasi)' }}</span>
                                            </button>
                                        @endif
                                    </div>
                                </th>
                            @empty
                                <th colspan="2" class="text-center text-muted">Pilih minimal satu bisnis</th>
                            @endforelse
                        </tr>

                        {{-- Tier 2 Header --}}
                        <tr>
                            @foreach($businesses as $b)
                                @php
                                    $isExpanded = $expandedBusinesses[$b['setting_id']] ?? false;
                                    $locs = $b['locations'];
                                @endphp
                                @if(!$isExpanded || empty($locs))
                                    <th class="text-center border-start" style="min-width: 90px;">Bagus</th>
                                    <th class="text-center border-end" style="min-width: 90px;">Rusak</th>
                                @else
                                    @foreach($locs as $loc)
                                        <th class="text-center border-start" style="min-width: 90px;" title="{{ $loc['name'] }}">
                                            <div class="small text-truncate" style="max-width: 90px;">{{ $loc['name'] }}</div>
                                            <span class="text-success small">Bagus</span>
                                        </th>
                                        <th class="text-center border-end" style="min-width: 90px;" title="{{ $loc['name'] }}">
                                            <div class="small text-truncate" style="max-width: 90px;">{{ $loc['name'] }}</div>
                                            <span class="text-danger small">Rusak</span>
                                        </th>
                                    @endforeach
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $product)
                            <tr>
                                {{-- Sticky First Column --}}
                                <td class="sticky-col bg-white text-start" style="left: 0; z-index: 10;">
                                    <div class="fw-semibold text-dark">{{ $product['product_name'] }}</div>
                                    <div class="d-flex align-items-center gap-2 small text-muted">
                                        <span>Kode: <code>{{ $product['product_code'] }}</code></span>
                                        @if($product['barcode'])
                                            <span>| Barcode: <code>{{ $product['barcode'] }}</code></span>
                                        @endif
                                        @if($product['serial_number_required'])
                                            <span class="badge bg-info-subtle text-info border border-info" style="font-size: 0.65rem;">Serial</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-center text-muted">{{ $product['category_name'] }}</td>
                                <td class="text-center text-muted">{{ $product['brand_name'] }}</td>

                                {{-- Stock Columns --}}
                                @foreach($businesses as $b)
                                    @php
                                        $settingId = $b['setting_id'];
                                        $isExpanded = $expandedBusinesses[$settingId] ?? false;
                                        $bStock = $product['businesses'][$settingId] ?? null;
                                        $locs = $b['locations'];
                                    @endphp

                                    @if(!$isExpanded || empty($locs))
                                        {{-- Collapsed Good Cell --}}
                                        <td class="text-center border-start">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <span class="{{ ($bStock['good'] ?? 0) > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                                    {{ (float) ($bStock['good'] ?? 0) }}
                                                </span>
                                                @if(!empty($bStock['good_tooltip']))
                                                    <i class="bi bi-info-circle text-warning mismatch-tooltip-icon"
                                                       data-bs-toggle="tooltip"
                                                       data-bs-placement="top"
                                                       title="{{ $bStock['good_tooltip'] }}"
                                                       style="cursor: help; font-size: 0.85rem;"></i>
                                                @endif
                                                @if($product['serial_number_required'] && ($bStock['good'] ?? 0) > 0)
                                                    <button type="button"
                                                            wire:click="openSerialDialog({{ $product['id'] }}, '{{ addslashes($product['product_name']) }}', {{ $settingId }}, '{{ addslashes($b['company_name']) }}', null, 'Semua Lokasi', 'good')"
                                                            class="btn btn-link btn-sm p-0 ms-1 text-primary serial-dialog-btn"
                                                            title="Lihat nomor serial siap jual">
                                                        <i class="bi bi-upc-scan"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>

                                        {{-- Collapsed Bad Cell --}}
                                        <td class="text-center border-end">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <span class="{{ ($bStock['bad'] ?? 0) > 0 ? 'fw-bold text-danger' : 'text-muted' }}">
                                                    {{ (float) ($bStock['bad'] ?? 0) }}
                                                </span>
                                                @if(!empty($bStock['bad_tooltip']))
                                                    <i class="bi bi-info-circle text-warning mismatch-tooltip-icon"
                                                       data-bs-toggle="tooltip"
                                                       data-bs-placement="top"
                                                       title="{{ $bStock['bad_tooltip'] }}"
                                                       style="cursor: help; font-size: 0.85rem;"></i>
                                                @endif
                                                @if($product['serial_number_required'] && ($bStock['bad'] ?? 0) > 0)
                                                    <button type="button"
                                                            wire:click="openSerialDialog({{ $product['id'] }}, '{{ addslashes($product['product_name']) }}', {{ $settingId }}, '{{ addslashes($b['company_name']) }}', null, 'Semua Lokasi', 'bad')"
                                                            class="btn btn-link btn-sm p-0 ms-1 text-danger serial-dialog-btn"
                                                            title="Lihat nomor serial rusak">
                                                        <i class="bi bi-upc-scan"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    @else
                                        {{-- Expanded Per-Location Cells --}}
                                        @foreach($locs as $loc)
                                            @php
                                                $locId = $loc['id'];
                                                $lData = $bStock['locations'][$locId] ?? null;
                                            @endphp
                                            {{-- Good Cell --}}
                                            <td class="text-center border-start">
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <span class="{{ ($lData['good'] ?? 0) > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                                        {{ (float) ($lData['good'] ?? 0) }}
                                                    </span>
                                                    @if(!empty($lData['good_tooltip']))
                                                        <i class="bi bi-info-circle text-warning mismatch-tooltip-icon"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-placement="top"
                                                           title="{{ $lData['good_tooltip'] }}"
                                                           style="cursor: help; font-size: 0.85rem;"></i>
                                                    @endif
                                                    @if($product['serial_number_required'] && ($lData['good'] ?? 0) > 0)
                                                        <button type="button"
                                                                wire:click="openSerialDialog({{ $product['id'] }}, '{{ addslashes($product['product_name']) }}', {{ $settingId }}, '{{ addslashes($b['company_name']) }}', {{ $locId }}, '{{ addslashes($loc['name']) }}', 'good')"
                                                                class="btn btn-link btn-sm p-0 ms-1 text-primary serial-dialog-btn"
                                                                title="Lihat nomor serial siap jual ({{ $loc['name'] }})">
                                                            <i class="bi bi-upc-scan"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>

                                            {{-- Bad Cell --}}
                                            <td class="text-center border-end">
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <span class="{{ ($lData['bad'] ?? 0) > 0 ? 'fw-bold text-danger' : 'text-muted' }}">
                                                        {{ (float) ($lData['bad'] ?? 0) }}
                                                    </span>
                                                    @if(!empty($lData['bad_tooltip']))
                                                        <i class="bi bi-info-circle text-warning mismatch-tooltip-icon"
                                                           data-bs-toggle="tooltip"
                                                           data-bs-placement="top"
                                                           title="{{ $lData['bad_tooltip'] }}"
                                                           style="cursor: help; font-size: 0.85rem;"></i>
                                                    @endif
                                                    @if($product['serial_number_required'] && ($lData['bad'] ?? 0) > 0)
                                                        <button type="button"
                                                                wire:click="openSerialDialog({{ $product['id'] }}, '{{ addslashes($product['product_name']) }}', {{ $settingId }}, '{{ addslashes($b['company_name']) }}', {{ $locId }}, '{{ addslashes($loc['name']) }}', 'bad')"
                                                                class="btn btn-link btn-sm p-0 ms-1 text-danger serial-dialog-btn"
                                                                title="Lihat nomor serial rusak ({{ $loc['name'] }})">
                                                            <i class="bi bi-upc-scan"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                        @endforeach
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 3 + max(1, count($businesses) * 2) }}" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                    Tidak ada produk yang cocok dengan kriteria pencarian dan filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($paginator->hasPages())
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="small text-muted">
                        Menampilkan {{ $paginator->firstItem() ?? 0 }} - {{ $paginator->lastItem() ?? 0 }} dari {{ $paginator->total() }} produk
                    </div>
                    <div>
                        {{ $paginator->links() }}
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Filter Lainnya Drawer (Category & Brand) --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" :class="showDrawer ? 'show' : ''"
         x-show="showDrawer" x-transition
         x-on:keydown.escape.window="showDrawer = false"
         style="visibility: visible; z-index: 1050; background: white; position: fixed; top: 0; right: 0; height: 100vh; width: 380px; box-shadow: -5px 0 15px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
         aria-labelledby="filterDrawerLabel" x-cloak>
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel"><i class="bi bi-funnel me-1"></i> Filter Kategori & Merek</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3 pb-5" style="overflow-y: auto; flex-grow: 1;">
            {{-- Category Search --}}
            <div class="mb-3">
                <label class="form-label small fw-bold">Kategori Produk</label>
                <div class="position-relative">
                    <input type="text"
                           x-ref="categorySearchInput"
                           wire:model.live.debounce.300ms="categorySearch"
                           class="form-control"
                           placeholder="Cari kategori (min 2 karakter)...">

                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="categorySearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>

                    @if(strlen($categorySearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($categoryOptions as $cat)
                                @if(in_array($cat['id'], $categoryIds))
                                    <button type="button" class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $cat['category_name'] }} <span class="badge bg-secondary ms-1">Dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click="selectCategory({{ $cat['id'] }}, '{{ addslashes($cat['category_name']) }}')"
                                            class="list-group-item list-group-item-action small py-2">
                                        {{ $cat['category_name'] }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item small py-2 text-center text-muted">Kategori tidak ditemukan</div>
                            @endforelse
                        </div>
                    @endif

                    @if(count($categoryIds) > 0)
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($categoryIds as $id)
                                <span class="badge bg-primary d-inline-flex align-items-center px-2 py-1">
                                    {{ $categoryLabels[$id] ?? $id }}
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeCategory({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <hr>

            {{-- Brand Search --}}
            <div class="mb-3">
                <label class="form-label small fw-bold">Merek Produk</label>
                <div class="position-relative">
                    <input type="text"
                           x-ref="brandSearchInput"
                           wire:model.live.debounce.300ms="brandSearch"
                           class="form-control"
                           placeholder="Cari merek (min 2 karakter)...">

                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="brandSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>

                    @if(strlen($brandSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($brandOptions as $bOption)
                                @if(in_array($bOption['id'], $brandIds))
                                    <button type="button" class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $bOption['name'] }} <span class="badge bg-secondary ms-1">Dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click="selectBrand({{ $bOption['id'] }}, '{{ addslashes($bOption['name']) }}')"
                                            class="list-group-item list-group-item-action small py-2">
                                        {{ $bOption['name'] }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item small py-2 text-center text-muted">Merek tidak ditemukan</div>
                            @endforelse
                        </div>
                    @endif

                    @if(count($brandIds) > 0)
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($brandIds as $id)
                                <span class="badge bg-secondary d-inline-flex align-items-center px-2 py-1">
                                    {{ $brandLabels[$id] ?? $id }}
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeBrand({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div style="height: 150px;"></div>
        </div>
        <div class="offcanvas-footer p-3 border-top d-flex justify-content-end">
            <button type="button" class="btn btn-primary btn-sm px-4" @click="showDrawer = false">Selesai</button>
        </div>
    </div>

    {{-- Serial Number Modal Dialog --}}
    @if($showSerialDialog)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5); z-index: 1060;" x-cloak>
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content shadow-lg border-0">
                    <div class="modal-header bg-light">
                        <div>
                            <h5 class="modal-title fs-6 mb-0">
                                <i class="bi bi-upc-scan me-1"></i> Rincian Nomor Serial:
                                <span class="{{ $dialogCondition === 'good' ? 'text-success' : 'text-danger' }}">
                                    {{ $dialogCondition === 'good' ? 'Kondisi Bagus (Siap Jual)' : 'Kondisi Rusak' }}
                                </span>
                            </h5>
                            <div class="small text-muted mt-1">
                                <strong>{{ $dialogProductName }}</strong> &bull; {{ $dialogBusinessName }} ({{ $dialogLocationName }})
                            </div>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeSerialDialog"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3 position-relative">
                            <input type="text"
                                   wire:model.live.debounce.300ms="dialogSearch"
                                   class="form-control form-control-sm"
                                   placeholder="Cari serial number...">
                            <div class="position-absolute end-0 top-0 mt-1 me-2" wire:loading wire:target="dialogSearch">
                                <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                            </div>
                        </div>

                        @if($dialogSerials && $dialogSerials->count() > 0)
                            <div class="table-responsive border rounded mb-3" style="max-height: 350px;">
                                <table class="table table-sm table-hover mb-0 align-middle">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th class="ps-3">No.</th>
                                            <th>Nomor Serial</th>
                                            <th>Lokasi Gudang</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dialogSerials as $index => $serial)
                                            <tr>
                                                <td class="ps-3 text-muted small">{{ $dialogSerials->firstItem() + $index }}</td>
                                                <td><code>{{ $serial->serial_number }}</code></td>
                                                <td class="small">{{ optional($serial->location)->name ?? '-' }}</td>
                                                <td>
                                                    @if($serial->is_broken)
                                                        <span class="badge bg-danger">Rusak</span>
                                                    @else
                                                        <span class="badge bg-success">Siap Jual</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if($dialogSerials->hasPages())
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="small text-muted">Total: {{ $dialogSerials->total() }} serial</span>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                wire:click="setDialogPage({{ $dialogPage - 1 }})"
                                                @if($dialogPage <= 1) disabled @endif>
                                            &laquo; Sebelumnya
                                        </button>
                                        <span class="mx-2 small">Hal. {{ $dialogPage }} / {{ $dialogSerials->lastPage() }}</span>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                wire:click="setDialogPage({{ $dialogPage + 1 }})"
                                                @if($dialogPage >= $dialogSerials->lastPage()) disabled @endif>
                                            Berikutnya &raquo;
                                        </button>
                                    </div>
                                </div>
                            @endif
                        @else
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-search fs-3 d-block mb-1"></i>
                                Tidak ada serial number yang cocok dengan kriteria.
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer bg-light p-2">
                        <button type="button" class="btn btn-secondary btn-sm px-4" wire:click="closeSerialDialog">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('page_css')
<style>
    .sticky-col {
        position: sticky;
        left: 0;
        background-color: #ffffff;
        box-shadow: 2px 0 5px rgba(0,0,0,0.05);
    }
    thead .sticky-col {
        background-color: #f8f9fa !important;
        z-index: 25;
    }
    .business-header-th {
        background-color: #f1f4f6;
    }
</style>
@endpush

@push('page_scripts')
<script>
    document.addEventListener('livewire:initialized', () => {
        const initTooltips = () => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        };
        initTooltips();
        Livewire.hook('morph.updated', () => {
            initTooltips();
        });
    });
</script>
@endpush
