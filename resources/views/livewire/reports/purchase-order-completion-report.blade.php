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
                <option value="this_quarter">Kuartal Ini</option>
                <option value="this_year">Tahun Ini</option>
                <option value="previous_month">Bulan Sebelumnya</option>
                <option value="previous_year">Tahun Sebelumnya</option>
            </select>
        </div>
        <div>
            <label class="form-label small">Mulai dari Tanggal</label>
            <input type="date" wire:model.live="startDate" value="{{ $startDate }}" class="form-control">
        </div>
        <div>
            <label class="form-label small">Sampai Tanggal</label>
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
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel">Filter laporan</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1;">

            {{-- Source Stages Filter --}}
            <div class="mb-3">
                <label class="form-label small">Mulai dari</label>
                <div class="d-flex flex-column gap-2">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" id="stagePenawaran" 
                               value="Penawaran" 
                               wire:model="sourceStage">
                        <label class="form-check-label" for="stagePenawaran">Penawaran</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" id="stagePemesanan" 
                               value="Pemesanan" 
                               wire:model="sourceStage">
                        <label class="form-check-label" for="stagePemesanan">Pemesanan</label>
                    </div>
                </div>
            </div>

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
                    <option value="any">Salah satu</option>
                    <option value="all">Mencakup semua</option>
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

    <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
        <h5 class="mb-0">Penyelesaian Pemesanan Pembelian</h5>
        <span class="text-muted small">(dalam IDR)</span>
    </div>

    <div class="table-responsive shadow-sm rounded">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.85rem; min-width: 1000px;">
            <thead class="table-light">
            <tr>
                <th wire:click="sortBy('date')" style="cursor:pointer; white-space:nowrap">Tanggal Pemesanan {!! $this->sortIcon('date') !!}</th>
                <th wire:click="sortBy('reference')" style="cursor:pointer; white-space:nowrap">No. Pemesanan {!! $this->sortIcon('reference') !!}</th>
                <th wire:click="sortBy('total_amount')" class="text-end" style="cursor:pointer; white-space:nowrap">Jumlah Pemesanan {!! $this->sortIcon('total_amount') !!}</th>
                <th style="white-space:nowrap">Status Pemesanan</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Pengiriman</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Faktur</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Pembayaran</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($purchases as $purchase)
                    @php
                        $mapped = $mapRow($purchase);
                    @endphp
                    <tr>
                        <td class="ps-3">{{ $mapped['Tanggal Pemesanan'] }}</td>
                        <td>{{ $mapped['No. Pemesanan'] }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Jumlah Pemesanan'], 0, ',', '.') }}</td>
                        <td>{{ $mapped['Status Pemesanan'] }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Jumlah Pengiriman'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Jumlah Faktur'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Jumlah Pembayaran'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
                @if($purchases->count() > 0 && isset($totals))
                    <tr class="table-secondary fw-bold fs-6">
                        <td colspan="2" class="ps-3 text-end">Total</td>
                        <td class="text-end text-primary">{{ number_format((float)$totals->sum_total_amount, 0, ',', '.') }}</td>
                        <td></td>
                        <td class="text-end text-primary">{{ number_format((float)$totals->sum_delivery_amount, 0, ',', '.') }}</td>
                        <td class="text-end text-primary">{{ number_format((float)$totals->sum_invoice_amount, 0, ',', '.') }}</td>
                        <td class="text-end text-primary">{{ number_format((float)$totals->sum_active_paid, 0, ',', '.') }}</td>
                    </tr>
                @endif
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

    @if($filterTriggered && $purchases instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-3">
            {{ $purchases->links() }}
        </div>
    @endif
</div>
