<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Purchase Return Details</title>
    <link rel="stylesheet" href="{{ public_path('b3/bootstrap.min.css') }}">
</head>
<body>
@php
    // Load settlement items for status derivation
    $items = $purchase_return->relationLoaded('settlementItems') 
        ? $purchase_return->settlementItems 
        : $purchase_return->settlementItems()->get();

    $allApproved = $items->isNotEmpty() && $items->every(fn($i) => strtoupper($i->status) === 'APPROVED');
    $anyApproved = $items->contains(fn($i) => strtoupper($i->status) === 'APPROVED');
    $anySubmitted = $items->contains(fn($i) => strtoupper($i->status) === 'SUBMITTED');
    $approvalStatus = strtolower($purchase_return->approval_status ?? '');

    // Derive settlement label
    if ($allApproved) {
        $settlementLabel = 'Selesai';
        $methodLabels = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::settlementMethods();
        $methods = $items->pluck('method')->unique()->filter()->map(fn($m) => $methodLabels[$m] ?? $m)->implode(', ');
        $settlementDetail = $methods ? 'Metode: ' . $methods : null;
    } elseif ($anyApproved) {
        $settlementLabel = 'Selesai Sebagian';
        $approvedCount = $items->filter(fn($i) => strtoupper($i->status) === 'APPROVED')->count();
        $settlementDetail = $approvedCount . ' dari ' . $items->count() . ' item disetujui';
    } elseif ($approvalStatus === 'rejected') {
        $settlementLabel = 'Ditolak';
        $settlementDetail = $purchase_return->rejection_reason ? 'Alasan: ' . $purchase_return->rejection_reason : null;
    } elseif ($anySubmitted) {
        $settlementLabel = 'Menunggu Persetujuan Item';
        $settlementDetail = null;
    } elseif ($purchase_return->status === 'Awaiting Settlement' || ($approvalStatus === 'approved' && $items->isEmpty())) {
        $settlementLabel = 'Menunggu Penyelesaian';
        $settlementDetail = null;
    } elseif ($approvalStatus !== 'approved') {
        $settlementLabel = 'Menunggu Persetujuan';
        $settlementDetail = null;
    } else {
        $settlementLabel = 'Belum Diproses';
        $settlementDetail = null;
    }
@endphp

<div class="container-fluid">
    <div class="row">
        <div class="col-xs-12">
            <div style="text-align: center;margin-bottom: 25px;">
                <img width="180" src="{{ public_path('images/logo-dark.png') }}" alt="Logo">
                <h4 style="margin-bottom: 20px;">
                    <span>Reference::</span> <strong>{{ $purchase_return->reference }}</strong>
                </h4>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-xs-4 mb-3 mb-md-0">
                            <h4 class="mb-2" style="border-bottom: 1px solid #dddddd;padding-bottom: 10px;">Company Info:</h4>
                            <div><strong>{{ settings()->company_name }}</strong></div>
                            <div>{{ settings()->company_address }}</div>
                            <div>Email: {{ settings()->company_email }}</div>
                            <div>Phone: {{ settings()->company_phone }}</div>
                        </div>

                        <div class="col-xs-4 mb-3 mb-md-0">
                            <h4 class="mb-2" style="border-bottom: 1px solid #dddddd;padding-bottom: 10px;">Supplier Info:</h4>
                            <div><strong>{{ $supplier->supplier_name }}</strong></div>
                            <div>{{ $supplier->address }}</div>
                            <div>Email: {{ $supplier->supplier_email }}</div>
                            <div>Phone: {{ $supplier->supplier_phone }}</div>
                        </div>

                        <div class="col-xs-4 mb-3 mb-md-0">
                            <h4 class="mb-2" style="border-bottom: 1px solid #dddddd;padding-bottom: 10px;">Invoice Info:</h4>
                            <div>Invoice: <strong>INV/{{ $purchase_return->reference }}</strong></div>
                            <div>Date: {{ \Carbon\Carbon::parse($purchase_return->date)->format('d M, Y') }}</div>
                            <div>
                                Status: <strong>{{ $purchase_return->status }}</strong>
                            </div>
                            <div>
                                Status Penyelesaian: <strong>{{ $settlementLabel }}</strong>
                                @if($settlementDetail)
                                    <div style="font-size: 12px; color: #6c757d;">{{ $settlementDetail }}</div>
                                @endif
                            </div>
                        </div>

                    </div>

                    <div class="table-responsive-sm" style="margin-top: 30px;">
                        @can('purchaseReturns.viewPrice')
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th class="align-middle">Product</th>
                                    <th class="align-middle">Net Unit Price</th>
                                    <th class="align-middle">Quantity</th>
                                    <th class="align-middle">Discount</th>
                                    <th class="align-middle">Tax</th>
                                    <th class="align-middle">Sub Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($purchase_return->purchaseReturnDetails as $item)
                                    <tr>
                                        <td class="align-middle">
                                            {{ $item->product_name }} <br>
                                            <span class="badge badge-success">
                                                    {{ $item->product_code }}
                                                </span>
                                        </td>

                                        <td class="align-middle">{{ format_currency($item->unit_price) }}</td>

                                        <td class="align-middle">
                                            {{ $item->quantity }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->product_discount_amount) }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->product_tax_amount) }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->sub_total) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @else
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th class="align-middle">Product</th>
                                    <th class="align-middle">Quantity</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($purchase_return->purchaseReturnDetails as $item)
                                    <tr>
                                        <td class="align-middle">
                                            {{ $item->product_name }} <br>
                                            <span class="badge badge-success">
                                                    {{ $item->product_code }}
                                                </span>
                                        </td>

                                        <td class="align-middle">
                                            {{ $item->quantity }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endcan
                    </div>

                    {{-- Per-Item Settlement Details --}}
                    @if($items->isNotEmpty())
                    <div class="table-responsive-sm" style="margin-top: 30px;">
                        <h4 class="mb-2" style="border-bottom: 1px solid #dddddd;padding-bottom: 10px;">Penyelesaian Per Item:</h4>
                        <table class="table table-striped">
                            <thead>
                            <tr>
                                <th class="align-middle">Produk</th>
                                <th class="align-middle">Serial Number</th>
                                <th class="align-middle">Metode</th>
                                @can('purchaseReturns.viewPrice')
                                <th class="align-middle">Nominal</th>
                                @endcan
                                <th class="align-middle">Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($items as $settlementItem)
                                <tr>
                                    <td class="align-middle">
                                        {{ $settlementItem->detail?->product_name ?? 'N/A' }} <br>
                                        <span class="badge badge-success">{{ $settlementItem->detail?->product_code ?? '-' }}</span>
                                    </td>
                                    <td class="align-middle">
                                        @if($settlementItem->serialNumber)
                                            {{ $settlementItem->serialNumber->serial_number }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        @php
                                            $methodLabels = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::settlementMethods();
                                        @endphp
                                        @if($settlementItem->method)
                                            @php 
                                                $methodKey = strtoupper(str_replace(' ', '_', trim($settlementItem->method))); 
                                                $isPurchaseLinked = in_array($methodKey, ['MODIFY_PURCHASE', 'CREDIT', 'CASH'], true);
                                                $targetPurchase = $settlementItem->targetPurchase;
                                            @endphp
                                            {{ $methodLabels[$settlementItem->method] ?? $settlementItem->method }}
                                            @if($isPurchaseLinked && $targetPurchase)
                                                <div style="font-size: 10px; color: #6c757d; margin-top: 2px;">
                                                    <div>Ref: {{ $targetPurchase->reference }}</div>
                                                    <div>Supplier Ref: {{ $targetPurchase->supplier_purchase_number ?: '-' }}</div>
                                                </div>
                                            @endif
                                        @else
                                            <em>Belum ditentukan</em>
                                        @endif
                                    </td>
                                    @can('purchaseReturns.viewPrice')
                                    <td class="align-middle">{{ format_currency($settlementItem->getEffectiveNominal()) }}</td>
                                    @endcan
                                    <td class="align-middle">
                                        @switch(strtoupper($settlementItem->status))
                                            @case('DRAFT')
                                                Draft
                                                @break
                                            @case('SUBMITTED')
                                                Menunggu Persetujuan
                                                @break
                                            @case('APPROVED')
                                                Disetujui
                                                @break
                                            @case('REJECTED')
                                                Ditolak
                                                @break
                                            @default
                                                {{ $settlementItem->status }}
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif

                    @can('purchaseReturns.viewPrice')
                        <div class="row">
                            <div class="col-xs-4 col-xs-offset-8">
                                <table class="table">
                                    <tbody>
                                    <tr>
                                        <td class="left"><strong>Discount ({{ $purchase_return->discount_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($purchase_return->discount_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Tax ({{ $purchase_return->tax_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($purchase_return->tax_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Shipping)</strong></td>
                                        <td class="right">{{ format_currency($purchase_return->shipping_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Grand Total</strong></td>
                                        <td class="right"><strong>{{ format_currency($purchase_return->total_amount) }}</strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endcan
                    <div class="row" style="margin-top: 25px;">
                        <div class="col-xs-12">
                            <p style="font-style: italic;text-align: center">{{ settings()->company_name }} &copy; {{ date('Y') }}.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
