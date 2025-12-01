<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title></title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* 80mm Thermal Receipt Printer Styles - Portrait Orientation */
        @page {
            size: 72mm auto;
            margin: 0;
            orientation: portrait;
        }

        * {
            font-size: 12px;
            line-height: 16px;
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-weight: 700;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            width: 72mm;
            max-width: 72mm;
            margin: 0 auto;
            padding: 2mm;
            font-weight: 700;
        }

        h2 {
            font-size: 14px;
            font-weight: 700;
        }

        td,
        th,
        tr,
        table {
            border-collapse: collapse;
        }

        tr {border-bottom: 1px dashed #000;}
        td, th {
            padding: 3px 0;
            font-size: 11px;
            font-weight: 700;
        }

        th {
            font-weight: 700;
        }

        table {width: 100%;}
        tfoot tr th:first-child {text-align: left;}

        .centered {
            text-align: center;
            align-content: center;
        }

        small {
            font-size: 10px;
            font-weight: 700;
        }

        .dashed-line {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }

        .bold {
            font-weight: 700;
        }

        p {
            font-weight: 700;
        }

        @media print {
            @page {
                size: 72mm auto;
                margin: 0;
                orientation: portrait;
            }

            html, body {
                width: 72mm;
                max-width: 72mm;
            }

            * {
                font-size: 11px;
                line-height: 14px;
                font-weight: 700;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            td, th {
                padding: 2px 0;
                font-weight: 700;
            }

            th {
                font-weight: 700;
            }

            .hidden-print {
                display: none !important;
            }

            /* Remove default margins for thermal print */
            body {
                margin: 0;
                padding: 2mm;
            }
        }

        @media screen {
            body {
                background: #f0f0f0;
                padding: 10px;
            }

            .receipt-container {
                background: white;
                padding: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
        }
    </style>
</head>
<body>

<div class="receipt-container" style="max-width:72mm;margin:0 auto">
    <div id="receipt-data">
        <div class="centered">
            <h2 style="margin-bottom: 5px">{{ settings()->company_name }}</h2>

            <p style="font-size: 10px;line-height: 14px;margin-top: 0">
                {{ settings()->company_email }}, {{ settings()->company_phone }}
                <br>{{ settings()->company_address }}
            </p>
        </div>
        @php
            $receipt = $receipt ?? null;
            $activeSale = $sale ?? null;
            $reference = $receipt?->receipt_number ?? $activeSale?->reference;
            $customerName = $receipt?->sales->first()?->customer?->contact_name ?? $activeSale?->customer?->contact_name ?? '-';
            $displayDate = $receipt?->created_at ?? ($activeSale?->date);
        @endphp

        <p style="margin-top: 5px;">
            <span class="bold">Tanggal:</span> {{ \Carbon\Carbon::parse($displayDate)->format('d M, Y H:i') }}<br>
            <span class="bold">No. Struk:</span> {{ $reference }}<br>
            <span class="bold">Pelanggan:</span> {{ $customerName }}
        </p>

        @if($receipt)
            <table class="table-data" style="margin-top: 5px; margin-bottom: 5px;">
                <thead>
                    <tr>
                        <th style="text-align:left; width: 30px;">Qty</th>
                        <th style="text-align:left">Nama Barang</th>
                        <th style="text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($receipt->sales as $tenantSale)
                    @foreach($tenantSale->saleDetails as $saleDetail)
                        @php
                            // Calculate unit breakdown
                            $product = $saleDetail->product;
                            $qty = (int) $saleDetail->quantity;
                            $unitBreakdown = [];
                            
                            if ($product && $product->conversions && $product->conversions->isNotEmpty()) {
                                $settingId = $tenantSale->setting_id;
                                $conversions = $product->conversions->sortByDesc('conversion_factor');
                                $remaining = $qty;
                                
                                foreach ($conversions as $conv) {
                                    $factor = (int) $conv->conversion_factor;
                                    if ($factor < 1 || $remaining <= 0) continue;
                                    
                                    $unitCount = intdiv($remaining, $factor);
                                    if ($unitCount <= 0) continue;
                                    
                                    // Get price for this conversion
                                    $price = 0;
                                    if ($settingId && $conv->prices) {
                                        $priceRecord = $conv->prices->where('setting_id', $settingId)->first();
                                        $price = $priceRecord ? (float) $priceRecord->price : (float) $conv->price;
                                    } else {
                                        $price = (float) $conv->price;
                                    }
                                    
                                    if ($price > 0) {
                                        $unitBreakdown[] = [
                                            'count' => $unitCount,
                                            'unit' => $conv->unit->short_name ?? $conv->unit->name ?? 'unit',
                                            'price' => $price,
                                        ];
                                    }
                                    
                                    $remaining -= $unitCount * $factor;
                                }
                                
                                // Handle remainder with base unit - get price from product_prices table
                                if ($remaining > 0) {
                                    $baseUnit = $product->baseUnit;
                                    // Get actual base price from product_prices
                                    $basePrice = 0;
                                    if ($product->relationLoaded('prices') && $product->prices) {
                                        $priceRecord = $product->prices->where('setting_id', $settingId)->first();
                                        $basePrice = $priceRecord ? (float) $priceRecord->sale_price : 0;
                                    }
                                    if ($basePrice <= 0) {
                                        $basePrice = (float) $saleDetail->price; // fallback to sale detail price
                                    }
                                    $unitBreakdown[] = [
                                        'count' => $remaining,
                                        'unit' => $baseUnit->short_name ?? $baseUnit->name ?? 'pcs',
                                        'price' => $basePrice,
                                    ];
                                }
                            }
                            
                            // If no conversion breakdown, show simple format
                            if (empty($unitBreakdown)) {
                                $unitBreakdown[] = [
                                    'count' => $qty,
                                    'unit' => '',
                                    'price' => (float) $saleDetail->price,
                                ];
                            }
                        @endphp
                        <tr>
                            <td style="vertical-align:top">{{ $saleDetail->quantity }}</td>
                            <td>
                                {{ $saleDetail->product->product_name ?? $saleDetail->product_name }}
                                <br>
                                @foreach($unitBreakdown as $segment)
                                    <small>{{ $segment['count'] }} {{ $segment['unit'] }} @ {{ number_format($segment['price'], 0, ',', '.') }}</small>@if(!$loop->last)<br>@endif
                                @endforeach
                            </td>
                            <td style="text-align:right;vertical-align:top">{{ number_format($saleDetail->sub_total, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                @endforeach

                @php
                    $totalTax = $receipt->sales->sum('tax_amount');
                    $totalDiscount = $receipt->sales->sum('discount_amount');
                    $totalShipping = $receipt->sales->sum('shipping_amount');
                @endphp

                @if($totalTax)
                    <tr>
                        <th colspan="2" style="text-align:left">Pajak</th>
                        <th style="text-align:right">{{ number_format($totalTax, 0, ',', '.') }}</th>
                    </tr>
                @endif
                @if($totalDiscount)
                    <tr>
                        <th colspan="2" style="text-align:left">Diskon</th>
                        <th style="text-align:right">{{ number_format($totalDiscount, 0, ',', '.') }}</th>
                    </tr>
                @endif
                @if($totalShipping)
                    <tr>
                        <th colspan="2" style="text-align:left">Ongkir</th>
                        <th style="text-align:right">{{ number_format($totalShipping, 0, ',', '.') }}</th>
                    </tr>
                @endif
                <tr>
                    <th colspan="2" style="text-align:left">Subtotal</th>
                    <th style="text-align:right">{{ number_format($receipt->total_amount, 0, ',', '.') }}</th>
                </tr>
                </tbody>
            </table>

            <table>
                <tbody>
                <tr>
                    <th colspan="2" style="text-align:left">Total</th>
                    <th style="text-align:right">{{ number_format($receipt->total_amount, 0, ',', '.') }}</th>
                </tr>
                <tr style="background-color:#ddd;">
                    <th colspan="2" style="text-align:left; padding: 4px;">
                        <span class="bold">Bayar:</span> {{ $receipt->payment_method }}
                    </th>
                    <th style="text-align:right; padding: 4px;">{{ number_format($receipt->paid_amount, 0, ',', '.') }}</th>
                </tr>
                @if($receipt->change_due > 0)
                    <tr>
                        <th colspan="2" style="text-align:left">Kembalian</th>
                        <th style="text-align:right">{{ number_format($receipt->change_due, 0, ',', '.') }}</th>
                    </tr>
                @endif
                </tbody>
            </table>
        @elseif(isset($sale))
            <table class="table-data" style="margin-top: 5px;">
                <thead>
                    <tr>
                        <th style="text-align:left; width: 25px;">Qty</th>
                        <th style="text-align:left">Nama Barang</th>
                        <th style="text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($sale->saleDetails as $saleDetail)
                    @php
                        // Calculate unit breakdown
                        $product = $saleDetail->product;
                        $qty = (int) $saleDetail->quantity;
                        $unitBreakdown = [];
                        
                        if ($product && $product->conversions && $product->conversions->isNotEmpty()) {
                            $settingId = $sale->setting_id;
                            $conversions = $product->conversions->sortByDesc('conversion_factor');
                            $remaining = $qty;
                            
                            foreach ($conversions as $conv) {
                                $factor = (int) $conv->conversion_factor;
                                if ($factor < 1 || $remaining <= 0) continue;
                                
                                $unitCount = intdiv($remaining, $factor);
                                if ($unitCount <= 0) continue;
                                
                                // Get price for this conversion
                                $price = 0;
                                if ($settingId && $conv->prices) {
                                    $priceRecord = $conv->prices->where('setting_id', $settingId)->first();
                                    $price = $priceRecord ? (float) $priceRecord->price : (float) $conv->price;
                                } else {
                                    $price = (float) $conv->price;
                                }
                                
                                if ($price > 0) {
                                    $unitBreakdown[] = [
                                        'count' => $unitCount,
                                        'unit' => $conv->unit->short_name ?? $conv->unit->name ?? 'unit',
                                        'price' => $price,
                                    ];
                                }
                                
                                $remaining -= $unitCount * $factor;
                            }
                            
                            // Handle remainder with base unit - get price from product_prices table
                            if ($remaining > 0) {
                                $baseUnit = $product->baseUnit;
                                // Get actual base price from product_prices
                                $basePrice = 0;
                                if ($product->relationLoaded('prices') && $product->prices) {
                                    $priceRecord = $product->prices->where('setting_id', $settingId)->first();
                                    $basePrice = $priceRecord ? (float) $priceRecord->sale_price : 0;
                                }
                                if ($basePrice <= 0) {
                                    $basePrice = (float) $saleDetail->price; // fallback to sale detail price
                                }
                                $unitBreakdown[] = [
                                    'count' => $remaining,
                                    'unit' => $baseUnit->short_name ?? $baseUnit->name ?? 'pcs',
                                    'price' => $basePrice,
                                ];
                            }
                        }
                        
                        // If no conversion breakdown, show simple format
                        if (empty($unitBreakdown)) {
                            $unitBreakdown[] = [
                                'count' => $qty,
                                'unit' => '',
                                'price' => (float) $saleDetail->price,
                            ];
                        }
                    @endphp
                    <tr>
                        <td style="vertical-align:top">{{ $saleDetail->quantity }}</td>
                        <td>
                            {{ $saleDetail->product->product_name }}
                            <br>
                            @foreach($unitBreakdown as $segment)
                                <small>{{ $segment['count'] }} {{ $segment['unit'] }} @ {{ number_format($segment['price'], 0, ',', '.') }}</small>@if(!$loop->last)<br>@endif
                            @endforeach
                        </td>
                        <td style="text-align:right;vertical-align:top">{{ number_format($saleDetail->sub_total, 0, ',', '.') }}</td>
                    </tr>
                @endforeach

                @if($sale->tax_percentage)
                    <tr>
                        <th colspan="2" style="text-align:left">Pajak ({{ $sale->tax_percentage }}%)</th>
                        <th style="text-align:right">{{ number_format($sale->tax_amount, 0, ',', '.') }}</th>
                    </tr>
                @endif
                @if($sale->discount_percentage)
                    <tr>
                        <th colspan="2" style="text-align:left">Diskon ({{ $sale->discount_percentage }}%)</th>
                        <th style="text-align:right">{{ number_format($sale->discount_amount, 0, ',', '.') }}</th>
                    </tr>
                @endif
                @if($sale->shipping_amount)
                    <tr>
                        <th colspan="2" style="text-align:left">Ongkir</th>
                        <th style="text-align:right">{{ number_format($sale->shipping_amount, 0, ',', '.') }}</th>
                    </tr>
                @endif
                <tr>
                    <th colspan="2" style="text-align:left">Total</th>
                    <th style="text-align:right">{{ number_format($sale->total_amount, 0, ',', '.') }}</th>
                </tr>
                </tbody>
            </table>
            <table>
                <tbody>
                <tr style="background-color:#ddd;">
                    <th colspan="2" style="text-align:left; padding: 4px;">
                        <span class="bold">Bayar:</span> {{ $sale->payment_method }}
                    </th>
                    <th style="text-align:right; padding: 4px;">{{ number_format($sale->paid_amount, 0, ',', '.') }}</th>
                </tr>
                </tbody>
            </table>
        @endif

        <div class="centered" style="margin-top: 8px;">
            <small style="font-style: italic;">Harga sudah termasuk PPN</small>
        </div>
        <div class="centered" style="margin-top: 5px;">
            <small class="bold">Terima kasih atas kunjungan Anda!</small>
        </div>
        <div style="margin-top: 20px;">&nbsp;</div>
    </div>
</div>

</body>
</html>
