<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pembayaran {{ $receiptData['receipt_number'] }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm auto;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            margin: 0;
            padding: 10px;
            width: 80mm;
            color: #000;
        }
        h2 { text-transform: uppercase; font-size: 14px; text-align: center; margin: 0 0 5px 0; }
        p { margin: 2px 0; text-align: center; }
        .divider { border-top: 1px dashed #000; margin: 10px 0; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 2px 0; font-weight: normal; }
        .item-row td { padding-top: 5px; }
        .item-name { font-weight: bold; }
        .unit-breakdown { font-size: 11px; padding-left: 10px; color: #333; }
        
        .totals-table { width: 100%; }
        .totals-table td { padding: 2px 0; }
        .totals-label { text-align: left; }
        .totals-value { text-align: right; font-weight: bold; }
        
        .footer { text-align: center; margin-top: 15px; font-style: italic; }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="/** window.print(); **/">
    
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer;">Cetak Struk</button>
    </div>

    <div class="header">
        <!-- Task 2.1: Center aligned business header -->
        <h2>{{ $receiptData['business_name'] }}</h2>
        @if(!empty($receiptData['business_address']))
            <p>{{ $receiptData['business_address'] }}</p>
        @endif
        @if(!empty($receiptData['business_phone']))
            <p>Telp: {{ $receiptData['business_phone'] }}</p>
        @endif
        @if(!empty($receiptData['business_email']))
            <p>Email: {{ $receiptData['business_email'] }}</p>
        @endif
        
        <div class="divider"></div>
        
        <table style="width: 100%;">
            <tr>
                <td class="text-left" style="width: 20%;">No</td>
                <td class="text-left">: {{ $receiptData['receipt_number'] }}</td>
            </tr>
            <tr>
                <td class="text-left">Tgl</td>
                <!-- Task 2.2: Consistent date formatting -->
                @php
                    $finalizedAt = Carbon\Carbon::parse($receiptData['date']);
                @endphp
                <td class="text-left">: {{ $finalizedAt->translatedFormat('d M, Y H:i') }}</td>
            </tr>
            <tr>
                <td class="text-left">Kasir</td>
                <td class="text-left">: {{ $receiptData['cashier_name'] }}</td>
            </tr>
            <tr>
                <td class="text-left">Term</td>
                <td class="text-left">: {{ $receiptData['terminal_name'] }}</td>
            </tr>
        </table>
        
        <div class="divider"></div>
    </div>

    <div class="items">
        <table>
            <!-- Task 2.3: Redesigned item table header -->
            <thead>
                <tr>
                    <th class="text-left" style="width: 15%;">Qty</th>
                    <th class="text-left">Nama Barang</th>
                    <th class="text-right" style="width: 25%;">Total</th>
                </tr>
                <tr>
                    <th colspan="3"><div style="border-top: 1px dashed #000; margin: 5px 0;"></div></th>
                </tr>
            </thead>
            <tbody>
                @foreach($receiptData['lines'] as $line)
                <tr class="item-row">
                    <td class="text-left">{{ (float)$line['qty'] }}</td>
                    <td class="item-name text-left">{{ $line['product_name'] }}</td>
                    <td class="text-right">{{ format_currency($line['sub_total']) }}</td>
                </tr>
                <!-- Task 2.4: Indented unit conversion breakdown -->
                @if(!empty($line['unit_breakdown']))
                <tr>
                    <td></td>
                    <td colspan="2" class="unit-breakdown">
                        {{ $line['unit_breakdown'] }}
                    </td>
                </tr>
                @endif
                
                @if($line['discount'] > 0)
                <tr>
                    <td></td>
                    <td class="text-left" style="font-size: 10px;">(Diskon: -{{ format_currency($line['discount']) }})</td>
                    <td></td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="divider"></div>

    <div class="totals">
        <table class="totals-table">
            @if($receiptData['discount'] > 0)
            <tr>
                <td class="totals-label">DISKON</td>
                <td class="totals-value">-{{ format_currency($receiptData['discount']) }}</td>
            </tr>
            @endif
            <tr>
                <td class="totals-label" style="font-size: 14px; padding-top: 5px;"><strong>TOTAL</strong></td>
                <td class="totals-value" style="font-size: 14px; padding-top: 5px;"><strong>{{ format_currency($receiptData['grand_total']) }}</strong></td>
            </tr>
            
            <!-- Task 2.5: Right-aligned totals and payment methods -->
            <tr style="height: 10px;"><td></td><td></td></tr>
            
            @if(!empty($receiptData['payment_breakdown']) && count($receiptData['payment_breakdown']) > 0)
                @foreach($receiptData['payment_breakdown'] as $payment)
                <tr>
                    <td class="totals-label">BAYAR ({{ $payment['method_name'] }})</td>
                    <td class="totals-value">{{ format_currency($payment['amount']) }}</td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td class="totals-label">BAYAR ({{ $receiptData['payment_method'] }})</td>
                    <td class="totals-value">{{ format_currency($receiptData['amount_paid']) }}</td>
                </tr>
            @endif
            
            @if($receiptData['change'] > 0)
            <tr>
                <td class="totals-label">KEMBALI</td>
                <td class="totals-value">{{ format_currency($receiptData['change']) }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="divider"></div>

    <div class="footer">
        <p>{!! nl2br(e($receiptData['footer_text'])) !!}</p>
        <!-- Task 2.6: Hardcoded footer message -->
        <p style="margin-top: 5px; font-weight: bold;">Harga sudah termasuk PPN</p>
    </div>
</body>
</html>
