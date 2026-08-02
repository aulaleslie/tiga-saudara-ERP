@php use Illuminate\Support\Carbon; @endphp
<div>
    @if ($globalMode)
    <!-- Global Mode Filters Panel -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="row g-3">
                <!-- Business Filter -->
                <div class="col-md-4">
                    <label class="form-label">Bisnis</label>
                    <select class="custom-select" wire:model="draftGlobalBusinessFilter">
                        <option value="">-- Semua Bisnis --</option>
                        @forelse (\Modules\Setting\Entities\Setting::all() as $setting)
                            <option value="{{ $setting->id }}">{{ $setting->company_name }}</option>
                        @empty
                        @endforelse
                    </select>
                </div>

                <!-- Document Date Range (Grouped) -->
                <div class="col-md-6">
                    <label class="form-label d-block">Tanggal Dokumen</label>
                    <div class="row g-2">
                        <div class="col">
                            <input type="date" class="form-control" wire:model="draftDocumentDateFrom" placeholder="Dari">
                        </div>
                        <div class="col">
                            <input type="date" class="form-control" wire:model="draftDocumentDateTo" placeholder="Hingga">
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="col-md-2 d-flex gap-2 align-items-end">
                    <button type="button" class="btn btn-primary flex-grow-1" wire:click="applyGlobalFilters">
                        <i class="bi bi-check2-circle"></i> Terapkan Filter
                    </button>
                    <button type="button" class="btn btn-secondary" wire:click="resetGlobalFilters" title="Reset semua filter">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>
            </div>

            <!-- Applied Filters Feedback -->
            @if ($globalBusinessFilter || $documentDateFrom || $documentDateTo)
            <div class="row mt-3">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="bi bi-funnel-fill"></i> Filter aktif:
                        @if ($globalBusinessFilter)
                            <span class="badge bg-primary">
                                Bisnis: {{ \Modules\Setting\Entities\Setting::find($globalBusinessFilter)?->company_name ?? 'N/A' }}
                            </span>
                        @endif
                        @if ($documentDateFrom || $documentDateTo)
                            <span class="badge bg-primary">
                                Tanggal: {{ $documentDateFrom ?? '...' }} s/d {{ $documentDateTo ?? '...' }}
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
                    {{ Carbon::parse($sale->date)->format('d M Y') }}
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
    document.addEventListener('livewire:updated', () => {
        $('[data-toggle="tooltip"]').tooltip('dispose').tooltip();
    });

    document.addEventListener('DOMContentLoaded', () => {
        $('[data-toggle="tooltip"]').tooltip();
    });
</script>
@endpush
