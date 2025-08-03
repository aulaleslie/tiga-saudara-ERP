@php use Illuminate\Support\Carbon; @endphp
<div>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
        </div>
        <form class="d-flex" wire:submit.prevent="searchSubmit" style="gap: 0.5rem;">
            <input type="text"
                   class="form-control"
                   placeholder="Cari referensi, pemasok, tag..."
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
            <th wire:click="sortBy('date')" style="cursor:pointer">
                Tanggal {!! $this->sortIcon('date') !!}
            </th>
            <th wire:click="sortBy('supplier_id')" style="cursor:pointer">
                Supplier {!! $this->sortIcon('supplier_id') !!}
            </th>
            <th>Total</th>
            <th>Tags</th>
            <th>Status</th>
            <th>Payment</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($purchases as $purchase)
            <tr>
                <td>
                    <a href="{{ route('purchases.show', $purchase->id) }}"
                       target="_blank" rel="noopener noreferrer">
                        {{ $purchase->reference }}
                    </a>
                </td>
                <td>
                    {{ Carbon::parse($purchase->date)->format('d M Y') }}
                </td>
                <td>{{ $purchase->supplier->supplier_name ?? '-' }}</td>
                <td>{{ format_currency($purchase->total_amount) }}</td>
                <td>
                    @foreach ($purchase->tags as $tag)
                        <span class="badge bg-info text-white fs-6 me-1">
                    {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                </span>
                    @endforeach
                </td>
                <td>@include('purchase::partials.status', ['data' => $purchase])</td>
                <td>@include('purchase::partials.payment-status', ['data' => $purchase])</td>
                <td>@include('purchase::partials.actions', ['data' => $purchase])</td>
            </tr>
        @empty
            <tr>
                <td colspan="8">No data found.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <!-- Pagination controls -->
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small">
            Menampilkan
            <strong>
                {{ $purchases->firstItem() ?? 0 }}-{{ $purchases->lastItem() ?? 0 }}
            </strong>
            dari <strong>{{ $purchases->total() }}</strong> data
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm"
                    wire:click="$set('page', {{ $purchases->currentPage() - 1 }})"
                    @if($purchases->onFirstPage()) disabled @endif>
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="px-2 small">
            <strong>Halaman {{ $purchases->currentPage() }}</strong>
            <span class="text-muted">/ {{ $purchases->lastPage() }}</span>
        </span>
            <button class="btn btn-outline-secondary btn-sm"
                    wire:click="$set('page', {{ $purchases->currentPage() + 1 }})"
                    @if(!$purchases->hasMorePages()) disabled @endif>
                Next <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
