<div>
    {{-- Header Section --}}
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h4 class="mb-1 text-dark fw-bold">Pantauan Stok</h4>
                    <p class="text-muted mb-0 small">
                        Pantauan stok global lintas bisnis, perhatian batas minimum, dan pergerakan penjualan terkini.
                    </p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" wire:click="resetFilters" class="btn btn-outline-danger btn-sm" title="Reset Semua Filter">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset Filter
                    </button>
                </div>
            </div>

            @if($feedbackMessage)
                <div class="alert alert-success alert-dismissible fade show mt-3 mb-0" role="alert">
                    <i class="bi bi-check-circle me-1"></i> {{ $feedbackMessage }}
                    <button type="button" class="btn-close" wire:click="$set('feedbackMessage', '')" aria-label="Close"></button>
                </div>
            @endif
        </div>
    </div>

    {{-- Attention / Summary Cards --}}
    <div class="row g-3 mb-4">
        {{-- Stok Habis --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 {{ in_array('Stok Habis', $statuses) ? 'border-danger border-2' : '' }}"
                 style="cursor: pointer;"
                 wire:click="toggleStatus('Stok Habis')">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Stok Habis</div>
                        <div class="fs-4 fw-bold text-danger">{{ number_format($attentionCounts['out_of_stock'] ?? 0) }}</div>
                        <div class="small text-muted">Stok Good &le; 0</div>
                    </div>
                    <div class="rounded-circle bg-danger-subtle p-3 text-danger">
                        <i class="bi bi-x-circle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        {{-- Perlu Dibeli Lagi --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 {{ in_array('Perlu Dibeli Lagi', $statuses) ? 'border-warning border-2' : '' }}"
                 style="cursor: pointer;"
                 wire:click="toggleStatus('Perlu Dibeli Lagi')">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Perlu Dibeli Lagi</div>
                        <div class="fs-4 fw-bold text-warning">{{ number_format($attentionCounts['reorder_required'] ?? 0) }}</div>
                        <div class="small text-muted">Stok Good &le; Batas Minimum</div>
                    </div>
                    <div class="rounded-circle bg-warning-subtle p-3 text-warning">
                        <i class="bi bi-exclamation-triangle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        {{-- Batas Minimum Belum Diatur --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 {{ in_array('Batas Minimum Belum Diatur', $statuses) ? 'border-secondary border-2' : '' }}"
                 style="cursor: pointer;"
                 wire:click="toggleStatus('Batas Minimum Belum Diatur')">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Batas Minimum Belum Diatur</div>
                        <div class="fs-4 fw-bold text-secondary">{{ number_format($attentionCounts['minimum_unset'] ?? 0) }}</div>
                        <div class="small text-muted">Batas Minimum = 0</div>
                    </div>
                    <div class="rounded-circle bg-secondary-subtle p-3 text-secondary">
                        <i class="bi bi-gear fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        {{-- Lama Tidak Terjual --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 {{ in_array('Lama Tidak Terjual', $statuses) ? 'border-info border-2' : '' }}"
                 style="cursor: pointer;"
                 wire:click="toggleStatus('Lama Tidak Terjual')">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Lama Tidak Terjual</div>
                        <div class="fs-4 fw-bold text-info">{{ number_format($attentionCounts['slow_moving'] ?? 0) }}</div>
                        <div class="small text-muted">&ge; 90 hari tanpa penjualan</div>
                    </div>
                    <div class="rounded-circle bg-info-subtle p-3 text-info">
                        <i class="bi bi-clock-history fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters and Search Bar --}}
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="row g-3">
                {{-- Search --}}
                <div class="col-md-3">
                    <label class="form-label small text-muted">Pencarian Produk</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text"
                               wire:model.live.debounce.300ms="search"
                               class="form-control border-start-0"
                               placeholder="Cari nama, kode, atau barcode...">
                    </div>
                </div>

                {{-- Category Filter --}}
                <div class="col-md-2">
                    <label class="form-label small text-muted">Kategori</label>
                    <div wire:ignore class="stock-insights-select2-container">
                        <select id="stock-insights-category-select" multiple class="form-control" style="width: 100%;">
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @if(in_array((string)$category->id, array_map('strval', $categoryIds))) selected @endif>
                                    {{ $category->category_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Brand Filter --}}
                <div class="col-md-2">
                    <label class="form-label small text-muted">Merek</label>
                    <div wire:ignore class="stock-insights-select2-container">
                        <select id="stock-insights-brand-select" multiple class="form-control" style="width: 100%;">
                            @foreach($brands as $brand)
                                <option value="{{ $brand->id }}" @if(in_array((string)$brand->id, array_map('strval', $brandIds))) selected @endif>
                                    {{ $brand->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Period Presets --}}
                <div class="col-md-2">
                    <label class="form-label small text-muted">Periode Penjualan</label>
                    <div wire:ignore class="stock-insights-select2-container">
                        <select id="stock-insights-preset-select" class="form-control" style="width: 100%;">
                            <option value="7" @if($preset === '7') selected @endif>7 Hari Terakhir</option>
                            <option value="30" @if($preset === '30') selected @endif>30 Hari Terakhir</option>
                            <option value="90" @if($preset === '90') selected @endif>90 Hari Terakhir</option>
                            <option value="custom" disabled @if($preset === 'custom') selected @endif>{{ $preset === 'custom' ? $periodLabel : 'Kustom' }}</option>
                        </select>
                    </div>
                </div>

                {{-- Start Date Picker --}}
                <div class="col-md-3">
                    <label class="form-label small text-muted">Tanggal Mulai (sampai Hari Ini)</label>
                    <input type="date"
                           wire:model.live="startDate"
                           max="{{ \Carbon\Carbon::today()->format('Y-m-d') }}"
                           class="form-control @error('startDate') is-invalid @enderror">
                    @error('startDate')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Main Stock Table --}}
    <div class="card border-0 shadow-sm position-relative">
        {{-- Loading Overlay --}}
        <div wire:loading.flex class="position-absolute top-0 start-0 w-100 h-100 bg-white bg-opacity-75 align-items-center justify-content-center" style="z-index: 20;">
            <div class="text-center">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="small text-muted mt-2">Memuat data pantauan stok...</div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 700px; overflow-x: auto;">
                <table class="table table-hover table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="table-light sticky-top" style="z-index: 15;">
                        {{-- Top-Tier Header --}}
                        <tr>
                            <th rowspan="2" class="sticky-col bg-light text-start align-middle" style="left: 0; min-width: 260px; z-index: 16;">
                                <div class="d-flex align-items-center justify-content-between">
                                    <span>Produk</span>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-muted" wire:click="sortBy('product_name')">
                                        <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                            <th rowspan="2" class="text-center align-middle" style="min-width: 140px;">Batas Minimum & Status</th>

                            {{-- Authoritative Global Good Stock --}}
                            <th rowspan="2" class="text-center align-middle bg-primary-subtle text-primary border-start border-end" style="min-width: 130px;">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <button type="button"
                                            class="btn btn-sm btn-link p-0 text-primary fw-bold text-decoration-none"
                                            wire:click="sortBy('global_stock')"
                                            title="Urutkan berdasarkan Stok Global">
                                        Stok Global
                                        <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                    <button type="button"
                                            class="btn btn-xs btn-outline-primary ms-1 py-0 px-1"
                                            wire:click="toggleGlobalExpansion"
                                            title="{{ $isGlobalExpanded ? 'Tutup rincian per bisnis' : 'Buka rincian per bisnis' }}">
                                        <i class="bi {{ $isGlobalExpanded ? 'bi-chevron-left' : 'bi-chevron-right' }}"></i>
                                    </button>
                                </div>
                            </th>

                            {{-- Dynamic Business / Location Columns --}}
                            @if($isGlobalExpanded)
                                @foreach($hierarchy as $business)
                                    @php
                                        $settingId = (int) $business['setting_id'];
                                        $isExpanded = !empty($expandedBusinesses[$settingId]) || !empty($expandedBusinesses[(string) $settingId]);
                                        $locations = $business['locations'];
                                        $locCount = count($locations);
                                        $colSpan = ($isExpanded && $locCount > 0) ? $locCount : 1;
                                    @endphp
                                    <th colspan="{{ $colSpan }}" class="text-center align-middle border-start border-end bg-light">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <span class="text-truncate fw-semibold" style="max-width: 160px;" title="{{ $business['company_name'] }}">
                                                {{ $business['company_name'] }}
                                            </span>
                                            @if($locCount > 0)
                                                <button type="button"
                                                        class="btn btn-xs btn-outline-secondary py-0 px-1"
                                                        wire:click="toggleBusinessExpansion({{ $settingId }})"
                                                        title="{{ $isExpanded ? 'Tutup rincian lokasi' : 'Buka rincian lokasi' }}">
                                                    <i class="bi {{ $isExpanded ? 'bi-dash' : 'bi-plus' }}"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </th>
                                @endforeach
                            @endif

                            {{-- Sales & Financial Columns (Sortable) --}}
                            <th rowspan="2" class="text-center align-middle border-start" style="min-width: 110px;">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-link p-0 text-dark fw-semibold text-decoration-none" wire:click="sortBy('sold_quantity')">
                                        Kuantitas Terjual <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                            <th rowspan="2" class="text-center align-middle" style="min-width: 130px;">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-link p-0 text-dark fw-semibold text-decoration-none" wire:click="sortBy('sales_value')">
                                        Nilai Penjualan <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                            <th rowspan="2" class="text-center align-middle" style="min-width: 130px;">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-link p-0 text-dark fw-semibold text-decoration-none" wire:click="sortBy('sold_cost')">
                                        Modal Terjual <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                            <th rowspan="2" class="text-center align-middle" style="min-width: 130px;">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-link p-0 text-dark fw-semibold text-decoration-none" wire:click="sortBy('gross_profit')">
                                        Laba Kotor <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                            <th rowspan="2" class="text-center align-middle border-end" style="min-width: 110px;">
                                <div class="d-flex align-items-center justify-content-center gap-1">
                                    <button type="button" class="btn btn-sm btn-link p-0 text-dark fw-semibold text-decoration-none" wire:click="sortBy('last_sale_date')">
                                        Penjualan Terakhir <i class="bi bi-arrow-down-up"></i>
                                    </button>
                                </div>
                            </th>
                        </tr>

                        {{-- Second-Tier Header (Sub-columns for business/locations) --}}
                        @if($isGlobalExpanded)
                            <tr>
                                @foreach($hierarchy as $business)
                                    @php
                                        $settingId = (int) $business['setting_id'];
                                        $isExpanded = !empty($expandedBusinesses[$settingId]) || !empty($expandedBusinesses[(string) $settingId]);
                                        $locations = $business['locations'];
                                    @endphp
                                    @if(!$isExpanded || empty($locations))
                                        <th class="text-center small border-start border-end text-muted" style="min-width: 90px;">Subtotal Good</th>
                                    @else
                                        @foreach($locations as $loc)
                                            <th class="text-center small border-start border-end text-muted text-truncate" style="min-width: 90px;" title="{{ $loc['name'] }}">
                                                {{ $loc['name'] }}
                                            </th>
                                        @endforeach
                                    @endif
                                @endforeach
                            </tr>
                        @endif
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                {{-- Product Identity Column (Sticky) --}}
                                <td class="sticky-col bg-white text-start" style="left: 0; z-index: 10;">
                                    <div class="d-flex align-items-start justify-content-between gap-1">
                                        <div>
                                            <div class="fw-bold text-dark">{{ $row->productName }}</div>
                                            <div class="small text-muted">
                                                <span>Kode: <code>{{ $row->productCode }}</code></span>
                                                @if($row->barcode)
                                                    <span class="ms-1">| Barcode: <code>{{ $row->barcode }}</code></span>
                                                @endif
                                            </div>
                                            @if($row->categoryName || $row->brandName)
                                                <div class="small text-secondary mt-1">
                                                    @if($row->categoryName)<span class="badge bg-light text-dark border me-1">{{ $row->categoryName }}</span>@endif
                                                    @if($row->brandName)<span class="badge bg-light text-dark border">{{ $row->brandName }}</span>@endif
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Inline Minimum Stock Modal Trigger --}}
                                        @php
                                            $modalPrefillPayload = [
                                                'productId' => $row->productId,
                                                'startDate' => $startDate,
                                                'periodLabel' => $periodLabel,
                                                'productName' => $row->productName,
                                                'productCode' => $row->productCode,
                                                'barcode' => $row->barcode,
                                                'stockAlert' => $row->stockAlert,
                                                'globalGoodStock' => $row->globalStock->totalGood,
                                                'taxGood' => $row->globalStock->taxGood,
                                                'nonTaxGood' => $row->globalStock->nonTaxGood,
                                                'taxBroken' => $row->globalStock->taxBroken,
                                                'nonTaxBroken' => $row->globalStock->nonTaxBroken,
                                                'soldQuantity' => $row->soldQuantity,
                                                'lastSaleDate' => $row->lastSaleDate,
                                            ];
                                        @endphp
                                        <button type="button"
                                                class="btn btn-sm {{ $row->isMinimumUnset ? 'btn-outline-danger' : 'btn-outline-primary' }} p-1"
                                                x-on:click="$dispatch('open-stock-insights-minimum-modal', @js($modalPrefillPayload))"
                                                title="Atur batas minimum stok">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </div>
                                </td>

                                {{-- Minimum Stock & Attention Statuses --}}
                                <td class="text-center">
                                    <div class="mb-1">
                                        <span class="text-muted small">Min:</span>
                                        <span class="fw-semibold {{ $row->isMinimumUnset ? 'text-danger' : 'text-dark' }}">
                                            {{ $row->stockAlert }} {{ $row->unitName }}
                                        </span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                                        @if($row->isOutOfStock)
                                            <span class="badge bg-danger">Stok Habis</span>
                                        @endif
                                        @if($row->isReorderRequired)
                                            <span class="badge bg-warning text-dark">Perlu Dibeli Lagi</span>
                                        @endif
                                        @if($row->isMinimumUnset)
                                            <span class="badge bg-secondary">Batas Minimum Belum Diatur</span>
                                        @endif
                                        @if($row->isSlowMoving)
                                            <span class="badge bg-info text-dark">Lama Tidak Terjual</span>
                                        @endif
                                        @if(empty($row->getActiveStatuses()))
                                            <span class="badge bg-success-subtle text-success border border-success">Cukup</span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Global Good Stock with Composition Tooltip --}}
                                <td class="text-center border-start border-end bg-primary-subtle text-primary">
                                    <span class="fw-bold fs-6">
                                        {{ number_format($row->globalStock->totalGood, 2) }}
                                    </span>
                                    <span class="small">{{ $row->unitName }}</span>

                                    {{-- Tooltip info for bucket composition --}}
                                    <div class="small text-muted" title="Bagus Pajak: {{ $row->globalStock->taxGood }} | Bagus Non-Pajak: {{ $row->globalStock->nonTaxGood }} | Rusak Pajak: {{ $row->globalStock->taxBroken }} | Rusak Non-Pajak: {{ $row->globalStock->nonTaxBroken }}">
                                        <i class="bi bi-info-circle text-muted" style="cursor: help;"></i>
                                        @if($row->globalStock->totalBroken > 0)
                                            <span class="text-danger small ms-1">(Rusak: {{ $row->globalStock->totalBroken }})</span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Business / Location Breakdown --}}
                                @if($isGlobalExpanded)
                                    @foreach($hierarchy as $business)
                                        @php
                                            $settingId = (int) $business['setting_id'];
                                            $isExpanded = !empty($expandedBusinesses[$settingId]) || !empty($expandedBusinesses[(string) $settingId]);
                                            $locations = $business['locations'];
                                            $bizStock = $row->businesses[$settingId]['stock'] ?? null;
                                        @endphp
                                        @if(!$isExpanded || empty($locations))
                                            <td class="text-center border-start border-end">
                                                <span class="{{ ($bizStock && $bizStock->totalGood > 0) ? 'fw-bold text-dark' : 'text-muted' }}"
                                                      @if($bizStock)
                                                          title="Bagus Pajak: {{ $bizStock->taxGood }} | Bagus Non-Pajak: {{ $bizStock->nonTaxGood }} | Rusak Pajak: {{ $bizStock->taxBroken }} | Rusak Non-Pajak: {{ $bizStock->nonTaxBroken }}"
                                                      @endif>
                                                    {{ $bizStock ? number_format($bizStock->totalGood, 2) : '0' }}
                                                </span>
                                                @if($bizStock && $bizStock->totalBroken > 0)
                                                    <div class="small text-danger" style="font-size: 0.7rem;">(R: {{ $bizStock->totalBroken }})</div>
                                                @endif
                                            </td>
                                        @else
                                            @foreach($locations as $loc)
                                                @php
                                                    $locId = $loc['id'];
                                                    $locStock = $row->businesses[$settingId]['locations'][$locId]['stock'] ?? null;
                                                @endphp
                                                <td class="text-center border-start border-end">
                                                    <span class="{{ ($locStock && $locStock->totalGood > 0) ? 'fw-bold text-dark' : 'text-muted' }}"
                                                          @if($locStock)
                                                              title="Bagus Pajak: {{ $locStock->taxGood }} | Bagus Non-Pajak: {{ $locStock->nonTaxGood }} | Rusak Pajak: {{ $locStock->taxBroken }} | Rusak Non-Pajak: {{ $locStock->nonTaxBroken }}"
                                                          @endif>
                                                        {{ $locStock ? number_format($locStock->totalGood, 2) : '0' }}
                                                    </span>
                                                    @if($locStock && $locStock->totalBroken > 0)
                                                        <div class="small text-danger" style="font-size: 0.7rem;">(R: {{ $locStock->totalBroken }})</div>
                                                    @endif
                                                </td>
                                            @endforeach
                                        @endif
                                    @endforeach
                                @endif

                                {{-- Sales & Financial Values --}}
                                <td class="text-center border-start">
                                    <span class="{{ $row->soldQuantity > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                        {{ number_format($row->soldQuantity, 2) }}
                                    </span>
                                </td>

                                <td class="text-end">
                                    <span class="{{ $row->salesValue > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                        Rp {{ number_format($row->salesValue, 0, ',', '.') }}
                                    </span>
                                </td>

                                <td class="text-end">
                                    <span class="{{ $row->soldCost > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                        Rp {{ number_format($row->soldCost, 0, ',', '.') }}
                                    </span>
                                    @if($row->isCostIncomplete)
                                        <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                           title="Modal belum lengkap (terdapat penjualan tanpa snapshot HPP valid)"></i>
                                    @endif
                                </td>

                                <td class="text-end">
                                    <span class="{{ $row->grossProfit > 0 ? 'fw-bold text-success' : ($row->grossProfit < 0 ? 'fw-bold text-danger' : 'text-muted') }}">
                                        Rp {{ number_format($row->grossProfit, 0, ',', '.') }}
                                    </span>
                                    @if($row->isCostIncomplete)
                                        <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                           title="Laba kotor dihitung dari data modal parsial/tidak lengkap"></i>
                                    @endif
                                </td>

                                <td class="text-center border-end text-muted small">
                                    {{ $row->lastSaleDate ? \Carbon\Carbon::parse($row->lastSaleDate)->format('d/m/Y') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="20" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                    Tidak ada data produk yang memenuhi kriteria filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination Footer --}}
            <div class="d-flex flex-wrap justify-content-between align-items-center p-3 border-top">
                <div class="small text-muted">
                    Menampilkan {{ $rows->firstItem() ?? 0 }} sampai {{ $rows->lastItem() ?? 0 }} dari {{ $rows->total() }} produk
                </div>
                <div>
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>

    {{-- Inline Minimum Stock Modal (isolated child component to avoid full report re-render) --}}
    <livewire:reports.stock-insights-minimum-modal />

    @once
    <style>
        .stock-insights-select2-container .select2-selection--multiple {
            min-height: 38px;
            height: auto !important;
            padding: 2px 6px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
        }

        .stock-insights-select2-container .select2-selection__rendered {
            display: flex !important;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            padding: 0 !important;
            margin: 0 !important;
            width: auto !important;
        }

        .stock-insights-select2-container .select2-search--inline {
            flex: 1 1 120px;
            min-width: 120px;
            line-height: 28px;
        }

        .stock-insights-select2-container .select2-search__field {
            width: 100% !important;
            height: 28px !important;
            min-height: 28px !important;
            max-height: 28px !important;
            line-height: 28px !important;
            resize: none !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
            text-align: left;
        }

        .stock-insights-select2-container .select2-selection--single {
            height: 38px !important;
            padding: 4px 8px;
            display: flex;
            align-items: center;
        }
    </style>
    @endonce

    <script>
        document.addEventListener('livewire:initialized', () => {
            function initSelect2() {
                // Category Select2
                const $categorySelect = $('#stock-insights-category-select');
                if ($categorySelect.length) {
                    if ($categorySelect.hasClass('select2-hidden-accessible')) {
                        $categorySelect.select2('destroy').off('change');
                    }
                    let syncCat = false;
                    $categorySelect.select2({
                        placeholder: 'Semua Kategori',
                        allowClear: true,
                        theme: 'coreui',
                        width: '100%'
                    }).on('change', function () {
                        if (!syncCat) {
                            @this.set('categoryIds', $(this).val() || []);
                        }
                    });

                    Livewire.on('sync-select2-categoryIds', (data) => {
                        let payload = Array.isArray(data) ? data[0] : data;
                        let values = payload.values || payload || [];
                        syncCat = true;
                        try {
                            $categorySelect.val(values).trigger('change.select2');
                        } finally {
                            syncCat = false;
                        }
                    });
                }

                // Brand Select2
                const $brandSelect = $('#stock-insights-brand-select');
                if ($brandSelect.length) {
                    if ($brandSelect.hasClass('select2-hidden-accessible')) {
                        $brandSelect.select2('destroy').off('change');
                    }
                    let syncBrand = false;
                    $brandSelect.select2({
                        placeholder: 'Semua Merek',
                        allowClear: true,
                        theme: 'coreui',
                        width: '100%'
                    }).on('change', function () {
                        if (!syncBrand) {
                            @this.set('brandIds', $(this).val() || []);
                        }
                    });

                    Livewire.on('sync-select2-brandIds', (data) => {
                        let payload = Array.isArray(data) ? data[0] : data;
                        let values = payload.values || payload || [];
                        syncBrand = true;
                        try {
                            $brandSelect.val(values).trigger('change.select2');
                        } finally {
                            syncBrand = false;
                        }
                    });
                }

                // Period Preset Select2 (search disabled)
                const $presetSelect = $('#stock-insights-preset-select');
                if ($presetSelect.length) {
                    if ($presetSelect.hasClass('select2-hidden-accessible')) {
                        $presetSelect.select2('destroy').off('change');
                    }
                    let syncPreset = false;
                    $presetSelect.select2({
                        theme: 'coreui',
                        minimumResultsForSearch: Infinity,
                        width: '100%'
                    }).on('change', function () {
                        if (!syncPreset) {
                            @this.set('preset', $(this).val());
                        }
                    });

                    Livewire.on('sync-select2-preset', (data) => {
                        let payload = Array.isArray(data) ? data[0] : data;
                        let value = payload.values || payload || '7';
                        let label = payload.label || null;
                        let $customOption = $presetSelect.find('option[value="custom"]');
                        syncPreset = true;
                        try {
                            if (value === 'custom') {
                                if (label) {
                                    $customOption.text(label);
                                }
                                $customOption.prop('disabled', false);
                            }
                            $presetSelect.val(value).trigger('change.select2');
                        } finally {
                            syncPreset = false;
                            if (value === 'custom') {
                                $customOption.prop('disabled', true);
                            }
                        }
                    });
                }
            }

            initSelect2();
        });
    </script>
</div>
