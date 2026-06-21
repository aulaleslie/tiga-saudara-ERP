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
            <label class="form-label small">Per</label>
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

            {{-- Customer multi-select searchable --}}
            <div class="mb-3">
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
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            @foreach($customerIds as $id)
                                <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1">
                                    {{ $customerLabels[$id] ?? 'ID:'.$id }}
                                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem" wire:click="removeCustomer({{ $id }})"></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="position-absolute end-0 top-0 mt-1 me-2 pt-1" wire:loading wire:target="customerSearch">
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
                    <option value="customer_name">Nama Customer</option>
                    <option value="total_balance">Total Saldo</option>
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

    <div class="table-responsive shadow-sm rounded mt-3">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 1000px;">
            <thead class="table-light">
            <tr>
                <th style="white-space:nowrap">Customer</th>
                <th class="text-end" style="white-space:nowrap">Total</th>
                <th class="text-end" style="white-space:nowrap">1 - 30 Hari</th>
                <th class="text-end" style="white-space:nowrap">31 - 60 Hari</th>
                <th class="text-end" style="white-space:nowrap">61 - 90 Hari</th>
                <th class="text-end" style="white-space:nowrap">> 90 Hari</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($sales as $row)
                    @php
                        $mappedRows = \App\Services\Reports\AgedReceivablesReportQueryService::mapRows($row);
                    @endphp
                    <tr>
                        <td class="ps-4">{{ $mappedRows['Pelanggan'] }}</td>
                        <td class="text-end fw-bold">{{ number_format($mappedRows['Total'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($mappedRows['1 - 30 Hari'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($mappedRows['31 - 60 Hari'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($mappedRows['61 - 90 Hari'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($mappedRows['> 90 Hari'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data piutang pelanggan yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
                @if($sales->count() > 0 && $grandTotals)
                    <tr class="table-secondary fw-bold fs-6">
                        <td class="ps-4">Total Piutang (semua pelanggan)</td>
                        <td class="text-end">{{ number_format($grandTotals['Total'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($grandTotals['1 - 30 Hari'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($grandTotals['31 - 60 Hari'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($grandTotals['61 - 90 Hari'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($grandTotals['> 90 Hari'], 0, ',', '.') }}</td>
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

    @if($filterTriggered && $sales instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3">
            {{ $sales->links() }}
        </div>
    @endif
</div>
