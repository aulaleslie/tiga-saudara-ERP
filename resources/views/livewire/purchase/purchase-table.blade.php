@php use Illuminate\Support\Carbon; @endphp
<div data-purchase-table-root>
    @if ($globalMode)
    <!-- Global Mode Filters Panel -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="row g-3">
                <!-- Business Filter (Multi-select) -->
                <div class="col-md-4">
                    @include('livewire.reports.business-source-selector', [
                        'selectId' => 'globalPurchaseBusinessFilters',
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
        @if(!$globalMode)
            @can('purchases.archive')
                <div class="d-flex align-items-center" style="gap: 1rem;">
                    <div class="form-check pt-1">
                        <input class="form-check-input" type="checkbox" id="showArchived" wire:model.live="showArchived">
                        <label class="form-check-label" for="showArchived">Tampilkan Arsip</label>
                    </div>
                </div>
            @endcan
        @endif
        <form class="d-flex" wire:submit.prevent="searchSubmit" style="gap: 0.5rem;">
            <input type="text"
                   class="form-control"
                   placeholder="Cari referensi, nomor pembelian supplier, nomor referensi supplier, nomor faktur pajak, pemasok, produk, tag..."
                   wire:model.defer="searchText"
                   style="width: 220px;"
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
                <th wire:click="sortBy('supplier_purchase_number')" style="cursor:pointer">
                    Nomor Pembelian Supplier {!! $this->sortIcon('supplier_purchase_number') !!}
                </th>
                <th wire:click="sortBy('tax_ref_no')" style="cursor:pointer">
                    No. Faktur Pajak {!! $this->sortIcon('tax_ref_no') !!}
                </th>
                <th wire:click="sortBy('date')" style="cursor:pointer">
                    Tanggal {!! $this->sortIcon('date') !!}
                </th>
                <th wire:click="sortBy('due_date')" style="cursor:pointer">
                    Tanggal Jatuh Tempo {!! $this->sortIcon('due_date') !!}
                </th>
                @if ($globalMode)
                <th>Bisnis</th>
                @endif
                @if (!$supplierId)
                    <th wire:click="sortBy('supplier_id')" style="cursor:pointer">
                        Supplier {!! $this->sortIcon('supplier_id') !!}
                    </th>
                @endif
                <th>Total</th>
                <th wire:click="sortBy('due_amount')" style="cursor:pointer">
                    Sisa Pembayaran {!! $this->sortIcon('due_amount') !!}
                </th>
                <th>Tags</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($purchases as $purchase)
                <tr wire:key="{{ $purchase->id }}">
                    <td>
                        @php
                            $productsTooltip = $purchase->purchaseDetails->map(function($detail) {
                                return ($detail->product->product_name ?? $detail->product_name) . ' (Qty: ' . $detail->quantity . ')';
                            })->implode("\n");
                        @endphp
                        <a href="{{ $globalMode ? route('purchases.global-payments.show', $purchase->id) : route('purchases.show', $purchase->id) }}"
                           class="text-primary font-weight-bold purchase-ref-tooltip"
                           data-toggle="tooltip"
                           data-placement="top"
                           title="{{ $productsTooltip }}">
                            {{ $purchase->reference }}
                        </a>
                        @if ($globalMode && filled($purchase->note))
                            <br>
                            <small class="text-muted">{{ $purchase->note }}</small>
                        @endif
                    </td>
                    <td>{{ $purchase->supplier_purchase_number ?? '-' }}</td>
                    <td>{{ $purchase->tax_ref_no ?? '-' }}</td>
                    <td>
                        {{ $this->formatDate($purchase->effective_date) }}
                    </td>
                    <td>
                        {{ $this->formatDate($purchase->due_date) }}
                    </td>
                    @if ($globalMode)
                    <td>
                        {{ $purchase->tenantSetting->company_name ?? '-' }}
                    </td>
                    @endif
                    @if (!$supplierId)
                        <td>{{ $purchase->supplier->supplier_name ?? '-' }}</td>
                    @endif
                    <td>{{ format_currency($purchase->total_amount) }}</td>
                    <td>{{ format_currency($globalMode ? $purchase->live_due_amount : $purchase->due_amount) }}</td>
                    <td>
                        @foreach ($purchase->tags as $tag)
                            <span class="badge badge-info me-1">
                        {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                    </span>
                        @endforeach
                    </td>
                    <td>@include('purchase::partials.status', ['data' => $purchase])</td>
                    <td>@include('purchase::partials.payment-status', ['data' => $purchase])</td>
                    <td>
                        @if($globalMode)
                            @include('purchase::partials.global-payment-actions', ['data' => $purchase, 'tableRefreshId' => $this->tableRefreshId])
                        @else
                            @include('purchase::partials.actions', ['data' => $purchase, 'tableRefreshId' => $this->tableRefreshId])
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $globalMode ? ($supplierId ? 12 : 13) : ($supplierId ? 11 : 12) }}">Tidak ada data yang ditemukan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination controls -->
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <select wire:model.live="perPage" class="custom-select custom-select-sm" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span class="text-muted small">Per halaman</span>
            </div>
            <div class="text-muted small">
                Menampilkan
                <strong>
                    {{ $purchases->firstItem() ?? 0 }}-{{ $purchases->lastItem() ?? 0 }}
                </strong>
                dari <strong>{{ $purchases->total() }}</strong> data
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm"
                    wire:click="gotoPage({{ $purchases->currentPage() - 1 }})"
                    @if($purchases->onFirstPage()) disabled @endif>
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="px-2 small">
            <strong>Halaman {{ $purchases->currentPage() }}</strong>
            <span class="text-muted">/ {{ $purchases->lastPage() }}</span>
        </span>
            <button class="btn btn-outline-secondary btn-sm"
                    wire:click="gotoPage({{ $purchases->currentPage() + 1 }})"
                    @if(!$purchases->hasMorePages()) disabled @endif>
                Next <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
@push('scripts')
<script>
    const reinitializeGlobalPaymentDropdowns = (containerElement) => {
        // Dispose and reinitialize Bootstrap dropdowns specifically for global payment actions
        // Only within the provided container to avoid affecting other components
        containerElement.querySelectorAll('[data-global-payment-action] [data-toggle="dropdown"]').forEach(el => {
            const dropdown = $(el).data('bs.dropdown');
            if (dropdown) {
                dropdown.dispose();
            }
            $(el).dropdown();
        });
    };

    const initializeTooltips = (containerElement) => {
        // Initialize tooltips only within the provided container
        containerElement.querySelectorAll('[data-toggle="tooltip"]').tooltip('dispose').tooltip();
    };

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => {
        initializeTooltips(document);
        reinitializeGlobalPaymentDropdowns(document);
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

            // Only reinitialize if this is the PurchaseTable component (marked with data-purchase-table-root)
            // and the morphed element is within it. This prevents running for summary cards or other components.
            if (detail.el && detail.component.el &&
                detail.component.el.matches('[data-purchase-table-root]') &&
                detail.component.el.contains(detail.el)) {
                // If no refresh is already scheduled for this component, schedule one
                if (!pendingRefreshes.get(componentId)) {
                    pendingRefreshes.set(componentId, true);
                    requestAnimationFrame(() => {
                        // Clear the pending flag and reinitialize
                        pendingRefreshes.set(componentId, false);
                        initializeTooltips(detail.component.el);
                        reinitializeGlobalPaymentDropdowns(detail.component.el);
                    });
                }
            }
        });
    }
</script>
@endpush