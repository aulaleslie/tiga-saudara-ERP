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
            <input type="date" wire:model="startDate" class="form-control">
        </div>
        <div>
            <label class="form-label small">Tanggal akhir</label>
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
                <button class="btn btn-outline-success dropdown-toggle" type="button" data-coreui-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Ekspor
                </button>
                <ul class="dropdown-menu">
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
                    <option value="transaction_date">Tanggal Transaksi</option>
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
                        <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($supplierOptions as $option)
                                <button type="button"
                                        wire:click="selectSupplier({{ $option['id'] }}, '{{ addslashes($option['supplier_name']) }}')"
                                        class="list-group-item list-group-item-action small py-2">
                                    {{ $option['supplier_name'] }}
                                </button>
                            @empty
                                <div class="list-group-item disabled small py-3 text-center text-muted">
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
                        <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1060; max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;">
                            @forelse($tagOptions as $option)
                                @php
                                    $locale = app()->getLocale();
                                    $nameData = is_string($option['name']) ? json_decode($option['name'], true) : $option['name'];
                                    $tagName = $nameData[$locale] ?? ($nameData['en'] ?? (is_array($nameData) ? reset($nameData) : $nameData));
                                @endphp
                                <button type="button"
                                        wire:click="selectTag({{ $option['id'] }}, '{{ addslashes($tagName) }}')"
                                        class="list-group-item list-group-item-action small py-2">
                                    {{ $tagName }}
                                </button>
                            @empty
                                <div class="list-group-item disabled small py-3 text-center text-muted">
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

    <div class="offcanvas-backdrop fade show" style="z-index: 1040;" x-show="showDrawer" @click="showDrawer = false"></div>

    <div class="table-responsive shadow-sm rounded">
        <table class="table table-hover table-bordered mb-0" style="font-size: 0.8rem; min-width: 2800px;">
            <thead class="table-light">
            <tr>
                <th wire:click="sortBy('date')" style="cursor:pointer; white-space:nowrap">Tanggal {!! $this->sortIcon('date') !!}</th>
                <th wire:click="sortBy('reference')" style="cursor:pointer; white-space:nowrap">Nomor Transaksi {!! $this->sortIcon('reference') !!}</th>
                <th wire:click="sortBy('supplier_purchase_number')" style="cursor:pointer; white-space:nowrap">Nomor Pembelian Supplier {!! $this->sortIcon('supplier_purchase_number') !!}</th>
                <th style="white-space:nowrap">Nama Panggilan</th>
                <th style="white-space:nowrap">Status Dokumen</th>
                <th style="white-space:nowrap">Status Pembayaran</th>
                <th style="white-space:nowrap">Memo</th>
                <th class="text-end" style="white-space:nowrap">Total</th>
                <th class="text-end" style="white-space:nowrap">Sisa Tagihan</th>
                <th style="white-space:nowrap">Tanggal Jatuh Tempo</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Kena Pajak</th>
                <th class="text-end" style="white-space:nowrap">Total Pajak</th>
                <th class="text-end" style="white-space:nowrap">Pembayaran</th>
                <th style="white-space:nowrap">Email</th>
                <th style="white-space:nowrap">Alamat Penagihan</th>
                <th style="white-space:nowrap">Alamat Pengiriman</th>
                <th style="white-space:nowrap">No Ref</th>
                <th style="white-space:nowrap">Tag</th>
                <th style="white-space:nowrap">Gudang</th>
                <th style="white-space:nowrap">Nama Produk</th>
                <th style="white-space:nowrap">Kode Produk</th>
                <th style="white-space:nowrap">Deskripsi</th>
                <th class="text-end" style="white-space:nowrap">Kuantitas</th>
                <th style="white-space:nowrap">Satuan</th>
                <th class="text-end" style="white-space:nowrap">Harga per Unit</th>
                <th class="text-end" style="white-space:nowrap">Diskon Per Baris %</th>
                <th style="white-space:nowrap">Tarif Pajak</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Pajak</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Kena Pajak per Baris</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Per Baris</th>
                <th class="text-end" style="white-space:nowrap">Diskon</th>
                <th style="white-space:nowrap">Pesan</th>
                <th class="text-end" style="white-space:nowrap">Biaya Pengiriman</th>
                <th class="text-end" style="white-space:nowrap">Jumlah Pemotongan</th>
                <th style="white-space:nowrap">Nama Perusahaan</th>
                <th style="white-space:nowrap">Nomor Pajak</th>
                <th style="white-space:nowrap">Nomor Ponsel</th>
                <th style="white-space:nowrap">Nomor Telepon</th>
                <th class="text-end" style="white-space:nowrap">Sisa Tagihan Hari Ini</th>
                <th class="text-end" style="white-space:nowrap">Diskon %</th>
            </tr>
            </thead>
            <tbody class="bg-white text-dark">
            @if($filterTriggered)
                @forelse($purchases as $row)
                    @php
                        $purchase = $row->purchase;
                        $supplier = $purchase?->supplier;
                        $tax      = $row->tax;
                        $locale   = app()->getLocale();

                        $activePaid  = (float) ($row->derived_active_paid ?? 0);
                        $totalAmount = (float) ($purchase?->total_amount ?? 0);
                        if ($activePaid <= 0) {
                            $payStatusLabel = 'Belum Dibayar';
                            $payStatusClass = 'bg-danger';
                        } elseif ($totalAmount > 0 && $activePaid >= $totalAmount) {
                            $payStatusLabel = 'Lunas';
                            $payStatusClass = 'bg-success';
                        } else {
                            $payStatusLabel = 'Terbayar Sebagian';
                            $payStatusClass = 'bg-warning text-dark';
                        }

                        $tagNames = $purchase?->tags->map(function ($tag) use ($locale) {
                            $nameData = is_array($tag->name) ? $tag->name : (json_decode($tag->name, true) ?? []);
                            return $nameData[$locale] ?? ($nameData['en'] ?? (is_array($nameData) ? reset($nameData) : $tag->name));
                        })->implode(', ') ?? '-';
                    @endphp
                    <tr>
                        <td>{{ $purchase?->date ? date('d/m/Y', strtotime($purchase->date)) : '-' }}</td>
                        <td>
                            @can('purchases.show')
                                @if($purchase)
                                    <a href="{{ route('purchases.show', $purchase->id) }}" class="text-primary fw-bold">
                                        {{ $purchase->reference ?? '-' }}
                                    </a>
                                @else
                                    -
                                @endif
                            @else
                                <strong>{{ $purchase?->reference ?? '-' }}</strong>
                            @endcan
                        </td>
                        <td>{{ $purchase?->supplier_purchase_number ?? '-' }}</td>
                        <td>{{ $supplier?->supplier_name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $documentStatusLabels[$purchase?->status] ?? ($purchase?->status ?? '-') }}
                            </span>
                        </td>
                        <td><span class="badge {{ $payStatusClass }}">{{ $payStatusLabel }}</span></td>
                        <td>{{ $purchase?->note ?? '-' }}</td>
                        <td class="text-end fw-bold text-primary">{{ number_format($totalAmount, 0, ',', '.') }}</td>
                        <td class="text-end text-danger">{{ number_format(max(0, $totalAmount - $activePaid), 0, ',', '.') }}</td>
                        <td>{{ $purchase?->due_date ? date('d/m/Y', strtotime($purchase->due_date)) : '-' }}</td>
                        <td class="text-end">{{ number_format($purchase?->tax_amount ?? 0, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($purchase?->tax_amount ?? 0, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($activePaid, 0, ',', '.') }}</td>
                        <td>{{ $supplier?->supplier_email ?? '-' }}</td>
                        <td>{{ $supplier?->billing_address ?? $supplier?->address ?? '-' }}</td>
                        <td>{{ $supplier?->shipping_address ?? $supplier?->address ?? '-' }}</td>
                        <td>{{ $purchase?->reference ?? '-' }}</td>
                        <td>{{ $tagNames }}</td>
                        <td>{{ $row->gudang ?? '-' }}</td>
                        <td>{{ $row->product_name ?? '-' }}</td>
                        <td>{{ $row->product_code ?? '-' }}</td>
                        <td>{{ $row->product?->description ?? '-' }}</td>
                        <td class="text-end">{{ number_format($row->quantity ?? 0, 2, ',', '.') }}</td>
                        <td>{{ $row->product?->unit ?? '-' }}</td>
                        <td class="text-end">{{ number_format($row->unit_price ?? 0, 0, ',', '.') }}</td>
                        <td class="text-end">
                            {{ $row->product_discount_type === 'percentage' ? number_format($row->product_discount_amount ?? 0, 2, ',', '.') . '%' : '-' }}
                        </td>
                        <td>{{ $tax?->tax_percentage ? $tax->tax_percentage . '%' : '-' }}</td>
                        <td class="text-end">{{ number_format($row->product_tax_amount ?? 0, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format(($row->sub_total ?? 0) + ($row->product_tax_amount ?? 0), 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($row->sub_total ?? 0, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($row->product_discount_amount ?? 0, 0, ',', '.') }}</td>
                        <td>{{ $purchase?->note ?? '-' }}</td>
                        <td class="text-end">{{ number_format($purchase?->shipping_amount ?? 0, 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($purchase?->discount_amount ?? 0, 0, ',', '.') }}</td>
                        <td>{{ $supplier?->supplier_name ?? '-' }}</td>
                        <td>{{ $supplier?->npwp ?? '-' }}</td>
                        <td>{{ $supplier?->supplier_phone ?? '-' }}</td>
                        <td>{{ $supplier?->fax ?? '-' }}</td>
                        <td class="text-end text-danger">{{ number_format(max(0, $totalAmount - $activePaid), 0, ',', '.') }}</td>
                        <td class="text-end">
                            {{ $totalAmount > 0 && ($purchase?->discount_amount ?? 0) > 0
                                ? number_format($purchase->discount_amount / $totalAmount * 100, 2, ',', '.') . '%'
                                : '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="40" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Tidak ada data pembelian yang sesuai dengan filter ini.
                        </td>
                    </tr>
                @endforelse
            @else
                <tr>
                    <td colspan="40" class="text-center py-5 text-muted">
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
