@php use Illuminate\Support\Carbon; @endphp
<div data-sale-table-root>
    @if ($globalMode)
    <!-- Global Mode Filters Panel -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="row g-3">
                <!-- Business Filter (Multi-select) -->
                <div class="col-md-4">
                    @include('livewire.reports.business-source-selector', [
                        'selectId' => 'globalSalesBusinessFilters',
                        'availableSettings' => $availableSettings,
                        'livewireProperty' => 'draftGlobalBusinessFilters',
                        'selectedValues' => $draftGlobalBusinessFilters,
                        'label' => 'Bisnis',
                        'placeholder' => 'Pilih bisnis (kosongkan untuk semua)'
                    ])
                    <small class="text-muted d-block mt-1">Pilih bisnis (kosongkan untuk semua)</small>
                </div>

                <!-- Document Date Range (Grouped) -->
                <div class="col-md-4">
                    <label class="form-label d-block">Tanggal Dokumen</label>
                    <div class="row g-2">
                        <div class="col">
                            <label class="form-label small">Dari</label>
                            <input type="date" class="form-control" wire:model="draftDocumentDateFrom">
                        </div>
                        <div class="col">
                            <label class="form-label small">Hingga</label>
                            <input type="date" class="form-control" wire:model="draftDocumentDateTo">
                        </div>
                    </div>
                </div>

                <!-- Due Date Range (Grouped) -->
                <div class="col-md-4">
                    <label class="form-label d-block">Tanggal Jatuh Tempo</label>
                    <div class="row g-2">
                        <div class="col">
                            <label class="form-label small">Dari</label>
                            <input type="date" class="form-control" wire:model="draftDueDateFrom">
                        </div>
                        <div class="col">
                            <label class="form-label small">Hingga</label>
                            <input type="date" class="form-control" wire:model="draftDueDateTo">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row g-2 mt-2">
                <div class="col-12">
                    <button type="button" class="btn btn-primary" wire:click="applyGlobalFilters">
                        <i class="bi bi-check2-circle"></i> Terapkan Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary" wire:click="resetGlobalFilters">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset semua filter
                    </button>
                </div>
            </div>

            <!-- Applied Filters Feedback -->
            @if ((!empty($globalBusinessFilters) && count($globalBusinessFilters) > 0) || $documentDateFrom || $documentDateTo || $dueDateFrom || $dueDateTo)
            <div class="row mt-3">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="bi bi-funnel-fill"></i> Filter aktif:
                        @if (!empty($globalBusinessFilters) && count($globalBusinessFilters) > 0)
                            <span class="badge bg-primary">
                                Bisnis: {{ collect($globalBusinessFilters)->map(fn($id) => \Modules\Setting\Entities\Setting::find($id)?->company_name ?? 'N/A')->join(', ') }}
                            </span>
                        @endif
                        @if ($documentDateFrom || $documentDateTo)
                            <span class="badge bg-primary">
                                Tanggal: {{ $documentDateFrom ?? '...' }} s/d {{ $documentDateTo ?? '...' }}
                            </span>
                        @endif
                        @if ($dueDateFrom || $dueDateTo)
                            <span class="badge bg-primary">
                                Jatuh Tempo: {{ $dueDateFrom ?? '...' }} s/d {{ $dueDateTo ?? '...' }}
                            </span>
                        @endif
                    </small>
                </div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center" style="gap: 1rem;">
            @if (!$globalMode)
            <div class="form-check pt-1">
                <input class="form-check-input" type="checkbox" id="showArchived" wire:model.live="showArchived">
                <label class="form-check-label" for="showArchived">Tampilkan Arsip</label>
            </div>
            @endif
        </div>
        <form class="d-flex" wire:submit.prevent="searchSubmit" style="gap: 0.5rem;">
            <input type="text"
                   class="form-control"
                   placeholder="Cari referensi, POS, ref import, pelanggan, produk (nama/kode), nomor faktur pajak, tag..."
                   wire:model.defer="searchText"
                   style="width: 300px;"
                   autocomplete="off"
            >
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i>
            </button>
            @if ($search)
                <button type="button" wire:click="clearSearch" class="btn btn-secondary">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
        </form>
    </div>

    <div class="table-responsive global-payment-table-scroll">
        <table class="table table-bordered table-hover align-middle">
            <thead>
            <tr>
                <th wire:click="sortBy('reference')" style="cursor:pointer">
                    Ref {!! $this->sortIcon('reference') !!}
                </th>
                <th>POS</th>
                <th wire:click="sortBy('date')" style="cursor:pointer">
                    Tanggal {!! $this->sortIcon('date') !!}
                </th>
                @if ($globalMode)
                <th>Bisnis</th>
                @endif
                <th wire:click="sortBy('customer_id')" style="cursor:pointer">
                    Pelanggan {!! $this->sortIcon('customer_id') !!}
                </th>
                <th wire:click="sortBy('tax_ref_no')" style="cursor:pointer">
                    No. Faktur Pajak {!! $this->sortIcon('tax_ref_no') !!}
                </th>
                <th>Tags</th>
                <th>Total</th>
                <th>Dibayar</th>
                <th>Sisa Tagihan</th>
                <th>Tanggal Jatuh Tempo</th>
                <th>Status</th>
                <th>Status Pembayaran</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($sales as $sale)
                <tr>
                    <td>
                        @php
                            $productsTooltip = $sale->saleDetails->map(function($detail) {
                                return ($detail->product_name ?? '-') . ' (Qty: ' . $detail->quantity . ')';
                            })->implode("\n");
                        @endphp
                        @if($globalMode || auth()->user()->can('sales.show'))
                            <a href="{{ $globalMode
                                ? route('sales.global-payments.show', $sale->id)
                                : route('sales.show', $sale->id) }}"
                               class="text-primary font-weight-bold sale-ref-tooltip"
                               data-toggle="tooltip"
                               data-placement="top"
                               title="{{ $productsTooltip }}">
                                {{ $sale->reference }}
                            </a>
                        @else
                            <span class="font-weight-bold sale-ref-tooltip"
                                  data-toggle="tooltip"
                                  data-placement="top"
                                  title="{{ $productsTooltip }}">
                                {{ $sale->reference }}
                            </span>
                        @endif
                        @if (!empty($sale->imported_sales_reference_number))
                            <br>
                            <small class="text-muted">{{ $sale->imported_sales_reference_number }}</small>
                        @endif
                        @if ($globalMode && filled($sale->note))
                            <br>
                            <small class="text-muted">{{ $sale->note }}</small>
                        @endif
                    </td>
                    <td>
                        @php
                            $posCode = '-';
                            if ($sale->posCheckout && $sale->posCheckout->transaction) {
                                $posCode = $sale->posCheckout->transaction->code;
                            } elseif ($sale->checkoutSale && $sale->checkoutSale->checkout && $sale->checkoutSale->checkout->transaction) {
                                $posCode = $sale->checkoutSale->checkout->transaction->code;
                            }
                        @endphp
                        @if ($posCode !== '-')
                            <span class="badge bg-info text-dark">{{ $posCode }}</span>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>
                        {{ Carbon::parse($sale->effective_date)->format('d M Y') }}
                    </td>
                    @if ($globalMode)
                    <td>
                        {{ $sale->tenantSetting->company_name ?? '-' }}
                    </td>
                    @endif
                    <td>
                        @php
                            $customerName = $sale->customer->canonical_name;
                        @endphp
                        {{ $customerName }}
                    </td>
                    <td>{{ $sale->tax_ref_no ?? '-' }}</td>
                    <td>
                        @foreach ($sale->tags as $tag)
                            <span class="badge badge-secondary">
                            {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                            </span>
                        @endforeach
                        @if ($sale->tags->isEmpty())
                            -
                        @endif
                    </td>
                    <td>{{ format_currency($sale->total_amount) }}</td>
                    <td>
                        @if ($globalMode)
                            {{ format_currency($sale->live_paid_amount) }}
                        @else
                            {{ format_currency($sale->paid_amount) }}
                        @endif
                    </td>
                    <td>
                        @if ($globalMode)
                            {{ format_currency($sale->live_due_amount) }}
                        @else
                            {{ format_currency($sale->due_amount) }}
                        @endif
                    </td>
                    <td>
                        {{ $sale->payment_due_date ? \Carbon\Carbon::parse($sale->payment_due_date)->format('d M Y') : '-' }}
                    </td>
                    <td>@include('sale::partials.status', ['data' => $sale])</td>
                    <td>@include('sale::partials.payment-status', ['data' => $sale])</td>
                    <td>@include('sale::partials.actions', ['data' => $sale, 'showArchived' => $showArchived, 'globalMode' => $this->globalMode])</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12">Tidak ada data yang ditemukan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination controls -->
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small">
            Menampilkan
            <strong>
                {{ $sales->firstItem() ?? 0 }}-{{ $sales->lastItem() ?? 0 }}
            </strong>
            dari <strong>{{ $sales->total() }}</strong> data
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm"
                    wire:click="gotoPage({{ $sales->currentPage() - 1 }})"
                    @if($sales->onFirstPage()) disabled @endif>
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="px-2 small">
            <strong>Halaman {{ $sales->currentPage() }}</strong>
            <span class="text-muted">/ {{ $sales->lastPage() }}</span>
        </span>
            <button class="btn btn-outline-secondary btn-sm"
                    wire:click="gotoPage({{ $sales->currentPage() + 1 }})"
                    @if(!$sales->hasMorePages()) disabled @endif>
                Next <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
@push('scripts')
<script>
    const initializeTooltips = (containerElement) => {
        // Initialize tooltips only within the provided container
        containerElement.querySelectorAll('[data-toggle="tooltip"]').tooltip('dispose').tooltip();
    };

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => {
        initializeTooltips(document);
    });

    // Reinitialize after elements have been morphed by Livewire
    // morph.updated fires for every updated DOM element, so we coalesce repeated calls
    // with a pending flag and requestAnimationFrame to run once per table update
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        // Track pending refreshes per component to avoid reinitializing unrelated components
        const pendingRefreshes = new Map();

        window.Livewire.hook('morph.updated', (detail) => {
            // Identify this component by its Livewire ID
            const componentId = detail.component.id;

            // Only reinitialize if this is the SaleTable component (marked with data-sale-table-root)
            // and the morphed element is within it. This prevents running for any other component.
            if (detail.el && detail.component.el &&
                detail.component.el.matches('[data-sale-table-root]') &&
                detail.component.el.contains(detail.el)) {
                // If no refresh is already scheduled for this component, schedule one
                if (!pendingRefreshes.get(componentId)) {
                    pendingRefreshes.set(componentId, true);
                    requestAnimationFrame(() => {
                        // Clear the pending flag and reinitialize
                        pendingRefreshes.set(componentId, false);
                        initializeTooltips(detail.component.el);
                    });
                }
            }
        });
    }
</script>
@endpush
