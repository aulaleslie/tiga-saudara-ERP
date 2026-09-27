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
            <label class="form-label small">Mode Laporan</label>
            <select wire:model="reportMode" class="form-control">
                <option value="detail">Detail</option>
                <option value="header">Header</option>
            </select>
        </div>
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
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu">
                    <li><button class="dropdown-item" wire:click="exportExcel">Excel</button></li>
                    <li><button class="dropdown-item" wire:click="exportCsv">CSV</button></li>
                    <li><button class="dropdown-item" disabled>PDF</button></li>
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

            {{-- Tanggal berdasarkan --}}
            <div class="mb-3">
                <label class="form-label small">Tanggal berdasarkan</label>
                <select wire:model="dateBasis" class="form-control">
                    <option value="transaction_date">Tanggal Pelaporan (Transaksi atau Override)</option>
                    <option value="due_date">Tanggal Jatuh Tempo</option>
                </select>
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
                                            wire:click="selectSupplier({{ $option['id'] }}, '{{ addslashes($option['supplier_name']) }}')"
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

            {{-- Status Dokumen multi-select toggle buttons --}}
            <div class="mb-3">
                <label class="form-label small">Status Dokumen</label>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($documentStatusLabels as $key => $label)
                        <button type="button"
                                wire:click="toggleDocumentStatus('{{ $key }}')"
                                class="btn btn-sm {{ in_array($key, $documentStatuses) ? 'btn-primary' : 'btn-outline-secondary' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @if(count($documentStatuses) > 0)
                    <small class="text-muted mt-1 d-block">{{ count($documentStatuses) }} dipilih</small>
                @endif
            </div>

            {{-- Status Pembayaran multi-select toggle buttons --}}
            <div class="mb-3">
                <label class="form-label small">Status Pembayaran</label>
                <div class="d-flex flex-wrap gap-1">
                    @foreach($paymentStatusLabels as $key => $label)
                        <button type="button"
                                wire:click="togglePaymentStatus('{{ $key }}')"
                                class="btn btn-sm {{ in_array($key, $paymentStatuses) ? 'btn-success' : 'btn-outline-secondary' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @if(count($paymentStatuses) > 0)
                    <small class="text-muted mt-1 d-block">{{ count($paymentStatuses) }} dipilih</small>
                @endif
            </div>

            {{-- Grup dengan tag multi-select searchable --}}
            <div class="mb-3">
                <label class="form-label small">Grup dengan tag</label>
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
                                            wire:click="selectTag({{ $option['id'] }}, '{{ addslashes($tagName) }}')"
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
                        <!-- Spacer to prevent the absolute dropdown from being clipped by the offcanvas body's overflow-y boundary -->
                        <div style="height: 260px;" class="d-block w-100 pointer-events-none"></div>
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

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false"></div>

    @php
        $isHeaderMode = $tableReportMode === 'header';
        $emptyColspan = $isHeaderMode ? 15 : 40;
    @endphp

    <div class="table-responsive shadow-sm rounded" wire:loading.class="opacity-50">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: {{ $isHeaderMode ? '1600px' : '2800px' }};">
            <thead class="table-light">
            @if($isHeaderMode)
                <tr>
                    <th wire:click="sortBy('date')" style="cursor:pointer; white-space:nowrap">Tanggal {!! $this->sortIcon('date') !!}</th>
                    <th wire:click="sortBy('reference')" style="cursor:pointer; white-space:nowrap">Nomor Transaksi {!! $this->sortIcon('reference') !!}</th>
                    <th wire:click="sortBy('supplier_purchase_number')" style="cursor:pointer; white-space:nowrap">Nomor Pembelian Supplier {!! $this->sortIcon('supplier_purchase_number') !!}</th>
                    <th wire:click="sortBy('supplier_name')" style="cursor:pointer; white-space:nowrap">Nama Panggilan {!! $this->sortIcon('supplier_name') !!}</th>
                    <th wire:click="sortBy('status')" style="cursor:pointer; white-space:nowrap">Status Dokumen {!! $this->sortIcon('status') !!}</th>
                    <th wire:click="sortBy('payment_status')" style="cursor:pointer; white-space:nowrap">Status Pembayaran {!! $this->sortIcon('payment_status') !!}</th>
                    <th style="white-space:nowrap">Memo</th>
                    <th wire:click="sortBy('total_amount')" class="text-end" style="cursor:pointer; white-space:nowrap">Total {!! $this->sortIcon('total_amount') !!}</th>
                    <th class="text-end" style="white-space:nowrap">Sisa Tagihan</th>
                    <th wire:click="sortBy('due_date')" style="cursor:pointer; white-space:nowrap">Tanggal Jatuh Tempo {!! $this->sortIcon('due_date') !!}</th>
                    <th class="text-end" style="white-space:nowrap">Jumlah Kena Pajak</th>
                    <th class="text-end" style="white-space:nowrap">Total Pajak</th>
                    <th class="text-end" style="white-space:nowrap">Pembayaran</th>
                    <th style="white-space:nowrap">No Ref</th>
                    <th style="white-space:nowrap">Tag</th>
                </tr>
            @else
                <tr>
                    <th wire:click="sortBy('date')" style="cursor:pointer; white-space:nowrap">Tanggal {!! $this->sortIcon('date') !!}</th>
                    <th wire:click="sortBy('reference')" style="cursor:pointer; white-space:nowrap">Nomor Transaksi {!! $this->sortIcon('reference') !!}</th>
                    <th wire:click="sortBy('supplier_purchase_number')" style="cursor:pointer; white-space:nowrap">Nomor Pembelian Supplier {!! $this->sortIcon('supplier_purchase_number') !!}</th>
                    <th wire:click="sortBy('supplier_name')" style="cursor:pointer; white-space:nowrap">Nama Panggilan {!! $this->sortIcon('supplier_name') !!}</th>
                    <th wire:click="sortBy('status')" style="cursor:pointer; white-space:nowrap">Status Dokumen {!! $this->sortIcon('status') !!}</th>
                    <th wire:click="sortBy('payment_status')" style="cursor:pointer; white-space:nowrap">Status Pembayaran {!! $this->sortIcon('payment_status') !!}</th>
                    <th style="white-space:nowrap">Memo</th>
                    <th wire:click="sortBy('total_amount')" class="text-end" style="cursor:pointer; white-space:nowrap">Total {!! $this->sortIcon('total_amount') !!}</th>
                    <th class="text-end" style="white-space:nowrap">Sisa Tagihan</th>
                    <th wire:click="sortBy('due_date')" style="cursor:pointer; white-space:nowrap">Tanggal Jatuh Tempo {!! $this->sortIcon('due_date') !!}</th>
                    <th class="text-end" style="white-space:nowrap">Jumlah Kena Pajak</th>
                    <th class="text-end" style="white-space:nowrap">Total Pajak</th>
                    <th class="text-end" style="white-space:nowrap">Pembayaran</th>
                    <th style="white-space:nowrap">Email</th>
                    <th style="white-space:nowrap">Alamat Penagihan</th>
                    <th style="white-space:nowrap">Alamat Pengiriman</th>
                    <th style="white-space:nowrap">No Ref</th>
                    <th style="white-space:nowrap">Tag</th>
                    <th style="white-space:nowrap">Gudang</th>
                    <th wire:click="sortBy('product_name')" style="cursor:pointer; white-space:nowrap">Nama Produk {!! $this->sortIcon('product_name') !!}</th>
                    <th wire:click="sortBy('product_code')" style="cursor:pointer; white-space:nowrap">Kode Produk {!! $this->sortIcon('product_code') !!}</th>
                    <th style="white-space:nowrap">Deskripsi</th>
                    <th class="text-end" style="white-space:nowrap">Kuantitas</th>
                    <th style="white-space:nowrap">Satuan</th>
                    <th class="text-end" style="white-space:nowrap">Harga per Unit</th>
                    <th style="white-space:nowrap">Tarif Pajak</th>
                    <th class="text-end" style="white-space:nowrap">Jumlah Pajak</th>
                    <th class="text-end" style="white-space:nowrap">Jumlah Kena Pajak per Baris</th>
                    <th class="text-end" style="white-space:nowrap">Jumlah Per Baris</th>
                    <th class="text-end" style="white-space:nowrap">Diskon</th>
                    <th style="white-space:nowrap">Pesan</th>
                    <th class="text-end" style="white-space:nowrap">Biaya Pengiriman</th>
                    <th style="white-space:nowrap">Nama Perusahaan</th>
                    <th style="white-space:nowrap">Nomor Pajak</th>
                    <th style="white-space:nowrap">Nomor Ponsel</th>
                    <th style="white-space:nowrap">Nomor Telepon</th>
                    <th class="text-end" style="white-space:nowrap">Sisa Tagihan Hari Ini</th>
                    <th class="text-end" style="white-space:nowrap">Diskon %</th>
                </tr>
            @endif
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($purchases as $row)
                    @php
                        $mapped = \App\Services\Reports\PurchaseReportQueryService::mapRow($row, $tableReportMode);
                        $purchase = $tableReportMode === 'header' ? $row : $row->purchase;

                        if ($mapped['Status Pembayaran'] === 'Belum Dibayar') {
                            $payStatusClass = 'bg-danger';
                        } elseif ($mapped['Status Pembayaran'] === 'Lunas') {
                            $payStatusClass = 'bg-success';
                        } else {
                            $payStatusClass = 'bg-warning text-dark';
                        }
                    @endphp
                    <tr>
                        <td>{{ $mapped['Tanggal'] }}</td>
                        <td>
                            @can('purchases.show')
                                @if($purchase)
                                    <a href="{{ route('purchases.show', $purchase->id) }}" class="text-primary fw-bold">
                                        {{ $mapped['Nomor Transaksi'] }}
                                    </a>
                                @else
                                    -
                                @endif
                            @else
                                <strong>{{ $mapped['Nomor Transaksi'] }}</strong>
                            @endcan
                        </td>
                        <td>{{ $mapped['Nomor Pembelian Supplier'] }}</td>
                        <td>{{ $mapped['Nama Panggilan'] }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $mapped['Status Dokumen'] }}
                            </span>
                        </td>
                        <td><span class="badge {{ $payStatusClass }}">{{ $mapped['Status Pembayaran'] }}</span></td>
                        <td>{{ $mapped['Memo'] }}</td>
                        <td class="text-end fw-bold text-primary">{{ number_format((float)$mapped['Total'], 0, ',', '.') }}</td>
                        <td class="text-end text-danger">{{ number_format((float)$mapped['Sisa Tagihan'], 0, ',', '.') }}</td>
                        <td>{{ $mapped['Tanggal Jatuh Tempo'] }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Jumlah Kena Pajak'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Total Pajak'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format((float)$mapped['Pembayaran'], 0, ',', '.') }}</td>
                        @if($isHeaderMode)
                            <td>{{ $mapped['No Ref'] }}</td>
                            <td>{{ $mapped['Tag'] }}</td>
                        @else
                            <td>{{ $mapped['Email'] }}</td>
                            <td>{{ $mapped['Alamat Penagihan'] }}</td>
                            <td>{{ $mapped['Alamat Pengiriman'] }}</td>
                            <td>{{ $mapped['No Ref'] }}</td>
                            <td>{{ $mapped['Tag'] }}</td>
                            <td>{{ $mapped['Gudang'] }}</td>
                            <td>{{ $mapped['Nama Produk'] }}</td>
                            <td>{{ $mapped['Kode Produk'] }}</td>
                            <td>{{ $mapped['Deskripsi'] }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Kuantitas'], 2, ',', '.') }}</td>
                            <td>{{ $mapped['Satuan'] }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Harga per Unit'], 0, ',', '.') }}</td>
                            <td>{{ $mapped['Tarif Pajak'] !== '-' ? $mapped['Tarif Pajak'] . '%' : '-' }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Jumlah Pajak'], 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Jumlah Kena Pajak per Baris'], 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Jumlah Per Baris'], 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Diskon'], 0, ',', '.') }}</td>
                            <td>{{ $mapped['Pesan'] }}</td>
                            <td class="text-end">{{ number_format((float)$mapped['Biaya Pengiriman'], 0, ',', '.') }}</td>
                            <td>{{ $mapped['Nama Perusahaan'] }}</td>
                            <td>{{ $mapped['Nomor Pajak'] }}</td>
                            <td>{{ $mapped['Nomor Ponsel'] }}</td>
                            <td>{{ $mapped['Nomor Telepon'] }}</td>
                            <td class="text-end text-danger">{{ number_format((float)$mapped['Sisa Tagihan Hari Ini'], 0, ',', '.') }}</td>
                            <td class="text-end">
                                {{ (float)$mapped['Diskon %'] > 0 ? number_format((float)$mapped['Diskon %'], 2, ',', '.') . '%' : '-' }}
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $emptyColspan }}" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data pembelian yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
            @else
                <tr>
                    <td colspan="{{ $emptyColspan }}" class="text-center py-5 text-muted">
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
