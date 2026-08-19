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
            <label class="form-label small">Periode</label>
            <select wire:model.live="periodPreset" class="form-control">
                <option value="">-- Pilih Periode --</option>
                <option value="today">Hari Ini</option>
                <option value="this_week">Minggu Ini</option>
                <option value="this_month">Bulan Ini</option>
                <option value="this_year">Tahun Ini</option>
            </select>
        </div>
        <div>
            <label class="form-label small">Tanggal awal</label>
            <input type="date" wire:model.live="startDate" value="{{ $startDate }}" class="form-control">
        </div>
        <div>
            <label class="form-label small">Tanggal akhir</label>
            <input type="date" wire:model.live="endDate" value="{{ $endDate }}" class="form-control">
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
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false" wire:loading.attr="disabled" wire:target="exportExcel,exportCsv">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <button class="dropdown-item" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel">
                            <span wire:loading wire:target="exportExcel" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            Excel
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                            <span wire:loading wire:target="exportCsv" class="spinner-border spinner-border-sm me-1" role="status"></span>
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
         style="visibility: visible; z-index: 1050; background: white; position: fixed; top: 0; right: 0; height: 100vh; width: 420px; box-shadow: -5px 0 15px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
         aria-labelledby="filterDrawerLabel" x-cloak>
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center" style="flex-shrink: 0;">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel">Filter laporan</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1; min-height: 0;">

            {{-- Customer multi-select searchable --}}
            <div class="mb-3" x-data="{ expanded: false }">
                <label class="form-label small">Customer</label>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="customerSearch"
                           class="form-control" placeholder="Cari Customer (min 2 karakter)...">
                    @if(strlen($customerSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($customerOptions as $option)
                                @if(in_array($option['id'], $customerIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $option['customer_name'] }} <span class="badge bg-secondary ms-1">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click='selectCustomer({{ $option['id'] }}, @json($option['customer_name']))'
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
                                        {{ $option['customer_name'] }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item bg-white disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada customer ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($customerIds) > 0)
                        <div class="mt-2">
                            @if(count($customerIds) > 10)
                                <div x-show="!expanded" class="d-flex justify-content-between align-items-center bg-light p-2 rounded small border">
                                    <span class="fw-semibold text-primary"><i class="bi bi-people me-1"></i>{{ count($customerIds) }} customer dipilih</span>
                                    <div>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 me-2" @click="expanded = true">Tampilkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0" wire:click="$set('customerIds', []); $set('customerLabels', [])">Hapus semua</button>
                                    </div>
                                </div>
                            @endif
                            <div @if(count($customerIds) > 10) x-show="expanded" @endif>
                                @if(count($customerIds) > 10)
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <button type="button" class="btn btn-link btn-sm text-muted text-decoration-none p-0 small" @click="expanded = false"><i class="bi bi-chevron-up me-1"></i>Ciutkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="$set('customerIds', []); $set('customerLabels', [])">Hapus semua</button>
                                    </div>
                                @endif
                                <div class="d-flex flex-wrap gap-1 p-1 bg-light rounded border" style="max-height: 120px; overflow-y: auto;">
                                    @foreach($customerIds as $id)
                                        <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1" style="max-width: 100%; white-space: normal; text-align: left;">
                                            <span class="text-truncate">{{ $customerLabels[$id] ?? 'ID:'.$id }}</span>
                                            <button type="button" class="btn-close btn-close-white ms-2 flex-shrink-0" style="font-size: 0.5rem" wire:click="removeCustomer({{ $id }})"></button>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="customerSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            {{-- Category multi-select searchable --}}
            <div class="mb-3" x-data="{ expanded: false }">
                <label class="form-label small">Kategori Produk</label>
                <select wire:model="categoryLogic" class="form-control form-control-sm mb-2">
                    <option value="Salah satu">Salah satu</option>
                    <option value="Mencakup semua">Mencakup semua</option>
                </select>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="categorySearch"
                           class="form-control" placeholder="Cari Kategori (min 2 karakter)...">
                    @if(strlen($categorySearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($categoryOptions as $option)
                                @if(in_array($option['id'], $categoryIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $option['category_name'] }} <span class="badge bg-secondary ms-1">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click='selectCategory({{ $option['id'] }}, @json($option['category_name']))'
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
                                        {{ $option['category_name'] }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item bg-white disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada kategori ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($categoryIds) > 0)
                        <div class="mt-2">
                            @if(count($categoryIds) > 10)
                                <div x-show="!expanded" class="d-flex justify-content-between align-items-center bg-light p-2 rounded small border">
                                    <span class="fw-semibold text-success"><i class="bi bi-folder me-1"></i>{{ count($categoryIds) }} kategori dipilih</span>
                                    <div>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 me-2" @click="expanded = true">Tampilkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0" wire:click="$set('categoryIds', []); $set('categoryLabels', [])">Hapus semua</button>
                                    </div>
                                </div>
                            @endif
                            <div @if(count($categoryIds) > 10) x-show="expanded" @endif>
                                @if(count($categoryIds) > 10)
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <button type="button" class="btn btn-link btn-sm text-muted text-decoration-none p-0 small" @click="expanded = false"><i class="bi bi-chevron-up me-1"></i>Ciutkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="$set('categoryIds', []); $set('categoryLabels', [])">Hapus semua</button>
                                    </div>
                                @endif
                                <div class="d-flex flex-wrap gap-1 p-1 bg-light rounded border" style="max-height: 120px; overflow-y: auto;">
                                    @foreach($categoryIds as $id)
                                        <span class="badge rounded-pill bg-success text-white d-inline-flex align-items-center px-2 py-1" style="max-width: 100%; white-space: normal; text-align: left;">
                                            <span class="text-truncate">{{ $categoryLabels[$id] ?? 'ID:'.$id }}</span>
                                            <button type="button" class="btn-close btn-close-white ms-2 flex-shrink-0" style="font-size: 0.5rem" wire:click="removeCategory({{ $id }})"></button>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="categorySearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            {{-- Product multi-select searchable --}}
            <div class="mb-3" x-data="{ expanded: false }">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">Produk</label>
                    @if(strlen(trim($productSearch)) >= 2)
                        <button type="button" class="btn btn-link btn-sm p-0 text-primary text-decoration-none small fw-semibold"
                                wire:click="selectAllMatchingProducts" wire:loading.attr="disabled" wire:target="selectAllMatchingProducts">
                            <span wire:loading wire:target="selectAllMatchingProducts" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            <i wire:loading.remove wire:target="selectAllMatchingProducts" class="bi bi-check-all me-1"></i>Pilih semua hasil
                        </button>
                    @endif
                </div>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="productSearch"
                           class="form-control" placeholder="Cari Produk (min 2 karakter)...">
                    @if(strlen($productSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($productOptions as $option)
                                @php
                                    $optLabel = ($option['product_code'] ?? '') ? $option['product_code'] . ' | ' . $option['product_name'] : $option['product_name'];
                                @endphp
                                @if(in_array($option['id'], $productIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2 d-flex justify-content-between align-items-center" disabled>
                                        <div class="text-truncate me-2">
                                            @if(!empty($option['product_code']))
                                                <span class="text-muted fw-bold me-1">[{{ $option['product_code'] }}]</span>
                                            @endif
                                            {{ $option['product_name'] }}
                                        </div>
                                        <span class="badge bg-secondary ms-1 flex-shrink-0">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click='selectProduct({{ $option['id'] }}, @json($optLabel))'
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
                                        @if(!empty($option['product_code']))
                                            <span class="text-muted fw-bold me-1">[{{ $option['product_code'] }}]</span>
                                        @endif
                                        {{ $option['product_name'] }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item bg-white disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada produk ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($productIds) > 0)
                        <div class="mt-2">
                            @if(count($productIds) > 10)
                                <div x-show="!expanded" class="d-flex justify-content-between align-items-center bg-light p-2 rounded small border">
                                    <span class="fw-semibold text-warning text-dark"><i class="bi bi-box-seam me-1"></i>{{ count($productIds) }} produk dipilih</span>
                                    <div>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 me-2" @click="expanded = true">Tampilkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0" wire:click="$set('productIds', []); $set('productLabels', [])">Hapus semua</button>
                                    </div>
                                </div>
                            @endif
                            <div @if(count($productIds) > 10) x-show="expanded" @endif>
                                @if(count($productIds) > 10)
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <button type="button" class="btn btn-link btn-sm text-muted text-decoration-none p-0 small" @click="expanded = false"><i class="bi bi-chevron-up me-1"></i>Ciutkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="$set('productIds', []); $set('productLabels', [])">Hapus semua</button>
                                    </div>
                                @endif
                                <div class="d-flex flex-wrap gap-1 p-1 bg-light rounded border" style="max-height: 120px; overflow-y: auto;">
                                    @foreach($productIds as $id)
                                        <span class="badge rounded-pill bg-warning text-dark d-inline-flex align-items-center px-2 py-1" style="max-width: 100%; white-space: normal; text-align: left;">
                                            <span class="text-truncate">{{ $productLabels[$id] ?? 'ID:'.$id }}</span>
                                            <button type="button" class="btn-close btn-close-dark ms-2 flex-shrink-0" style="font-size: 0.5rem" wire:click="removeProduct({{ $id }})"></button>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="productSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            {{-- Grup dengan tag multi-select searchable --}}
            <div class="mb-3" x-data="{ expanded: false }">
                <label class="form-label small">Grup dengan tag</label>
                <select wire:model="tagLogic" class="form-control form-control-sm mb-2">
                    <option value="Salah satu">Salah satu</option>
                    <option value="Mencakup semua">Mencakup semua</option>
                </select>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="tagSearch"
                           class="form-control" placeholder="Cari Tag (min 2 karakter)...">
                    @if(strlen($tagSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($tagOptions as $option)
                                @php
                                    $locale = app()->getLocale();
                                    $nameData = is_string($option['name']) ? json_decode($option['name'], true) : $option['name'];
                                    $tagName = $nameData[$locale] ?? ($nameData['en'] ?? (is_array($nameData) ? reset($nameData) : $nameData));
                                @endphp
                                @if(in_array($option['id'], $tagIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $tagName }} <span class="badge bg-secondary ms-1">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click='selectTag({{ $option['id'] }}, @json($tagName))'
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
                                        {{ $tagName }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item bg-white disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada tag ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($tagIds) > 0)
                        <div class="mt-2">
                            @if(count($tagIds) > 10)
                                <div x-show="!expanded" class="d-flex justify-content-between align-items-center bg-light p-2 rounded small border">
                                    <span class="fw-semibold text-info text-dark"><i class="bi bi-tag-fill me-1"></i>{{ count($tagIds) }} tag dipilih</span>
                                    <div>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 me-2" @click="expanded = true">Tampilkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0" wire:click="$set('tagIds', []); $set('tagLabels', [])">Hapus semua</button>
                                    </div>
                                </div>
                            @endif
                            <div @if(count($tagIds) > 10) x-show="expanded" @endif>
                                @if(count($tagIds) > 10)
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <button type="button" class="btn btn-link btn-sm text-muted text-decoration-none p-0 small" @click="expanded = false"><i class="bi bi-chevron-up me-1"></i>Ciutkan</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger text-decoration-none p-0 small" wire:click="$set('tagIds', []); $set('tagLabels', [])">Hapus semua</button>
                                    </div>
                                @endif
                                <div class="d-flex flex-wrap gap-1 p-1 bg-light rounded border" style="max-height: 120px; overflow-y: auto;">
                                    @foreach($tagIds as $id)
                                        <span class="badge rounded-pill bg-info text-dark d-inline-flex align-items-center px-2 py-1" style="max-width: 100%; white-space: normal; text-align: left;">
                                            <i class="bi bi-tag-fill me-1" style="font-size: 0.7rem;"></i>
                                            <span class="text-truncate">{{ $tagLabels[$id] ?? 'ID:'.$id }}</span>
                                            <button type="button" class="btn-close ms-2 flex-shrink-0" style="font-size: 0.5rem" wire:click="removeTag({{ $id }})"></button>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="tagSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            {{-- Sorting Options --}}
            <div class="mb-3">
                <label class="form-label small">Urutkan berdasarkan</label>
                <select wire:model="sortField" class="form-control form-control-sm mb-2">
                    <option value="product_code">Kode Produk</option>
                    <option value="product_name">Nama Produk</option>
                    <option value="sold_quantity">Kuantitas Terjual</option>
                    <option value="return_quantity">Kuantitas Retur</option>
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

    <div class="table-responsive shadow-sm rounded mt-3">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 1200px;">
            <thead class="table-light">
            <tr>
                <th style="white-space:nowrap">Kode Produk</th>
                <th style="white-space:nowrap">Nama Produk</th>
                <th class="text-end" style="white-space:nowrap">Kuantitas Terjual</th>
                <th class="text-end" style="white-space:nowrap">Kuantitas Retur</th>
                <th style="white-space:nowrap">Satuan</th>
                <th class="text-end" style="white-space:nowrap">Total Nilai terjual</th>
                <th class="text-end" style="white-space:nowrap">Total Nilai Retur</th>
                <th class="text-end" style="white-space:nowrap">Harga Penjualan Rata-rata</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($products as $row)
                    <tr>
                        <td>{{ $row->product_code }}</td>
                        <td>{{ $row->product_name }}</td>
                        <td class="text-end">{{ number_format((float)$row->sold_quantity, 2, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$row->return_quantity, 2, ',', '.') }}</td>
                        <td>{{ $row->unit_name }}</td>
                        <td class="text-end">{{ number_format((float)$row->sold_value, 0, ',', '.') }}</td>
                        <td class="text-end text-danger">{{ number_format((float)$row->return_value, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$row->average_sales_value, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data penjualan per produk yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
                @if($products->count() > 0)
                    <tr class="table-secondary fw-bold fs-6">
                        <td colspan="5" class="text-end">Total Keseluruhan</td>
                        <td class="text-end text-primary">{{ number_format((float)$grandTotalSold, 0, ',', '.') }}</td>
                        <td class="text-end text-danger">{{ number_format((float)$grandTotalReturn, 0, ',', '.') }}</td>
                        <td></td>
                    </tr>
                @endif
            @else
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Silakan atur filter dan klik <strong>Filter</strong> untuk menampilkan laporan.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if($filterTriggered && $products instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3">
            {{ $products->links() }}
        </div>
    @endif
</div>
