@php use Illuminate\Support\Carbon; @endphp
<div>
    @if ($globalMode)
    <!-- Global Mode Filters -->
    <div class="mb-3 p-3 bg-light border rounded">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Bisnis</label>
                <select class="form-select" wire:model.live="globalBusinessFilter">
                    <option value="">-- Semua Bisnis --</option>
                    @forelse (\Modules\Setting\Entities\Setting::all() as $setting)
                        <option value="{{ $setting->id }}">{{ $setting->company_name }}</option>
                    @empty
                    @endforelse
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" class="form-control" wire:model.live="documentDateFrom">
            </div>
            <div class="col-md-3">
                <label class="form-label">Hingga Tanggal</label>
                <input type="date" class="form-control" wire:model.live="documentDateTo">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="button" class="btn btn-secondary w-100" wire:click="clearGlobalFilters">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </button>
            </div>
        </div>
    </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-2">
        @if(!$globalMode)
            @can('purchases.archive')
                <div class="d-flex align-items-center" style="gap: 1rem;">
                    <div class="form-check form-switch pt-1">
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
                       class="text-primary font-weight-bold"
                       class="purchase-ref-tooltip"
                       data-toggle="tooltip"
                       data-placement="top"
                       title="{{ $productsTooltip }}">
                        {{ $purchase->reference }}
                    </a>
                </td>
                <td>{{ $purchase->supplier_purchase_number ?? '-' }}</td>
                <td>{{ $purchase->tax_ref_no ?? '-' }}</td>
                <td>
                    {{ $this->formatDate($purchase->date) }}
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
                        <span class="badge bg-info text-white fs-6 me-1">
                    {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                </span>
                    @endforeach
                </td>
                <td>@include('purchase::partials.status', ['data' => $purchase])</td>
                <td>@include('purchase::partials.payment-status', ['data' => $purchase])</td>
                <td>
                    @if($globalMode)
                        @include('purchase::partials.global-payment-actions', ['data' => $purchase])
                    @else
                        @include('purchase::partials.actions', ['data' => $purchase])
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

    <!-- Pagination controls -->
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <select wire:model.live="perPage" class="form-select form-select-sm" style="width: auto;">
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
    document.addEventListener('livewire:updated', () => {
        $('[data-toggle="tooltip"]').tooltip('dispose').tooltip();
    });

    document.addEventListener('DOMContentLoaded', () => {
        $('[data-toggle="tooltip"]').tooltip();
    });
</script>
@endpush