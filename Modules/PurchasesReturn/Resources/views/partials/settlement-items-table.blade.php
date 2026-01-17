@if($purchase_return->settlementItems->isNotEmpty())
<div class="mt-5">
    <div class="d-flex align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-list-check me-2"></i>Penyelesaian Per Item
        </h5>
        @if($purchase_return->settlementItems->every(fn($i) => $i->status === 'APPROVED'))
             <span class="ms-3 badge bg-success text-uppercase">Fully Settled</span>
        @elseif($purchase_return->settlementItems->contains(fn($i) => $i->status === 'APPROVED'))
             <span class="ms-3 badge bg-warning text-dark text-uppercase">Partially Settled</span>
        @endif
    </div>
    
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-3" style="width: 25%;">Produk</th>
                        <th style="width: 15%;">Serial Number</th>
                        <th style="width: 15%;">Metode</th>
                        <th class="text-end" style="width: 15%;">Nominal</th>
                        <th class="text-center" style="width: 15%;">Status</th>
                        <th class="text-center d-print-none" style="width: 15%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchase_return->settlementItems as $item)
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold text-wrap">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                            <small class="text-muted">{{ $item->detail?->product_code ?? '-' }}</small>
                        </td>
                        <td>
                            @if($item->serialNumber)
                                <span class="badge bg-secondary">{{ $item->serialNumber->serial_number }}</span>
                            @else
                                <span class="text-muted small">N/A</span>
                            @endif
                        </td>
                        <td>
                            @if($item->method)
                                <span class="fw-medium text-primary">{{ str_replace('_', ' ', $item->method) }}</span>
                            @else
                                <span class="text-muted small italic">Belum ditentukan</span>
                            @endif
                        </td>
                        <td class="text-end fw-semibold">
                            {{ format_currency($item->getEffectiveNominal()) }}
                        </td>
                        <td class="text-center">
                            @include('purchasesreturn::partials.item-settlement-status', ['item' => $item])
                        </td>
                        <td class="text-center d-print-none">
                            @if($item->status === 'SUBMITTED')
                                @can('purchaseReturnSettlements.approve')
                                <div class="btn-group" role="group">
                                    <form method="POST" action="{{ route('purchase-return-settlements.item.approve', $item->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success border-0" title="Setujui" onclick="return confirm('Setujui item ini?')">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0" title="Tolak" data-bs-toggle="modal" data-bs-target="#rejectItemModal{{ $item->id }}">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                                @else
                                <span class="text-muted small">-</span>
                                @endcan
                            @else
                                <span class="text-muted small">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
