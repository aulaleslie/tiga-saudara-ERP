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

            {{-- Supplier multi-select searchable --}}
            <div class="mb-3">
                <label class="form-label small">Supplier</label>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="supplierSearch"
                           class="form-control" placeholder="Cari Supplier (min 2 karakter)...">
                    @if(strlen($supplierSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1 bg-white text-dark" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($supplierOptions as $option)
                                @if(in_array($option['id'], $supplierIds))
                                    <button type="button"
                                            class="list-group-item list-group-item-action bg-light text-muted small py-2" disabled>
                                        {{ $option['supplier_name'] }} <span class="badge bg-secondary ms-1">Sudah dipilih</span>
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click='selectSupplier({{ $option['id'] }}, @json($option['supplier_name']))'
                                            class="list-group-item list-group-item-action bg-white text-dark small py-2">
                                        {{ $option['supplier_name'] }}
                                    </button>
                                @endif
                            @empty
                                <div class="list-group-item bg-white disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada supplier ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($supplierIds) > 0)
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($supplierIds as $id)
                                <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1">
                                    {{ $supplierLabels[$id] ?? 'ID:'.$id }}
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeSupplier({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="supplierSearch">
                        <div class="spinner-border spinner-border-sm text-primary" style="width: 0.8rem; height: 0.8rem;" role="status"></div>
                    </div>
                </div>
            </div>

            {{-- Grup dengan tag multi-select searchable --}}
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
                                <span class="badge rounded-pill bg-info text-dark d-inline-flex align-items-center px-2 py-1">
                                    <i class="bi bi-tag-fill me-1" style="font-size: 0.7rem;"></i>
                                    {{ $tagLabels[$id] ?? 'ID:'.$id }}
                                    <button type="button" class="btn-close ms-2" style="font-size: 0.5rem" wire:click="removeTag({{ $id }})"></button>
                                </span>
                            @endforeach
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
                    <option value="date">Tanggal</option>
                    <option value="amount">Jumlah</option>
                    <option value="status">Status</option>
                    <option value="outstanding">Sisa Tagihan</option>
                </select>
                <select wire:model="sortDirection" class="form-control form-control-sm">
                    <option value="asc">Ascending (A-Z / Terkecil)</option>
                    <option value="desc">Descending (Z-A / Terbesar)</option>
                </select>
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

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false" x-cloak></div>

    {{-- Detail Mode Toggle --}}
    @if($filterTriggered)
    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="detailModeToggle" wire:click="toggleDetailMode" @if($detailMode) checked @endif>
            <label class="form-check-label" for="detailModeToggle">Perlihatkan Lebih Detail</label>
        </div>
    </div>
    @endif

    <div class="table-responsive shadow-sm rounded mt-3">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 1200px;">
            <thead class="table-light">
            <tr>
                <th style="white-space:nowrap">Tanggal</th>
                <th style="white-space:nowrap">Transaksi</th>
                <th style="white-space:nowrap">Nomor</th>
                <th style="white-space:nowrap">Kategori</th>
                <th style="white-space:nowrap">Deskripsi</th>
                <th style="white-space:nowrap">Supplier</th>
                <th class="text-end" style="white-space:nowrap">Jumlah</th>
                <th class="text-end" style="white-space:nowrap">Tax</th>
                <th style="white-space:nowrap">Status</th>
                <th class="text-end" style="white-space:nowrap">Sisa Tagihan</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($expenses as $expense)
                    @if($detailMode)
                        @php $detailRows = \App\Services\Reports\ExpenseListReportQueryService::mapDetailRows($expense); @endphp
                        @foreach($detailRows as $row)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($row['Tanggal'])->format('d/m/Y') }}</td>
                                <td>{{ $row['Transaksi'] }}</td>
                                <td><strong>{{ $row['Nomor'] }}</strong></td>
                                <td>{{ $row['Kategori'] }}</td>
                                <td>{{ $row['Deskripsi'] }}</td>
                                <td>{{ $row['Supplier'] }}</td>
                                <td class="text-end">{{ number_format($row['Jumlah'], 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($row['Tax'], 0, ',', '.') }}</td>
                                <td>{{ $row['Status'] }}</td>
                                <td class="text-end">{{ number_format($row['Sisa Tagihan'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @else
                        @php $row = \App\Services\Reports\ExpenseListReportQueryService::mapSummaryRow($expense); @endphp
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($row['Tanggal'])->format('d/m/Y') }}</td>
                            <td>{{ $row['Transaksi'] }}</td>
                            <td><strong>{{ $row['Nomor'] }}</strong></td>
                            <td>{{ $row['Kategori'] }}</td>
                            <td>{{ $row['Deskripsi'] }}</td>
                            <td>{{ $row['Supplier'] }}</td>
                            <td class="text-end">{{ number_format($row['Jumlah'], 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row['Tax'], 0, ',', '.') }}</td>
                            <td>{{ $row['Status'] }}</td>
                            <td class="text-end">{{ number_format($row['Sisa Tagihan'], 0, ',', '.') }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data pengeluaran yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
                @if($expenses->count() > 0)
                    <tr class="table-secondary fw-bold fs-6">
                        <td colspan="6" class="ps-4">Total Biaya</td>
                        <td class="text-end">{{ number_format((float)$grandTotals['Jumlah'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$grandTotals['Tax'], 0, ',', '.') }}</td>
                        <td></td>
                        <td class="text-end">{{ number_format((float)$grandTotals['Sisa Tagihan'], 0, ',', '.') }}</td>
                    </tr>
                @endif
            @else
                <tr>
                    <td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Silakan atur filter dan klik <strong>Filter</strong> untuk menampilkan laporan.
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if($filterTriggered && $expenses instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3">
            {{ $expenses->links() }}
        </div>
    @endif
</div>
