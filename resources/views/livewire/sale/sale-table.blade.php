@php use Illuminate\Support\Carbon; @endphp
<div>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center" style="gap: 1rem;">
            <div class="form-check form-switch pt-1">
                <input class="form-check-input" type="checkbox" id="showArchived" wire:model.live="showArchived">
                <label class="form-check-label" for="showArchived">Tampilkan Arsip</label>
            </div>
        </div>
        <form class="d-flex" wire:submit.prevent="searchSubmit" style="gap: 0.5rem;">
            <input type="text"
                   class="form-control"
                   placeholder="Cari referensi, ref import, pelanggan, produk, nomor faktur pajak, tag..."
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
            <th>
                No. POS
            </th>
            <th wire:click="sortBy('date')" style="cursor:pointer">
                Tanggal {!! $this->sortIcon('date') !!}
            </th>
            <th wire:click="sortBy('customer_id')" style="cursor:pointer">
                Pelanggan {!! $this->sortIcon('customer_id') !!}
            </th>
            <th wire:click="sortBy('tax_ref_no')" style="cursor:pointer">
                No. Faktur Pajak {!! $this->sortIcon('tax_ref_no') !!}
            </th>
            <th>Tags</th>
            <th>Total</th>
            <th>Dibayar</th>
            <th>Jatuh Tempo</th>
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
                    <a href="{{ route('sales.show', $sale->id) }}"
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="sale-ref-tooltip"
                       data-toggle="tooltip"
                       data-placement="top"
                       title="{{ $productsTooltip }}">
                        {{ $sale->reference }}
                    </a>
                    @if (!empty($sale->imported_sales_reference_number))
                        <br>
                        <small class="text-muted">{{ $sale->imported_sales_reference_number }}</small>
                    @endif
                    @if (!empty($sale->note))
                        <br>
                        <small class="text-muted">{{ Str::limit($sale->note, 50) }}</small>
                    @endif
                </td>
                <td>
                    {{ $sale->posReceipt?->receipt_number ?? '-' }}
                </td>
                <td>
                    {{ Carbon::parse($sale->date)->format('d M Y') }}
                </td>
                <td>
                    @php
                        $customerName = $sale->customer->contact_name ?: $sale->customer->customer_name ?? '-';
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
                <td>{{ format_currency($sale->paid_amount) }}</td>
                <td>{{ format_currency($sale->due_amount) }}</td>
                <td>@include('sale::partials.status', ['data' => $sale])</td>
                <td>@include('sale::partials.payment-status', ['data' => $sale])</td>
                <td>@include('sale::partials.actions', ['data' => $sale, 'showArchived' => $showArchived])</td>
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
