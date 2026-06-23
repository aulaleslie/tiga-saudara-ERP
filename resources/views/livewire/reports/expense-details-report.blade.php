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
            <label class="form-label small">Tanggal Mulai</label>
            <input type="date" wire:model.live="startDate" class="form-control">
        </div>
        <div>
            <label class="form-label small">Tanggal Selesai</label>
            <input type="date" wire:model.live="endDate" class="form-control">
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
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false" wire:loading.attr="disabled" wire:target="exportExcel,exportCsv,exportPdf">
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
                    <li>
                        <button class="dropdown-item" wire:click="exportPdf" wire:loading.attr="disabled" wire:target="exportPdf">
                            <span wire:loading wire:target="exportPdf" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            PDF
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
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel">Filter laporan</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1;">

            <div class="mb-3">
                <label class="form-label small">Kategori Pengeluaran</label>
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

            <div class="mb-3">
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
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($tagIds as $id)
                                <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1">
                                    {{ $tagLabels[$id] ?? 'ID:'.$id }}
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeTag({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="tagSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small">Urutkan berdasarkan</label>
                <select wire:model="sortDirection" class="form-control form-control-sm">
                    <option value="asc">Tanggal Paling Lama</option>
                    <option value="desc">Tanggal Terbaru</option>
                </select>
            </div>

        </div>
        <div class="offcanvas-footer p-3 border-top d-flex gap-2 bg-light">
            <button type="button" wire:click="resetFilters" class="btn btn-outline-secondary w-50">Reset</button>
            <button type="button" @click="isApplying = true; $wire.applyFilters().then(() => showDrawer = false)" class="btn btn-primary w-50">Terapkan Filter</button>
        </div>
    </div>

    <div class="mt-4">
        <div class="d-flex align-items-baseline mb-3">
            <h4 class="mb-0 me-2 text-dark font-weight-bold">Rincian Biaya</h4>
            <span class="text-muted small">(dalam IDR)</span>
        </div>

    @if($filterTriggered)
            <div class="table-responsive bg-white border rounded">
                <table class="table table-hover table-striped mb-0 text-sm">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-bottom font-weight-semibold text-nowrap" style="width: 15%">Kategori / Tanggal</th>
                            <th class="border-bottom font-weight-semibold text-nowrap" style="width: 15%">Transaksi</th>
                            <th class="border-bottom font-weight-semibold text-nowrap" style="width: 15%">Nomor</th>
                            <th class="border-bottom font-weight-semibold" style="width: 35%">Keterangan</th>
                            <th class="border-bottom font-weight-semibold text-end text-nowrap" style="width: 20%">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groupedData as $group)
                            <tr class="bg-light">
                                <td colspan="5" class="font-weight-bold text-dark border-bottom">
                                    {{ $group['category_name'] }}
                                </td>
                            </tr>
                            @foreach($group['rows'] as $row)
                                <tr>
                                    <td class="text-nowrap">{{ $row['Kategori / Tanggal'] }}</td>
                                    <td class="text-nowrap">{{ $row['Transaksi'] }}</td>
                                    <td class="text-nowrap">{{ $row['Nomor'] }}</td>
                                    <td class="text-wrap" style="max-width: 300px; word-break: break-word;">{{ $row['Keterangan'] }}</td>
                                    <td class="text-end text-nowrap">{{ format_currency($row['Jumlah']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-light font-weight-bold">
                                <td colspan="4" class="text-end border-top">Total {{ $group['category_name'] }}</td>
                                <td class="text-end border-top text-nowrap">{{ format_currency($group['subtotal']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 text-light"></i>
                                    Tidak ada data yang sesuai dengan filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($groupedData) > 0)
                        <tfoot class="bg-light font-weight-bold text-dark">
                            <tr>
                                <td colspan="4" class="text-end py-3">Grand Total</td>
                                <td class="text-end py-3 text-nowrap">{{ format_currency($grandTotal) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            @if($paginator && $paginator->hasPages())
                <div class="mt-3">
                    {{ $paginator->links() }}
                </div>
            @endif
            
            @if(count($groupedData) > 0)
                <div class="mt-2 text-muted small">
                    Menampilkan total dari {{ $transactionCount }} baris transaksi
                </div>
            @endif
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i> Silakan sesuaikan filter dan klik <strong>Terapkan Filter</strong> untuk melihat laporan.
        </div>
    @endif
    </div>
</div>
