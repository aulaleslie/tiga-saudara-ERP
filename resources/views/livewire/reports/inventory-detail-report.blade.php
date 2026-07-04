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
            <label class="form-label small">Per Tanggal</label>
            <select wire:model.live="periodPreset" class="form-control">
                <option value="">-- Pilih Preset --</option>
                <option value="today">Hari Ini</option>
                <option value="yesterday">Kemarin</option>
                <option value="this_week">Pekan Ini</option>
                <option value="last_week">Pekan Lalu</option>
                <option value="this_month">Bulan Ini</option>
                <option value="last_month">Bulan Lalu</option>
                <option value="this_quarter">Kuartal Ini</option>
                <option value="last_quarter">Kuartal Lalu</option>
                <option value="this_year">Tahun Ini</option>
                <option value="last_year">Tahun Lalu</option>
            </select>
        </div>
        <div>
            <label class="form-label small">Tanggal Awal</label>
            <input type="date" wire:model.live="tanggalAwal" value="{{ $tanggalAwal }}" class="form-control">
        </div>
        <div>
            <label class="form-label small">Tanggal Akhir</label>
            <input type="date" wire:model.live="tanggalAkhir" value="{{ $tanggalAkhir }}" class="form-control">
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
                <label class="form-label small">Kategori Match Mode</label>
                <select wire:model="categoryMatchMode" class="form-control">
                    <option value="any">Salah Satu (Any)</option>
                    <option value="all">Semua (All)</option>
                </select>
            </div>

            {{-- Kategori multi-select searchable --}}
            <div class="mb-3">
                <label class="form-label small">Kategori</label>
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
                                            wire:click="selectCategory({{ $option['id'] }}, '{{ addslashes($option['category_name']) }}')"
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
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($categoryIds as $id)
                                <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1">
                                    {{ $categoryLabels[$id] ?? 'ID:'.$id }}
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeCategory({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="categorySearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            {{-- Product multi-select searchable --}}
            <div class="mb-3">
                <label class="form-label small">Produk</label>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="productSearch"
                           class="form-control" placeholder="Cari Produk (min 2 karakter)...">
                    @if(strlen($productSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($productOptions as $option)
                                @if(in_array($option['id'], $productIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $option['product_name'] }} <span class="badge bg-secondary ms-1">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click="selectProduct({{ $option['id'] }}, '{{ addslashes($option['product_name']) }}')"
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
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
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($productIds as $id)
                                <span class="badge rounded-pill bg-info text-dark d-inline-flex align-items-center px-2 py-1">
                                    {{ $productLabels[$id] ?? 'ID:'.$id }}
                                    <button type="button" class="btn-close ms-2" style="font-size: 0.5rem" wire:click="removeProduct({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="productSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
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

    <div class="mb-3 text-muted small">
        * Semua perhitungan nilai moneter dalam <strong>(dalam IDR)</strong> kecuali dinyatakan lain.
    </div>

    <div class="table-responsive shadow-sm rounded" wire:loading.class="opacity-50">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 1000px;">
            <thead class="table-light">
                <tr>
                    <th style="white-space:nowrap">Tanggal</th>
                    <th style="white-space:nowrap">Tipe transaksi</th>
                    <th style="white-space:nowrap">No. transaksi</th>
                    <th style="white-space:nowrap">Deskripsi</th>
                    <th class="text-end" style="white-space:nowrap">Mutasi</th>
                    <th class="text-end" style="white-space:nowrap">Stok di gudang</th>
                    <th style="white-space:nowrap">Unit</th>
                </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($paginator as $group)
                    <tr class="table-secondary">
                        <td colspan="7" class="fw-bold">
                            <button wire:click="toggleProduct({{ $group['product_id'] }})" class="btn btn-sm btn-link p-0 me-2 text-decoration-none">
                                <i class="bi {{ in_array($group['product_id'], $expandedProducts) ? 'bi-chevron-up' : 'bi-chevron-down' }} text-dark"></i>
                            </button>
                            @if($group['product_code'])
                                ({{ $group['product_code'] }}) | {{ $group['product_name'] }}
                            @else
                                () | {{ $group['product_name'] }}
                            @endif
                        </td>
                    </tr>
                    
                    {{-- Opening Row & Ledger Rows (Lazy Loaded) --}}
                    @if(in_array($group['product_id'], $expandedProducts))
                        @if(isset($loadedProductDetails[$group['product_id']]))
                            @php $detail = $loadedProductDetails[$group['product_id']]; @endphp
                            
                            {{-- Opening Row --}}
                            <tr>
                                <td>{{ $detail['opening_row']['date'] }}</td>
                                <td>{{ $detail['opening_row']['type_label'] }}</td>
                                <td>{{ $detail['opening_row']['reference'] }}</td>
                                <td>{{ $detail['opening_row']['description'] }}</td>
                                <td class="text-end">{{ $detail['opening_row']['mutation'] }}</td>
                                <td class="text-end fw-bold">{{ number_format((float)$detail['opening_row']['running_stock'], 2, ',', '.') }}</td>
                                <td>{{ $detail['opening_row']['unit'] }}</td>
                            </tr>

                            {{-- Ledger Rows --}}
                            @foreach($detail['ledger_rows'] as $row)
                                <tr>
                                    <td>{{ $row['date'] }}</td>
                                    <td>{{ $row['type_label'] }}</td>
                                    <td>{{ $row['reference'] }}</td>
                                    <td>{{ $row['description'] }}</td>
                                    <td class="text-end">{{ number_format((float)$row['mutation'], 2, ',', '.') }}</td>
                                    <td class="text-end fw-bold">{{ number_format((float)$row['running_stock'], 2, ',', '.') }}</td>
                                    <td>{{ $row['unit'] }}</td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="7" class="text-center py-3">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                                        <span class="sr-only">Loading...</span>
                                    </div>
                                    <span class="ms-2 text-muted">Memuat data pergerakan...</span>
                                </td>
                            </tr>
                        @endif
                    @endif

                    {{-- Subtotal Row --}}
                    <tr class="table-light">
                        <td colspan="5" class="text-end fw-bold">Total Stok di Tangan</td>
                        <td class="text-end fw-bold text-primary">{{ number_format((float)$group['ending_stock'], 2, ',', '.') }}</td>
                        <td>{{ $group['product_unit'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data persediaan yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
            @else
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
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
            <div class="small text-muted">
                Menampilkan seluruh {{ $paginator->total() }} produk
            </div>
            {{ $paginator->links() }}
        </div>
    @endif
</div>
