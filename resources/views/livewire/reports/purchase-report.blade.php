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
            <label class="form-label small">Tanggal Mulai</label>
            <input type="date" wire:model="startDate" class="form-control">
        </div>
        <div>
            <label class="form-label small">Tanggal Akhir</label>
            <input type="date" wire:model="endDate" class="form-control">
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
                <button class="btn btn-outline-success dropdown-toggle" type="button" id="exportDropdown" data-coreui-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                    <li><button class="dropdown-item" disabled>Excel</button></li>
                    <li><button class="dropdown-item" disabled>CSV</button></li>
                    <li><button class="dropdown-item" disabled>PDF</button></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right-side Drawer for "Filter lainnya" -->
    <div class="offcanvas offcanvas-end" tabindex="-1" :class="showDrawer ? 'show' : ''" 
         x-show="showDrawer" x-transition 
         x-on:keydown.escape.window="showDrawer = false"
         style="visibility: visible; z-index: 1050; background: white; position: fixed; top: 0; right: 0; height: 100vh; width: 400px; box-shadow: -5px 0 15px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
         aria-labelledby="filterDrawerLabel" x-cloak>
        <div class="offcanvas-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="offcanvas-title mb-0" id="filterDrawerLabel">Filter laporan</h5>
            <button type="button" class="btn-close text-reset" @click="showDrawer = false"></button>
        </div>
        <div class="offcanvas-body p-3" style="overflow-y: auto; flex-grow: 1;">
            <div class="mb-3">
                <label class="form-label small">Tanggal berdasarkan</label>
                <select wire:model="dateBasis" class="form-control">
                    <option value="transaction_date">Tanggal Transaksi</option>
                    <option value="due_date">Tanggal Jatuh Tempo</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small">Tipe transaksi</label>
                <select wire:model="transactionType" class="form-control">
                    <option value="purchase_invoice">Faktur Pembelian</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small">Supplier</label>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="supplierSearch" 
                           class="form-control" placeholder="Cari Supplier (min 2 karakter)...">
                    @if(strlen($supplierSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($supplierOptions as $option)
                                <button type="button" wire:click="selectSupplier({{ $option['id'] }})" 
                                        class="list-group-item list-group-item-action small py-2 d-flex justify-content-between align-items-center">
                                    <span>{{ $option['supplier_name'] }}</span>
                                    <small class="text-muted">ID: {{ $option['id'] }}</small>
                                </button>
                            @empty
                                <div class="list-group-item disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada supplier ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($supplierIds) > 0)
                        <div class="mt-1 d-flex flex-wrap gap-1">
                            @foreach($supplierIds as $id)
                                <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1 shadow-sm">
                                    <i class="bi bi-check-circle-fill me-1" style="font-size: 0.7rem;"></i>
                                    ID: {{ $id }}
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
            <div class="mb-3">
                <label class="form-label small">Status Pengiriman</label>
                <select wire:model="deliveryStatus" class="form-control">
                    <option value="">-- Semua --</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small">Status Pembayaran</label>
                <select wire:model="paymentStatus" class="form-control">
                    <option value="">-- Semua --</option>
                    <option value="PAID">Lunas</option>
                    <option value="UNPAID">Belum Dibayar</option>
                    <option value="PARTIAL">Sebagian</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small">Pajak</label>
                <select wire:model="withTax" class="form-control">
                    <option value="">-- Semua --</option>
                    <option value="1">Dengan Pajak</option>
                    <option value="0">Tanpa Pajak</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small">Tag</label>
                <div class="position-relative">
                    <input type="text" wire:model.live.debounce.300ms="tagSearch" 
                           class="form-control" placeholder="Cari Tag (min 2 karakter)...">
                    @if(strlen($tagSearch) >= 2)
                        <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($tagOptions as $option)
                                @php 
                                    $locale = app()->getLocale();
                                    $nameData = is_string($option['name']) ? json_decode($option['name'], true) : $option['name'];
                                    $tagName = $nameData[$locale] ?? ($nameData['en'] ?? (is_array($nameData) ? reset($nameData) : $nameData));
                                @endphp
                                <button type="button" wire:click="selectTag({{ $option['id'] }})" 
                                        class="list-group-item list-group-item-action small py-2 d-flex justify-content-between align-items-center">
                                    <span>{{ $tagName }}</span>
                                    <small class="text-muted">ID: {{ $option['id'] }}</small>
                                </button>
                            @empty
                                <div class="list-group-item disabled small py-3 text-center text-muted">
                                    <i class="bi bi-search me-1"></i> Tidak ada tag ditemukan
                                </div>
                            @endforelse
                        </div>
                    @endif
                    @if(count($tagIds) > 0)
                        <div class="mt-1 d-flex flex-wrap gap-1">
                            @foreach($tagIds as $id)
                                <span class="badge rounded-pill bg-primary d-inline-flex align-items-center px-2 py-1 shadow-sm">
                                    <i class="bi bi-tag-fill me-1" style="font-size: 0.7rem;"></i>
                                    ID: {{ $id }}
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

    <div class="table-responsive shadow-sm rounded">
        <table class="table table-hover table-bordered mb-0">
            <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>No. Referensi</th>
                <th>Pemasok</th>
                <th>Status</th>
                <th>Status Pembayaran</th>
                <th class="text-end">Total</th>
                <th class="text-end">Pajak</th>
                <th class="text-end">Sisa Tagihan</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($purchases as $p)
                    <tr>
                        <td>{{ date('d/m/Y', strtotime($p->date)) }}</td>
                        <td><span class="fw-bold">{{ $p->reference }}</span></td>
                        <td>{{ $p->supplier->supplier_name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $statuses[$p->status] ?? $p->status }}
                            </span>
                        </td>
                        <td>
                            @if($p->payment_status === 'Paid' || strtoupper($p->payment_status) === 'PAID')
                                <span class="badge bg-success">Lunas</span>
                            @elseif($p->payment_status === 'Partial' || strtoupper($p->payment_status) === 'PARTIAL')
                                <span class="badge bg-warning text-dark">Sebagian</span>
                            @else
                                <span class="badge bg-danger">Belum Dibayar</span>
                            @endif
                        </td>
                        <td class="text-end fw-bold text-primary">{{ number_format($p->total_amount, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($p->tax_amount, 0, ',', '.') }}</td>
                        <td class="text-end text-danger">{{ number_format($p->due_amount, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data pembelian yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
            @else
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                        Silakan atur filter dan klik <strong>Tampilkan Laporan</strong>.
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
