<div class="sbp-report-root" x-data="{ showDrawer: false, isApplying: false }" x-init="$watch('showDrawer', value => { if(!value && !isApplying) { $wire.cancelFilters(); } isApplying = false; })">
    <style>
        .sbp-report-root .sbp-chip-scroll {
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
        .sbp-report-root .sbp-chip {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            white-space: normal;
            text-align: left;
            line-height: 1.25;
            padding: 0.25rem 0.5rem;
        }
        .sbp-report-root .sbp-chip-remove {
            border: 0;
            background: transparent;
            color: inherit;
            font-weight: 700;
            line-height: 1;
            padding: 0 0 0 0.35rem;
            cursor: pointer;
            opacity: 0.85;
        }
        .sbp-report-root .sbp-chip-remove:hover {
            opacity: 1;
        }
        .sbp-report-root .sbp-checkbox-dropdown {
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
        .sbp-report-root .sbp-checkbox-option {
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
        .sbp-report-root .sbp-checkbox-option:hover {
            background-color: #f8f9fa;
        }
        .sbp-report-root .sbp-checkbox-input {
            position: static !important;
            margin: 0.2rem 0.55rem 0 0 !important;
            flex-shrink: 0;
            cursor: pointer;
        }
        .sbp-report-root .sbp-applied-summary {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.82rem;
        }
    </style>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($hasPendingFilterChanges)
        <div class="alert alert-warning d-flex align-items-center justify-content-between py-2 mb-3" role="alert">
            <span><i class="bi bi-exclamation-triangle mr-2"></i>Filter telah diubah. Klik <strong>Filter</strong> untuk memperbarui hasil sebelum mengekspor.</span>
            <button type="button" wire:click="applyFilters" class="btn btn-sm btn-warning font-weight-bold">Terapkan Filter</button>
        </div>
    @endif

    <div class="d-flex flex-wrap mb-3 align-items-end" style="gap: 0.5rem;">
        <div style="min-width: 240px;">
            @if(count($availableSettings) > 1)
                @include('livewire.reports.business-checkbox-selector', [
                    'selectId' => 'saleByProductSettingIds',
                    'availableSettings' => $availableSettings,
                    'livewireProperty' => 'selectedSettingIds',
                    'label' => 'Bisnis',
                    'placeholder' => 'Pilih bisnis...',
                ])
            @elseif(count($availableSettings) === 1)
                <label class="form-label small mb-1">Bisnis</label>
                <div class="form-control d-flex align-items-center bg-light text-truncate" style="height: 38px;">
                    {{ $availableSettings[0]['company_name'] ?? $availableSettings[0]->company_name ?? '' }}
                </div>
            @endif
        </div>
        <div>
            <label class="form-label small mb-1">Periode</label>
            <select wire:model.lazy="periodPreset" wire:key="sale-by-product-period-{{ $periodPreset }}" class="form-control">
                <option value="today" @selected($periodPreset === 'today')>Hari Ini</option>
                <option value="this_week" @selected($periodPreset === 'this_week')>Minggu Ini</option>
                <option value="last_week" @selected($periodPreset === 'last_week')>Minggu Lalu</option>
                <option value="this_month" @selected($periodPreset === 'this_month')>Bulan Ini</option>
                <option value="last_month" @selected($periodPreset === 'last_month')>Bulan Lalu</option>
                <option value="this_year" @selected($periodPreset === 'this_year')>Tahun Ini</option>
                <option value="last_year" @selected($periodPreset === 'last_year')>Tahun Lalu</option>
                <option value="custom" @selected($periodPreset === 'custom')>Rentang khusus</option>
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Tanggal awal</label>
            <input type="date" wire:model.lazy="startDate" class="form-control">
        </div>
        <div>
            <label class="form-label small mb-1">Tanggal akhir</label>
            <input type="date" wire:model.lazy="endDate" class="form-control">
        </div>
        <div class="ml-auto d-flex" style="gap: 0.5rem;">
            <button wire:click="applyFilters" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading wire:target="applyFilters" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                <i wire:loading.remove wire:target="applyFilters" class="bi bi-search"></i> Filter
            </button>
            <button type="button" @click="showDrawer = true" class="btn btn-outline-secondary">
                <i class="bi bi-funnel"></i> Filter lainnya
            </button>
            <div class="dropdown">
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false" wire:loading.attr="disabled" wire:target="exportExcel,exportCsv">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <button class="dropdown-item" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel">
                            <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                            Excel
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                            <span wire:loading wire:target="exportCsv" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                            CSV
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right-side Drawer for "Filter lainnya" -->
    <div class="offcanvas offcanvas-end" tabindex="-1" :class="showDrawer ? 'show' : ''"
         x-show="showDrawer" x-transition
         x-on:keydown.escape.window="showDrawer = false"
         style="visibility: visible; z-index: 1050; background: white; position: fixed; top: 0; right: 0; height: 100vh; width: min(420px, 100vw); box-shadow: -5px 0 15px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
         aria-labelledby="filterDrawerLabel" x-cloak>
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center" style="flex-shrink: 0;">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel">Filter laporan</h5>
            <button type="button" class="close" aria-label="Tutup" @click="showDrawer = false"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1; min-height: 0;">

            {{-- Customer checkbox multi-select (global, 25-result cap) --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">
                        Customer
                        @if(count($customerIds) > 0)
                            <span class="badge badge-primary ml-1">{{ count($customerIds) }} dipilih</span>
                        @endif
                    </label>
                    @if(count($customerIds) > 0)
                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearCustomers">Hapus semua</button>
                    @endif
                </div>
                @if(count($customerIds) > 0)
                    <div class="sbp-chip-scroll mb-2">
                        @foreach($customerIds as $id)
                            <span class="badge badge-primary sbp-chip">
                                <span class="text-break">{{ $customerLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="sbp-chip-remove" aria-label="Hapus" wire:click="removeCustomer({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="customerSearch"
                           @focus="open = true" @input="open = true"
                           class="form-control" placeholder="Cari Customer (nama atau kontak, min 2 karakter)...">
                    @if(strlen(trim($customerSearch)) >= 2)
                        <div x-show="open" class="sbp-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($customerOptions as $option)
                                @php
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $customerIds), true);
                                @endphp
                                <label wire:key="cust-opt-{{ $option['id'] }}" class="sbp-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input sbp-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleCustomer({{ $option['id'] }}, @json($option['customer_name']))'>
                                    <span class="d-flex flex-column text-break">
                                        <span class="font-weight-bold">{{ $option['customer_name'] }}</span>
                                        @if(!empty($option['contact_name']) && $option['contact_name'] !== $option['customer_name'])
                                            <span class="text-muted small">{{ $option['contact_name'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search mr-1"></i> Tidak ada customer ditemukan
                                </div>
                            @endforelse
                            @if($hasMoreCustomerOptions)
                                <div class="px-3 py-2 border-top bg-light text-muted small">
                                    Menampilkan 25 hasil pertama. Persempit pencarian untuk hasil lebih spesifik.
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Category checkbox multi-select (global, includes descendants) --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">
                        Kategori Produk <span class="text-muted">(termasuk subkategori)</span>
                        @if(count($categoryIds) > 0)
                            <span class="badge badge-success ml-1">{{ count($categoryIds) }} dipilih</span>
                        @endif
                    </label>
                    @if(count($categoryIds) > 0)
                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearCategories">Hapus semua</button>
                    @endif
                </div>
                @if(count($categoryIds) > 0)
                    <div class="sbp-chip-scroll mb-2">
                        @foreach($categoryIds as $id)
                            <span class="badge badge-success sbp-chip">
                                <span class="text-break">{{ $categoryLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="sbp-chip-remove" aria-label="Hapus" wire:click="removeCategory({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="categorySearch"
                           @focus="open = true" @input="open = true"
                           class="form-control" placeholder="Cari Kategori (min 2 karakter)...">
                    @if(strlen(trim($categorySearch)) >= 2)
                        <div x-show="open" class="sbp-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($categoryOptions as $option)
                                @php
                                    $catLabel = $option['display_path'] ?? $option['category_name'];
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $categoryIds), true);
                                @endphp
                                <label wire:key="cat-opt-{{ $option['id'] }}" class="sbp-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input sbp-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleCategory({{ $option['id'] }}, @json($catLabel))'>
                                    <span class="text-break">{{ $catLabel }}</span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search mr-1"></i> Tidak ada kategori ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                </div>
            </div>

            {{-- Brand checkbox multi-select (global) --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">
                        Merek / Brand
                        @if(count($brandIds) > 0)
                            <span class="badge badge-secondary ml-1">{{ count($brandIds) }} dipilih</span>
                        @endif
                    </label>
                    @if(count($brandIds) > 0)
                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearBrands">Hapus semua</button>
                    @endif
                </div>
                @if(count($brandIds) > 0)
                    <div class="sbp-chip-scroll mb-2">
                        @foreach($brandIds as $id)
                            <span class="badge badge-secondary sbp-chip">
                                <span class="text-break">{{ $brandLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="sbp-chip-remove" aria-label="Hapus" wire:click="removeBrand({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="brandSearch"
                           @focus="open = true" @input="open = true"
                           class="form-control" placeholder="Cari Merek (min 2 karakter)...">
                    @if(strlen(trim($brandSearch)) >= 2)
                        <div x-show="open" class="sbp-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($brandOptions as $option)
                                @php
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $brandIds), true);
                                @endphp
                                <label wire:key="brand-opt-{{ $option['id'] }}" class="sbp-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input sbp-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleBrand({{ $option['id'] }}, @json($option['name']))'>
                                    <span class="text-break">{{ $option['name'] }}</span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search mr-1"></i> Tidak ada merek ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                </div>
            </div>

            {{-- Product checkbox multi-select (global, 25-result cap + 500 bulk-select) --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent="open = false">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">
                        Produk
                        @if(count($productIds) > 0)
                            <span class="badge badge-warning text-dark ml-1">{{ count($productIds) }} dipilih</span>
                        @endif
                    </label>
                    <div class="d-flex align-items-center" style="gap: 0.5rem;">
                        @if(strlen(trim($productSearch)) >= 2)
                            <button type="button" class="btn btn-link btn-sm p-0 text-primary text-decoration-none small font-weight-bold"
                                    wire:click="selectAllMatchingProducts" wire:loading.attr="disabled" wire:target="selectAllMatchingProducts">
                                <span wire:loading wire:target="selectAllMatchingProducts" class="spinner-border spinner-border-sm mr-1" role="status"></span>
                                <i wire:loading.remove wire:target="selectAllMatchingProducts" class="bi bi-check-all mr-1"></i>Pilih semua hasil
                            </button>
                        @endif
                        @if(count($productIds) > 0)
                            <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="clearProducts">Hapus semua</button>
                        @endif
                    </div>
                </div>
                @if(count($productIds) > 0)
                    <div class="sbp-chip-scroll mb-2">
                        @foreach($productIds as $id)
                            <span class="badge badge-warning text-dark sbp-chip">
                                <span class="text-break">{{ $productLabels[$id] ?? 'ID:'.$id }}</span>
                                <button type="button" class="sbp-chip-remove" aria-label="Hapus" wire:click="removeProduct({{ $id }})">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="productSearch"
                           @focus="open = true" @input="open = true"
                           class="form-control" placeholder="Cari Produk (nama atau kode, min 2 karakter)...">
                    @if(strlen(trim($productSearch)) >= 2)
                        <div x-show="open" class="sbp-checkbox-dropdown" role="listbox" aria-multiselectable="true">
                            @forelse($productOptions as $option)
                                @php
                                    $optLabel = ($option['product_code'] ?? '') ? $option['product_code'] . ' | ' . $option['product_name'] : $option['product_name'];
                                    $isChecked = in_array((int) $option['id'], array_map('intval', $productIds), true);
                                @endphp
                                <label wire:key="prod-opt-{{ $option['id'] }}" class="sbp-checkbox-option">
                                    <input type="checkbox"
                                           class="form-check-input sbp-checkbox-input"
                                           @checked($isChecked)
                                           wire:click='toggleProduct({{ $option['id'] }}, @json($optLabel))'>
                                    <span class="d-flex flex-column text-break">
                                        <span class="font-weight-bold">{{ $option['product_name'] }}</span>
                                        @if(!empty($option['product_code']))
                                            <span class="text-muted small">{{ $option['product_code'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <div class="px-3 py-3 text-center text-muted small">
                                    <i class="bi bi-search mr-1"></i> Tidak ada produk ditemukan
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

            {{-- Grup dengan tag multi-select searchable --}}
            <div class="mb-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                <label class="form-label small">Grup dengan tag</label>
                <select wire:model="tagLogic" class="form-control form-control-sm mb-2">
                    <option value="Salah satu">Salah satu</option>
                    <option value="Mencakup semua">Mencakup semua</option>
                </select>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="tagSearch"
                           @focus="open = true" @input="open = true"
                           class="form-control" placeholder="Cari Tag (min 2 karakter)...">
                    @if(strlen($tagSearch) >= 2)
                        <div x-show="open" class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($tagOptions as $option)
                                @php
                                    $locale = app()->getLocale();
                                    $nameData = is_string($option['name']) ? json_decode($option['name'], true) : $option['name'];
                                    $tagName = $nameData[$locale] ?? ($nameData['en'] ?? (is_array($nameData) ? reset($nameData) : $nameData));
                                @endphp
                                @if(in_array($option['id'], $tagIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $tagName }} <span class="badge badge-secondary ml-1">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            @click="open = false"
                                            wire:click='selectTag({{ $option['id'] }}, @json($tagName))'
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
                                        {{ $tagName }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item bg-white disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search mr-1"></i> Tidak ada tag ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($tagIds) > 0)
                        <div class="sbp-chip-scroll mt-2">
                            @foreach($tagIds as $id)
                                <span class="badge badge-info text-dark sbp-chip">
                                    <span class="text-break">{{ $tagLabels[$id] ?? 'ID:'.$id }}</span>
                                    <button type="button" class="sbp-chip-remove" aria-label="Hapus" wire:click="removeTag({{ $id }})">&times;</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Sorting Options --}}
            <div class="mb-3">
                <label class="form-label small">Urutkan berdasarkan</label>
                <select wire:model="sortField" class="form-control form-control-sm mb-2">
                    <option value="product_code">Kode Produk</option>
                    <option value="product_name">Nama Produk</option>
                    <option value="sold_quantity">Kuantitas Terjual</option>
                    <option value="sold_value">Total Nilai Terjual</option>
                    <option value="average_sales_value">Harga Penjualan Rata-rata</option>
                </select>
                <select wire:model="sortDirection" class="form-control form-control-sm">
                    <option value="desc">Descending (Z-A / Besar ke Kecil)</option>
                    <option value="asc">Ascending (A-Z / Kecil ke Besar)</option>
                </select>
            </div>

        </div>
        <div class="offcanvas-footer p-3 border-top d-flex justify-content-between" style="flex-shrink: 0;">
            <button type="button" wire:click="resetFilters" class="btn btn-link text-decoration-none px-0">Reset filter</button>
            <div>
                <button type="button" @click="showDrawer = false" class="btn btn-outline-secondary">Batalkan</button>
                <button type="button" wire:click="applyFilters" @click="isApplying = true; showDrawer = false" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </div>

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false" x-cloak></div>

    {{-- Compact summary of APPLIED selections above the report table --}}
    @if($filterTriggered && !empty($appliedSummary))
        <div class="sbp-applied-summary mb-2 d-flex flex-wrap align-items-center" style="gap: 0.5rem 1rem;">
            <span><strong>Bisnis:</strong> {{ $appliedSummary['scope'] }}</span>
            <span><strong>Periode:</strong> {{ $appliedSummary['period_label'] }} ({{ $appliedSummary['date_range'] }})</span>
            @if(!empty($appliedSummary['customers']))
                <span><strong>Customer ({{ count($appliedSummary['customers']) }}):</strong> {{ implode(', ', array_slice($appliedSummary['customers'], 0, 3)) }}@if(count($appliedSummary['customers']) > 3) +{{ count($appliedSummary['customers']) - 3 }} lainnya @endif</span>
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
            @if(!empty($appliedSummary['tags']))
                <span><strong>Tag ({{ count($appliedSummary['tags']) }}):</strong> {{ implode(', ', array_slice($appliedSummary['tags'], 0, 3)) }}@if(count($appliedSummary['tags']) > 3) +{{ count($appliedSummary['tags']) - 3 }} lainnya @endif</span>
            @endif
        </div>
    @endif

    <div class="table-responsive shadow-sm rounded mt-2">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem;">
            <thead class="table-light">
            <tr>
                <th style="white-space:nowrap">Kode Produk</th>
                <th style="white-space:nowrap">Nama Produk</th>
                <th class="text-right" style="white-space:nowrap">Kuantitas Terjual</th>
                <th style="white-space:nowrap">Satuan</th>
                <th class="text-right" style="white-space:nowrap">Total Nilai terjual</th>
                <th class="text-right" style="white-space:nowrap">Harga Penjualan Rata-rata</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($products as $row)
                    <tr>
                        <td>{{ $row->product_code }}</td>
                        <td>{{ $row->product_name }}</td>
                        <td class="text-right">{{ number_format((float)$row->sold_quantity, 2, ',', '.') }}</td>
                        <td>{{ $row->unit_name }}</td>
                        <td class="text-right">{{ number_format((float)$row->sold_value, 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format((float)$row->average_sales_value, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data penjualan per produk yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
                @if($products->count() > 0)
                    <tr class="table-secondary font-weight-bold">
                        <td colspan="4" class="text-right">Total Keseluruhan</td>
                        <td class="text-right text-primary">{{ number_format((float)$grandTotalSold, 0, ',', '.') }}</td>
                        <td></td>
                    </tr>
                @endif
            @else
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Silakan atur filter dan klik <strong>Filter</strong> untuk menampilkan laporan.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if($filterTriggered && $products instanceof \Illuminate\Pagination\LengthAwarePaginator && $products->hasPages())
        <div class="mt-3">
            {{ $products->links() }}
        </div>
    @endif
</div>
