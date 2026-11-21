<div class="cbsi-report-root"
     x-data="{ showDrawer: false, isApplying: false }"
     x-init="$watch('showDrawer', value => { if (!value && !isApplying) { $wire.cancelFilters(); } isApplying = false; })"
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
        if (target === 'all' || target === 'product') {
            if ($refs.productSearchInput) $refs.productSearchInput.value = '';
        }
     ">
    <style>
        .cbsi-report-root .cbsi-chip-scroll {
            max-height: 112px;
            overflow-y: auto;
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            padding: 0.375rem;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }
        .cbsi-report-root .cbsi-chip {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            white-space: normal;
            text-align: left;
            line-height: 1.25;
            padding: 0.25rem 0.5rem;
        }
        .cbsi-report-root .cbsi-chip-remove {
            border: 0;
            background: transparent;
            color: inherit;
            font-weight: 700;
            line-height: 1;
            padding: 0 0 0 0.35rem;
            cursor: pointer;
            opacity: 0.85;
        }
        .cbsi-report-root .cbsi-chip-remove:hover {
            opacity: 1;
        }
        .cbsi-report-root .cbsi-checkbox-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1060;
            max-height: 260px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.12);
            margin-top: 0.25rem;
        }
        .cbsi-report-root .cbsi-checkbox-option {
            display: flex;
            align-items: flex-start;
            width: 100%;
            padding: 0.45rem 0.75rem;
            margin: 0;
            cursor: pointer;
            white-space: normal;
            font-size: 0.85rem;
            line-height: 1.35;
            color: #212529;
            user-select: none;
        }
        .cbsi-report-root .cbsi-checkbox-option:hover {
            background-color: #f8f9fa;
        }
        .cbsi-report-root .cbsi-checkbox-input {
            position: static !important;
            margin: 0.2rem 0.55rem 0 0 !important;
            flex-shrink: 0;
            cursor: pointer;
        }
        .cbsi-report-root .cbsi-applied-summary {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.82rem;
            gap: 0.45rem 0.9rem;
        }
        .cbsi-report-root .cbsi-filter-section {
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e9ecef;
        }
        .cbsi-report-root .cbsi-filter-actions {
            gap: 0.5rem;
        }
        .cbsi-report-root .cbsi-action-group {
            gap: 0.5rem;
        }
        .cbsi-report-root .cbsi-selector-trigger {
            color: #212529;
            background-color: #ffffff;
            border: 1px solid #ced4da;
            font-weight: 500;
        }
        .cbsi-report-root .cbsi-selector-trigger:hover {
            background-color: #f8f9fa;
            border-color: #adb5bd;
            color: #212529;
        }
        .cbsi-report-root .cbsi-presentation-toolbar {
            gap: 0.75rem;
        }
        .cbsi-report-root .cbsi-presentation-left {
            gap: 0.75rem 1.25rem;
        }
        .cbsi-report-root .cbsi-field-height {
            height: 38px;
        }
        .cbsi-report-root .cbsi-pending-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.55rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: #856404;
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 0.25rem;
        }
    </style>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            @if ($errors->any())
                <div class="alert alert-danger py-2 mb-3">
                    <ul class="mb-0 small">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- 1. Filter Section: Row 1 (Pencarian, Bisnis / Cabang, Ketersediaan Stok) + Row 2 (Filter Actions) --}}
            <div class="cbsi-filter-section mb-3">
                <div class="row align-items-end">
                    {{-- Pencarian --}}
                    <div class="col-12 col-lg-5 mb-2 mb-lg-0">
                        <label class="small text-muted mb-1 d-block">Pencarian</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-white cbsi-field-height"><i class="bi bi-search"></i></span>
                            </div>
                            <input type="text"
                                   x-ref="searchInput"
                                   wire:model.live.debounce.400ms="search"
                                   class="form-control cbsi-field-height"
                                   placeholder="Cari nama, kode, barcode, atau serial...">
                            @if($search)
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary cbsi-field-height" type="button" wire:click="clearSearch" title="Hapus pencarian">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Bisnis / Cabang --}}
                    <div class="col-12 col-md-7 col-lg-4 mb-2 mb-lg-0">
                        @include('livewire.reports.business-checkbox-selector', [
                            'label' => 'Bisnis / Cabang',
                            'availableSettings' => $availableSettings,
                            'selectId' => 'cross-business-select',
                            'livewireProperty' => 'selectedSettingIds',
                            'placeholder' => 'Pilih bisnis...',
                        ])
                    </div>

                    {{-- Ketersediaan Stok --}}
                    <div class="col-12 col-md-5 col-lg-3">
                        <label class="small text-muted mb-1 d-block">Ketersediaan Stok</label>
                        <select wire:model.live="availability" class="form-control cbsi-field-height">
                            <option value="all">Semua Stok</option>
                            <option value="available">Tersedia (> 0)</option>
                            <option value="non_available">Kosong (<= 0)</option>
                        </select>
                    </div>
                </div>

                {{-- 2. Filter Actions Row --}}
                <div class="d-flex flex-wrap align-items-center justify-content-between mt-3 cbsi-filter-actions">
                    <div>
                        <button type="button" @click="showDrawer = true" class="btn cbsi-selector-trigger">
                            <i class="bi bi-funnel mr-1"></i> Kategori, Merek &amp; Produk
                            @php $draftDrawerCount = count($categoryIds) + count($brandIds) + count($productIds); @endphp
                            @if($draftDrawerCount > 0)
                                <span class="badge badge-primary ml-1">{{ $draftDrawerCount }}</span>
                            @endif
                        </button>
                    </div>

                    <div class="d-flex flex-wrap align-items-center cbsi-action-group">
                        <button type="button" wire:click="resetFilters" class="btn btn-outline-danger">
                            <i class="bi bi-arrow-counterclockwise mr-1"></i> Reset
                        </button>
                        <button type="button" wire:click="cancelFilters" class="btn btn-outline-secondary" @disabled(!$hasPendingFilterChanges)>
                            Batalkan
                        </button>
                        <button type="button" wire:click="applyFilters" wire:loading.attr="disabled" class="btn btn-primary">
                            <span wire:loading wire:target="applyFilters" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                            <i wire:loading.remove wire:target="applyFilters" class="bi bi-check2-circle mr-1"></i> Terapkan Filter
                        </button>
                    </div>
                </div>
            </div>

            {{-- 3. Compact Applied Summary directly below filters --}}
            @if(!empty($appliedSummary))
                <div class="cbsi-applied-summary mb-3 d-flex flex-wrap align-items-center justify-content-between">
                    <div class="d-flex flex-wrap align-items-center" style="gap: 0.45rem 0.9rem;">
                        <span class="font-weight-bold text-dark">Diterapkan:</span>
                        <span><strong>Bisnis:</strong> {{ $appliedSummary['scope'] }}</span>
                        <span><strong>Ketersediaan:</strong> {{ $appliedSummary['availability'] }}</span>
                        @if($appliedSummary['search'] !== '')
                            <span><strong>Pencarian:</strong> <code>{{ $appliedSummary['search'] }}</code></span>
                        @endif
                        @if(!empty($appliedSummary['categories']))
                            <span><strong>Kategori ({{ count($appliedSummary['categories']) }}):</strong> {{ implode(', ', array_slice($appliedSummary['categories'], 0, 3)) }}@if(count($appliedSummary['categories']) > 3) +{{ count($appliedSummary['categories']) - 3 }} lainnya @endif</span>
                        @endif
                        @if(!empty($appliedSummary['brands']))
                            <span><strong>Merek ({{ count($appliedSummary['brands']) }}):</strong> {{ implode(', ', array_slice($appliedSummary['brands'], 0, 3)) }}@if(count($appliedSummary['brands']) > 3) +{{ count($appliedSummary['brands']) - 3 }} lainnya @endif</span>
                        @endif
                        @if(!empty($appliedSummary['products']))
                            <span><strong>Produk ({{ count($appliedSummary['products']) }}):</strong> {{ implode(', ', array_slice($appliedSummary['products'], 0, 3)) }}@if(count($appliedSummary['products']) > 3) +{{ count($appliedSummary['products']) - 3 }} lainnya @endif</span>
                        @endif
                    </div>
                    @if($hasPendingFilterChanges)
                        <span class="cbsi-pending-badge" role="status">
                            <i class="bi bi-exclamation-triangle mr-1"></i> Perubahan belum diterapkan
                        </span>
                    @endif
                </div>
            @endif

            {{-- 4. Presentation Toolbar immediately above the table --}}
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 cbsi-presentation-toolbar">
                <div class="d-flex flex-wrap align-items-center cbsi-presentation-left">
                    <div class="d-flex align-items-center">
                        <label for="cbsiDisplayModeSelect" class="small text-muted mb-0 mr-2 text-nowrap">Format Kuantitas</label>
                        <select id="cbsiDisplayModeSelect" wire:model.live="displayMode" class="form-control form-control-sm" style="width: auto; min-width: 145px;">
                            <option value="decimal">Hanya Angka</option>
                            <option value="conversion">Dengan Satuan</option>
                        </select>
                    </div>

                    @if($canViewPrices)
                        <div class="custom-control custom-switch mb-0">
                            <input type="checkbox"
                                   class="custom-control-input"
                                   id="cbsiShowPricesToggle"
                                   wire:model.live="showPrices">
                            <label class="custom-control-label small font-weight-bold" for="cbsiShowPricesToggle">
                                Tampilkan Harga <span class="text-muted font-weight-normal">(per Satuan Dasar)</span>
                            </label>
                        </div>
                    @endif
                </div>

                <div>
                    @if($canViewPrices)
                        <div class="dropdown">
                            <button class="btn btn-outline-success btn-sm dropdown-toggle"
                                    type="button"
                                    data-toggle="dropdown"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                    @disabled($hasPendingFilterChanges)
                                    wire:loading.attr="disabled"
                                    wire:target="exportExcel">
                                <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                                <i wire:loading.remove wire:target="exportExcel" class="bi bi-file-earmark-excel mr-1"></i> Ekspor
                            </button>
                            <div class="dropdown-menu dropdown-menu-right">
                                <button type="button" class="dropdown-item" wire:click="exportExcel('stock')" @disabled($hasPendingFilterChanges)>
                                    Stok saja
                                </button>
                                <button type="button" class="dropdown-item" wire:click="exportExcel('prices')" @disabled($hasPendingFilterChanges)>
                                    Harga saja
                                </button>
                                <button type="button" class="dropdown-item" wire:click="exportExcel('stock_and_prices')" @disabled($hasPendingFilterChanges)>
                                    Stok + Harga
                                </button>
                            </div>
                        </div>
                    @else
                        <button type="button"
                                wire:click="exportExcel('stock')"
                                @disabled($hasPendingFilterChanges)
                                wire:loading.attr="disabled"
                                class="btn btn-outline-success btn-sm">
                            <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                            <i wire:loading.remove wire:target="exportExcel" class="bi bi-file-earmark-excel mr-1"></i> Ekspor
                        </button>
                    @endif
                </div>
            </div>

            @php
                $formatPrice = function ($val): string {
                    if ($val === null) {
                        return '—';
                    }
                    $num = (float) $val;
                    $decimals = (abs($num - round($num)) < 0.000001) ? 0 : 2;
                    return 'Rp ' . number_format($num, $decimals, ',', '.');
                };

                $emptyColspan = $showPrices ? 7 : 6;
                if ($businesses->isEmpty()) {
                    $emptyColspan += $showPrices ? 7 : 3;
                } else {
                    foreach ($businesses as $b) {
                        $isExp = $expandedBusinesses[$b['setting_id']] ?? false;
                        $locCnt = count($b['locations']);
                        $emptyColspan += ($isExp ? (max(1, $locCnt) * 2) + 1 : 3) + ($showPrices ? 4 : 0);
                    }
                }
            @endphp

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
                            @if($showPrices)
                                <th rowspan="2" class="align-middle text-center" style="min-width: 90px;">Satuan</th>
                            @endif
                            <th rowspan="2" class="align-middle text-center bg-light border-start" style="min-width: 95px;">Total Bagus</th>
                            <th rowspan="2" class="align-middle text-center bg-light" style="min-width: 95px;">Total Rusak</th>
                            <th rowspan="2" class="align-middle text-center bg-light border-end" style="min-width: 125px;">Dalam Pengiriman</th>

                            @forelse($businesses as $b)
                                @php
                                    $isExpanded = $expandedBusinesses[$b['setting_id']] ?? false;
                                    $locCount = count($b['locations']);
                                    $stockSpan = $isExpanded ? (max(1, $locCount) * 2) + 1 : 3;
                                    $colspan = $stockSpan + ($showPrices ? 4 : 0);
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
                                <th colspan="{{ $showPrices ? 7 : 3 }}" class="text-center text-muted">Pilih minimal satu bisnis</th>
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
                                    <th class="text-center" style="min-width: 90px;">Rusak</th>
                                    <th class="text-center border-end text-info" style="min-width: 125px;">Dalam Pengiriman</th>
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
                                    <th class="text-center border-end text-info" style="min-width: 125px;" title="Dalam Pengiriman (Bisnis)">
                                        <div class="small text-truncate" style="max-width: 125px;">{{ $b['company_name'] }}</div>
                                        <span class="small">Dalam Pengiriman</span>
                                    </th>
                                @endif

                                @if($showPrices)
                                    <th class="text-end bg-light" style="min-width: 115px;" data-price-header="business-{{ $b['setting_id'] }}-modal">Harga Modal</th>
                                    <th class="text-end bg-light" style="min-width: 115px;" data-price-header="business-{{ $b['setting_id'] }}-sale">Harga Jual</th>
                                    <th class="text-end bg-light" style="min-width: 110px;" data-price-header="business-{{ $b['setting_id'] }}-tier1">Tier 1</th>
                                    <th class="text-end bg-light border-end" style="min-width: 110px;" data-price-header="business-{{ $b['setting_id'] }}-tier2">Tier 2</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $product)
                            @php
                                $baseUnitName = $product['base_unit_name'] ?? null;
                                $convUnitName = $product['conversion_unit_name'] ?? null;
                                $convFactor = $product['conversion_factor'] ?? null;

                                $formatQty = function ($qty) use ($displayMode, $baseUnitName, $convUnitName, $convFactor) {
                                    return \App\Services\Reports\CrossBusinessQuantityPresenter::format(
                                        $qty,
                                        $displayMode,
                                        $baseUnitName,
                                        $convUnitName,
                                        $convFactor
                                    );
                                };
                            @endphp
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
                                @if($showPrices)
                                    <td class="text-center text-muted" data-base-unit="{{ $product['id'] }}">{{ $baseUnitName ?: '-' }}</td>
                                @endif

                                {{-- Total Stock Across Selected Businesses --}}
                                <td class="text-center border-start bg-light-subtle">
                                    <span class="{{ ($product['total_good'] ?? 0) > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                        {{ $formatQty($product['total_good'] ?? 0) }}
                                    </span>
                                </td>
                                <td class="text-center bg-light-subtle">
                                    <span class="{{ ($product['total_bad'] ?? 0) > 0 ? 'fw-bold text-danger' : 'text-muted' }}">
                                        {{ $formatQty($product['total_bad'] ?? 0) }}
                                    </span>
                                </td>
                                {{-- Total In-Delivery Across Selected Businesses --}}
                                <td class="text-center border-end bg-light-subtle" data-stock-cell="global-in-delivery">
                                    <span class="{{ ($product['total_in_delivery'] ?? 0) > 0 ? 'fw-bold text-info' : 'text-muted' }}">
                                        {{ $formatQty($product['total_in_delivery'] ?? 0) }}
                                    </span>
                                </td>

                                {{-- Business Stock & Optional Price Columns --}}
                                @foreach($businesses as $b)
                                    @php
                                        $settingId = $b['setting_id'];
                                        $isExpanded = $expandedBusinesses[$settingId] ?? false;
                                        $bStock = $product['businesses'][$settingId] ?? null;
                                        $locs = $b['locations'];
                                        $bInDelivery = $bStock['in_delivery'] ?? 0.0;
                                    @endphp

                                    @if(!$isExpanded || empty($locs))
                                        {{-- Collapsed Good Cell --}}
                                        <td class="text-center border-start{{ ($bStock['marker_good'] ?? false) ? ' serial-marker' : '' }}" data-stock-cell="business-{{ $settingId }}-good">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <span class="{{ ($bStock['good'] ?? 0) > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                                    {{ $formatQty($bStock['good'] ?? 0) }}
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
                                        <td class="text-center{{ ($bStock['marker_bad'] ?? false) ? ' serial-marker' : '' }}" data-stock-cell="business-{{ $settingId }}-bad">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <span class="{{ ($bStock['bad'] ?? 0) > 0 ? 'fw-bold text-danger' : 'text-muted' }}">
                                                    {{ $formatQty($bStock['bad'] ?? 0) }}
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

                                        {{-- Collapsed Business In-Delivery Cell --}}
                                        <td class="text-center border-end" data-stock-cell="business-{{ $settingId }}-in-delivery">
                                            <span class="{{ $bInDelivery > 0 ? 'fw-bold text-info' : 'text-muted' }}">
                                                {{ $formatQty($bInDelivery) }}
                                            </span>
                                        </td>
                                    @else
                                        {{-- Expanded Per-Location Cells --}}
                                        @foreach($locs as $loc)
                                            @php
                                                $locId = $loc['id'];
                                                $lData = $bStock['locations'][$locId] ?? null;
                                            @endphp
                                            {{-- Good Cell --}}
                                            <td class="text-center border-start{{ ($lData['marker_good'] ?? false) ? ' serial-marker' : '' }}" data-stock-cell="location-{{ $locId }}-good">
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <span class="{{ ($lData['good'] ?? 0) > 0 ? 'fw-bold text-dark' : 'text-muted' }}">
                                                        {{ $formatQty($lData['good'] ?? 0) }}
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
                                            <td class="text-center border-end{{ ($lData['marker_bad'] ?? false) ? ' serial-marker' : '' }}" data-stock-cell="location-{{ $locId }}-bad">
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <span class="{{ ($lData['bad'] ?? 0) > 0 ? 'fw-bold text-danger' : 'text-muted' }}">
                                                        {{ $formatQty($lData['bad'] ?? 0) }}
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

                                        {{-- Expanded Business In-Delivery Cell (Retained once per business) --}}
                                        <td class="text-center border-end" data-stock-cell="business-{{ $settingId }}-in-delivery">
                                            <span class="{{ $bInDelivery > 0 ? 'fw-bold text-info' : 'text-muted' }}">
                                                {{ $formatQty($bInDelivery) }}
                                            </span>
                                        </td>
                                    @endif

                                    {{-- Optional Business Prices (Once per business, never repeated per location) --}}
                                    @if($showPrices)
                                        @php
                                            $pRow = $loadedPrices[$product['id']][$settingId] ?? null;
                                            $pModal = $pRow['average_purchase_price'] ?? null;
                                            $pSale = $pRow['sale_price'] ?? null;
                                            $pTier1 = $pRow['tier_1_price'] ?? null;
                                            $pTier2 = $pRow['tier_2_price'] ?? null;
                                        @endphp
                                        <td class="text-end {{ $pModal === null ? 'text-muted' : '' }}" data-price-cell="business-{{ $settingId }}-modal">{{ $formatPrice($pModal) }}</td>
                                        <td class="text-end {{ $pSale === null ? 'text-muted' : '' }}" data-price-cell="business-{{ $settingId }}-sale">{{ $formatPrice($pSale) }}</td>
                                        <td class="text-end {{ $pTier1 === null ? 'text-muted' : '' }}" data-price-cell="business-{{ $settingId }}-tier1">{{ $formatPrice($pTier1) }}</td>
                                        <td class="text-end border-end {{ $pTier2 === null ? 'text-muted' : '' }}" data-price-cell="business-{{ $settingId }}-tier2">{{ $formatPrice($pTier2) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $emptyColspan }}" class="text-center py-4 text-muted">
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

    {{-- Filter Lainnya Drawer (Category, Brand & Product) --}}
    <div class="offcanvas offcanvas-end" tabindex="-1" :class="showDrawer ? 'show' : ''"
         x-show="showDrawer" x-transition
         x-on:keydown.escape.window="showDrawer = false"
         style="visibility: visible; z-index: 1050; background: white; position: fixed; top: 0; right: 0; height: 100vh; width: min(420px, 100vw); box-shadow: -5px 0 15px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
         aria-labelledby="filterDrawerLabel" x-cloak>
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center" style="flex-shrink: 0;">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel"><i class="bi bi-funnel me-1"></i> Filter Kategori, Merek & Produk</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1; min-height: 0;">
            {{-- Category checkbox multi-select (includes descendants) --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-bold mb-0">
                        Kategori Produk <span class="text-muted fw-normal">(termasuk subkategori)</span>
                        @if(count($categoryIds) > 0)
                            <span class="badge bg-success ms-1">{{ count($categoryIds) }} dipilih</span>
                        @endif
                    </label>
                    @if(count($categoryIds) > 0)
                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearCategories">Hapus semua</button>
                    @endif
                </div>
                @if(count($categoryIds) > 0)
                    <div class="cbsi-chip-scroll mb-2" data-chip-area="category">
                        @foreach($categoryIds as $id)
                            <span class="badge bg-success cbsi-chip">
                                <span class="text-break">{{ $categoryLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="cbsi-chip-remove" aria-label="Hapus" wire:click="removeCategory({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text"
                           x-ref="categorySearchInput"
                           wire:model.live.debounce.300ms="categorySearch"
                           @focus="open = true" @input="open = true"
                           class="form-control"
                           placeholder="Cari kategori (min 2 karakter)...">
                    @if(strlen(trim($categorySearch)) >= 2)
                        <div x-show="open" class="cbsi-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($categoryOptions as $option)
                                @php
                                    $catLabel = $option['display_path'] ?? $option['category_name'];
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $categoryIds), true);
                                @endphp
                                <label wire:key="cbsi-cat-opt-{{ $option['id'] }}" class="cbsi-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input cbsi-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleCategory({{ $option['id'] }}, @json($catLabel))'>
                                    <span class="text-break">{{ $catLabel }}</span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search me-1"></i> Tidak ada kategori ditemukan
                                </div>
                            @endforelse
                            @if($hasMoreCategoryOptions)
                                <div class="px-3 py-2 border-top bg-light text-muted small">
                                    Menampilkan 25 hasil pertama. Persempit pencarian untuk hasil lebih spesifik.
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Brand checkbox multi-select --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-bold mb-0">
                        Merek / Brand
                        @if(count($brandIds) > 0)
                            <span class="badge bg-secondary ms-1">{{ count($brandIds) }} dipilih</span>
                        @endif
                    </label>
                    @if(count($brandIds) > 0)
                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearBrands">Hapus semua</button>
                    @endif
                </div>
                @if(count($brandIds) > 0)
                    <div class="cbsi-chip-scroll mb-2" data-chip-area="brand">
                        @foreach($brandIds as $id)
                            <span class="badge bg-secondary cbsi-chip">
                                <span class="text-break">{{ $brandLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="cbsi-chip-remove" aria-label="Hapus" wire:click="removeBrand({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text"
                           x-ref="brandSearchInput"
                           wire:model.live.debounce.300ms="brandSearch"
                           @focus="open = true" @input="open = true"
                           class="form-control"
                           placeholder="Cari merek (min 2 karakter)...">
                    @if(strlen(trim($brandSearch)) >= 2)
                        <div x-show="open" class="cbsi-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($brandOptions as $option)
                                @php
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $brandIds), true);
                                @endphp
                                <label wire:key="cbsi-brand-opt-{{ $option['id'] }}" class="cbsi-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input cbsi-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleBrand({{ $option['id'] }}, @json($option['name']))'>
                                    <span class="text-break">{{ $option['name'] }}</span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search me-1"></i> Tidak ada merek ditemukan
                                </div>
                            @endforelse
                            @if($hasMoreBrandOptions)
                                <div class="px-3 py-2 border-top bg-light text-muted small">
                                    Menampilkan 25 hasil pertama. Persempit pencarian untuk hasil lebih spesifik.
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Product checkbox multi-select (global, 25-result cap + 500 bulk-select) --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-bold mb-0">
                        Produk
                        @if(count($productIds) > 0)
                            <span class="badge bg-warning text-dark ms-1">{{ count($productIds) }} dipilih</span>
                        @endif
                    </label>
                    <div class="d-flex align-items-center gap-2">
                        @if(strlen(trim($productSearch)) >= 2)
                            <button type="button" class="btn btn-link btn-sm p-0 text-primary text-decoration-none small fw-bold"
                                    wire:click="selectAllMatchingProducts" wire:loading.attr="disabled" wire:target="selectAllMatchingProducts">
                                <span wire:loading wire:target="selectAllMatchingProducts" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                <i wire:loading.remove wire:target="selectAllMatchingProducts" class="bi bi-check-all me-1"></i>Pilih semua hasil
                            </button>
                        @endif
                        @if(count($productIds) > 0)
                            <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearProducts">Hapus semua</button>
                        @endif
                    </div>
                </div>
                @if(count($productIds) > 0)
                    <div class="cbsi-chip-scroll mb-2" data-chip-area="product">
                        @foreach($productIds as $id)
                            <span class="badge bg-warning text-dark cbsi-chip">
                                <span class="text-break">{{ $productLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="cbsi-chip-remove" aria-label="Hapus" wire:click="removeProduct({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text"
                           x-ref="productSearchInput"
                           wire:model.live.debounce.300ms="productSearch"
                           @focus="open = true" @input="open = true"
                           class="form-control"
                           placeholder="Cari produk (nama atau kode, min 2 karakter)...">
                    @if(strlen(trim($productSearch)) >= 2)
                        <div x-show="open" class="cbsi-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($productOptions as $option)
                                @php
                                    $optLabel = ($option['product_code'] ?? '') ? $option['product_code'] . ' | ' . $option['product_name'] : $option['product_name'];
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $productIds), true);
                                @endphp
                                <label wire:key="cbsi-prod-opt-{{ $option['id'] }}" class="cbsi-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input cbsi-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleProduct({{ $option['id'] }}, @json($optLabel))'>
                                    <span class="d-flex flex-column text-break">
                                        <span class="fw-bold">{{ $option['product_name'] }}</span>
                                        @if(!empty($option['product_code']))
                                            <span class="text-muted small">{{ $option['product_code'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search me-1"></i> Tidak ada produk ditemukan
                                </div>
                            @endforelse
                            @if($hasMoreProductOptions)
                                <div class="px-3 py-2 border-top bg-light text-muted small">
                                    Menampilkan 25 hasil pertama. Persempit pencarian untuk hasil lebih spesifik.
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="offcanvas-footer p-3 border-top d-flex justify-content-between" style="flex-shrink: 0;">
            <button type="button" wire:click="resetFilters" @click="isApplying = true; showDrawer = false" class="btn btn-link text-decoration-none px-0">Reset filter</button>
            <div class="d-flex gap-2">
                <button type="button" @click="showDrawer = false" class="btn btn-outline-secondary btn-sm">Batalkan</button>
                <button type="button" wire:click="applyFilters" @click="isApplying = true; showDrawer = false" class="btn btn-primary btn-sm px-3">Terapkan Filter</button>
            </div>
        </div>
    </div>

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false" x-cloak></div>

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

                        @if($dialogDiscrepancy)
                            <div class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                                <div>
                                    Perhatian: Terdapat selisih antara saldo stok tercatat ({{ $dialogDiscrepancy['bucket_total'] }}) dan jumlah nomor seri tersedia ({{ $dialogDiscrepancy['serial_count'] }}) untuk kondisi {{ $dialogDiscrepancy['condition'] }}.
                                </div>
                            </div>
                        @endif

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
                                            @php
                                                $combinedState = $serial->getCombinedState();
                                            @endphp
                                            <tr>
                                                <td class="ps-3 text-muted small">{{ $dialogSerials->firstItem() + $index }}</td>
                                                <td><code>{{ $serial->serial_number }}</code></td>
                                                <td class="small">{{ optional($serial->location)->name ?? '-' }}</td>
                                                <td>
                                                    <span class="badge {{ $combinedState['badge_class'] }}">{{ $combinedState['label'] }}</span>
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
    /* Serial-location stabilo-style marker: soft yellow with readable contrast */
    .table td.serial-marker {
        background-color: #fff9c4 !important;
        color: #424242 !important;
    }
    .table-hover tbody tr:hover td.serial-marker {
        background-color: #fff176 !important;
        color: #212121 !important;
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
