@extends('layouts.app')

@section('title', 'Rekonsiliasi Titipan Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Rekonsiliasi Titipan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-0">Rekonsiliasi Fisik Titipan Konsinyasi</h4>
                        <small class="text-muted">Laporan audit penerimaan dan pembatalan fisik barang titipan supplier.</small>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" action="{{ route('consignments.reconciliation.index') }}" class="row mb-4">
                    <div class="col-md-3 mb-2">
                        @include('consignment::partials.ajax-select', [
                            'name' => 'supplier_id',
                            'url' => route('consignments.select.suppliers'),
                            'selectedId' => request('supplier_id'),
                            'selectedText' => $selectedSupplierText ?? null,
                            'placeholder' => '-- Semua Supplier --',
                        ])
                    </div>
                    <div class="col-md-3 mb-2">
                        <select name="location_id" class="form-control consignment-local-select">
                            <option value="">-- Semua Lokasi Konsinyasi --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        @include('consignment::partials.ajax-select', [
                            'name' => 'product_id',
                            'url' => route('consignments.select.products'),
                            'selectedId' => request('product_id'),
                            'selectedText' => $selectedProductText ?? null,
                            'placeholder' => '-- Semua Produk --',
                        ])
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="status" class="form-control">
                            <option value="">-- Status Fisik --</option>
                            <option value="APPROVED" {{ request('status') === 'APPROVED' ? 'selected' : '' }}>Hanya APPROVED</option>
                            <option value="REVERSED" {{ request('status') === 'REVERSED' ? 'selected' : '' }}>Hanya REVERSED</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="confirmation_status" class="form-control">
                            <option value="">-- Status Konfirmasi --</option>
                            <option value="DRAFT" {{ request('confirmation_status') === 'DRAFT' ? 'selected' : '' }}>DRAFT</option>
                            <option value="WAITING_APPROVAL" {{ request('confirmation_status') === 'WAITING_APPROVAL' ? 'selected' : '' }}>WAITING</option>
                            <option value="APPROVED" {{ request('confirmation_status') === 'APPROVED' ? 'selected' : '' }}>APPROVED</option>
                            <option value="REJECTED" {{ request('confirmation_status') === 'REJECTED' ? 'selected' : '' }}>REJECTED</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="billing_status" class="form-control">
                            <option value="">-- Status Billing --</option>
                            <option value="READY" {{ request('billing_status') === 'READY' ? 'selected' : '' }}>Siap Billing (Ready)</option>
                            <option value="BILLED" {{ request('billing_status') === 'BILLED' ? 'selected' : '' }}>Sudah Billed (Purchase)</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <input type="text" name="serial_number" class="form-control" placeholder="Cari Nomor Seri" value="{{ request('serial_number') }}">
                    </div>
                    <div class="col-md-3 mb-2">
                        <input type="text" name="transaction_reference" class="form-control" placeholder="Cari Referensi Penjualan" value="{{ request('transaction_reference') }}">
                    </div>
                    <div class="col-md-2 mb-2">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                        <a href="{{ route('consignments.reconciliation.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>No. Penerimaan</th>
                                <th>Supplier</th>
                                <th>Lokasi</th>
                                <th>Produk</th>
                                <th>Diterima</th>
                                <th>Biaya DPP</th>
                                <th>Total Nilai</th>
                                <th>Reversed</th>
                                <th>Pending Billed</th>
                                <th>Approved Billed</th>
                                <th>Sisa Pool</th>
                                <th>Purchase / Invoice Reference</th>
                                <th>Status Billing & Saldo</th>
                                <th>Nomor Seri</th>
                                <th>Sumber Penjualan & Blocker</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($details as $d)
                                @php
                                    $isReversed = $d->consignmentReceiving->status === 'REVERSED';
                                    $reversedQty = $isReversed ? $d->quantity_received : 0;
                                    
                                    $approvedAlloc = $d->receiptAllocations->filter(fn($ra) => $ra->line && $ra->line->confirmation && $ra->line->confirmation->isApproved())->sum('allocated_base_quantity');
                                    $pendingRes = $d->receiptAllocations->filter(fn($ra) => $ra->line && $ra->line->confirmation && $ra->line->confirmation->isWaitingApproval())->sum('allocated_base_quantity');
                                    $remainingPool = $isReversed ? 0 : max(0, $d->quantity_received - $approvedAlloc - $pendingRes);
                                    
                                    $sourcesHtml = [];
                                    $blockersHtml = [];
                                    $totalReturned = 0;
                                    
                                    foreach($d->receiptAllocations as $ra) {
                                        if ($ra->line && $ra->line->soldSource) {
                                            $source = $ra->line->soldSource;
                                            $ddId = $source->dispatch_detail_id;
                                            
                                            if ($source->has_reconstruction_blocker) {
                                                $blockersHtml[] = "<span class='text-danger small'><i class='bi bi-exclamation-triangle'></i> ".e($source->blocker_reason)."</span>";
                                            }
                                            
                                            $saleNo = $source->dispatchDetail->sale->checkoutSale->checkout->transaction->code ?? $source->dispatchDetail->sale->posCheckout->transaction->code ?? $source->dispatchDetail->sale->reference ?? 'TRX-'.$ddId;
                                            
                                            $soldQty = $source->original_base_quantity ?? 0;
                                            $returnedQty = $returnedQuantities[$ddId] ?? 0;
                                            $soldLabel = " <span class='text-info ml-1'>(Sold: " . number_format($soldQty, 3) . ")</span>";
                                            $returnLabel = $returnedQty > 0 ? " <span class='text-danger ml-1'>(Ret: " . number_format($returnedQty, 3) . ")</span>" : "";
                                            
                                            $sourcesHtml[$saleNo] = "<span class='badge badge-light border'>".e($saleNo).$soldLabel.$returnLabel."</span>";
                                        }
                                    }
                                @endphp
                                <tr>
                                    <td>{{ $d->consignmentReceiving->date->format('d/m/Y') }}</td>
                                    <td>
                                        <a href="{{ route('consignments.receivings.show', $d->consignment_receiving_id) }}">
                                            {{ $d->consignmentReceiving->receiving_number }}
                                        </a>
                                    </td>
                                    <td>{{ $d->consignmentReceiving->receival->supplier->supplier_name ?? '-' }}</td>
                                    <td>{{ $d->consignmentReceiving->location->name ?? '-' }}</td>
                                    <td>
                                        <div class="font-weight-bold">{{ $d->product->product_name }}</div>
                                        <small class="text-muted">{{ $d->product->product_code }}</small>
                                    </td>
                                    <td class="font-weight-bold">{{ number_format($d->quantity_received, 3) }}</td>
                                    <td>Rp {{ number_format($d->unit_dpp, 2, ',', '.') }}</td>
                                    <td>Rp {{ number_format($d->quantity_received * $d->unit_dpp, 2, ',', '.') }}</td>
                                    <td class="text-danger">{{ number_format($reversedQty, 3) }}</td>
                                    <td class="text-warning">{{ number_format($pendingRes, 3) }}</td>
                                    <td class="text-primary">{{ number_format($approvedAlloc, 3) }}</td>
                                    <td class="font-weight-bold text-success">{{ number_format($remainingPool, 3) }}</td>
                                    <td>
                                        @php
                                            // A receiving detail can be allocated across several confirmations and
                                            // Purchases. Group every billed contribution by Purchase so none is lost,
                                            // preserving allocation-level attribution of the billed quantity.
                                            $billedGroups = [];
                                            $billedAllocQty = 0.0;

                                            foreach ($d->receiptAllocations as $ra) {
                                                $c = $ra->line?->confirmation;
                                                if (!$c || !$c->isBilled() || !$c->purchase) {
                                                    continue;
                                                }

                                                $key = $c->purchase->id;
                                                if (!isset($billedGroups[$key])) {
                                                    $billedGroups[$key] = [
                                                        'purchase' => $c->purchase,
                                                        'invoices' => [],
                                                        'quantity' => 0.0,
                                                    ];
                                                }

                                                if ($c->supplier_invoice_number) {
                                                    $billedGroups[$key]['invoices'][$c->supplier_invoice_number] = true;
                                                }

                                                $billedGroups[$key]['quantity'] += (float) $ra->allocated_base_quantity;
                                                $billedAllocQty += (float) $ra->allocated_base_quantity;
                                            }
                                        @endphp
                                        @forelse($billedGroups as $group)
                                            <div @class(['mb-2' => !$loop->last])>
                                                <a href="{{ route('purchases.show', $group['purchase']->id) }}" class="font-weight-bold">
                                                    {{ $group['purchase']->reference }}
                                                </a>
                                                @if(!empty($group['invoices']))
                                                    <small class="d-block text-muted">Inv: {{ implode(', ', array_keys($group['invoices'])) }}</small>
                                                @endif
                                                <small class="d-block text-muted">Qty: {{ number_format($group['quantity'], 3) }}</small>
                                            </div>
                                        @empty
                                            <span class="text-muted">-</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @php
                                            // Ready-for-billing quantity is what remains approved but not yet billed,
                                            // so a detail carrying both billed and ready allocations shows both.
                                            $readyAlloc = max(0, $approvedAlloc - $billedAllocQty);
                                        @endphp
                                        @if(!empty($billedGroups))
                                            <span class="badge badge-primary">BILLED</span>
                                            @foreach($billedGroups as $group)
                                                @php
                                                    // Canonical live balances from active payments, not stored columns.
                                                    $livePaid = $group['purchase']->getEffectivePaidAmount();
                                                    $liveDue = $group['purchase']->live_due_amount;
                                                @endphp
                                                <div @class(['mt-1', 'border-top pt-1' => !$loop->first])>
                                                    @if(count($billedGroups) > 1)
                                                        <small class="d-block text-muted">{{ $group['purchase']->reference }}</small>
                                                    @endif
                                                    <small class="d-block text-success">Paid: Rp {{ number_format($livePaid, 2, ',', '.') }}</small>
                                                    <small class="d-block text-danger">Due: Rp {{ number_format($liveDue, 2, ',', '.') }}</small>
                                                </div>
                                            @endforeach
                                        @endif
                                        @if($readyAlloc > 0)
                                            <span @class(['badge', 'badge-warning', 'mt-1' => !empty($billedGroups)])>READY FOR BILLING</span>
                                            <small class="d-block text-muted">Qty: {{ number_format($readyAlloc, 3) }}</small>
                                        @elseif(empty($billedGroups))
                                            <span class="badge badge-light">UNBILLED</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($d->serialNumbers->count() > 0)
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach($d->serialNumbers as $sn)
                                                    <span class="badge badge-info mr-1">{{ $sn->serial_number }}</span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(!empty($sourcesHtml))
                                            <div class="d-flex flex-wrap gap-1 mb-1">
                                                {!! implode(' ', $sourcesHtml) !!}
                                            </div>
                                        @endif
                                        @if(!empty($blockersHtml))
                                            <div class="d-flex flex-column gap-1">
                                                {!! implode('', $blockersHtml) !!}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="15" class="text-center text-muted py-4">Tidak ada data rekonsiliasi konsinyasi.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $details->links() }}
                </div>
            </div>
        </div>

        @if($blockers->count() > 0)
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h5 class="card-title text-danger mb-3"><i class="bi bi-exclamation-triangle-fill"></i> Unallocated Sales & Blockers</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Produk</th>
                                <th>Referensi Penjualan</th>
                                <th>Kuantitas Dispatched</th>
                                <th>Alasan Blocker</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($blockers as $blocker)
                            <tr>
                                <td>
                                    <div class="font-weight-bold">{{ $blocker->dispatchDetail->product->product_name ?? '-' }}</div>
                                    <small class="text-muted">{{ $blocker->dispatchDetail->product->product_code ?? '-' }}</small>
                                </td>
                                <td>
                                    @php
                                        $saleNo = $blocker->dispatchDetail->sale->checkoutSale->checkout->transaction->code ?? $blocker->dispatchDetail->sale->posCheckout->transaction->code ?? $blocker->dispatchDetail->sale->reference ?? 'TRX-'.$blocker->dispatch_detail_id;
                                    @endphp
                                    <span class="badge badge-light border">{{ $saleNo }}</span>
                                </td>
                                <td>{{ number_format($blocker->dispatchDetail->dispatched_quantity ?? 0, 3) }}</td>
                                <td class="text-danger small">{{ $blocker->blocker_reason }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

@push('page_scripts')
    @include('consignment::partials.ajax-select-scripts')
    @include('consignment::partials.local-select-scripts')
@endpush
