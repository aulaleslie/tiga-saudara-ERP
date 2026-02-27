<!DOCTYPE html>
<html lang="en">
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
        .item-name { font-weight: bold; }
        .item-qty { text-align: left; width: 25px; }
        .item-price { text-align: right; }
        
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
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer;">Print Struk</button>
    </div>

    <div class="header">
        <h2>{{ $receiptData['business_name'] }}</h2>
        @if(!empty($receiptData['business_address']))
            <p>{{ $receiptData['business_address'] }}</p>
        @endif
        @if(!empty($receiptData['business_phone']))
            <p>Telp: {{ $receiptData['business_phone'] }}</p>
        @endif
        <div class="divider"></div>
        <table style="width: 100%;">
            <tr>
                <td class="text-left">No</td>
                <td class="text-left">: {{ $receiptData['receipt_number'] }}</td>
            </tr>
            <tr>
                <td class="text-left">Tgl</td>
                <td class="text-left">: {{ $receiptData['date'] }}</td>
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
            @foreach($receiptData['lines'] as $line)
            <tr>
                <td colspan="3" class="item-name">{{ $line['product_name'] }}</td>
            </tr>
            <tr>
                <td class="item-qty">{{ $line['qty'] }} x</td>
                <td class="text-left">{{ format_currency($line['price']) }}</td>
                <td class="item-price">{{ format_currency($line['sub_total']) }}</td>
            </tr>
            @if($line['discount'] > 0)
            <tr>
                <td></td>
                <td class="text-left" style="font-size: 10px;">Disc.</td>
                <td class="item-price" style="font-size: 10px;">-{{ format_currency($line['discount']) }}</td>
            </tr>
            @endif
            @endforeach
        </table>
    </div>

    <div class="divider"></div>

    <div class="totals">
        <table class="totals-table">
            <tr>
                <td class="totals-label">Subtotal</td>
                <td class="totals-value">{{ format_currency($receiptData['subtotal']) }}</td>
            </tr>
            @if($receiptData['discount'] > 0)
            <tr>
                <td class="totals-label">Diskon</td>
                <td class="totals-value">-{{ format_currency($receiptData['discount']) }}</td>
            </tr>
            @endif
            @if($receiptData['tax'] > 0)
            <tr>
                <td class="totals-label">Pajak</td>
                <td class="totals-value">{{ format_currency($receiptData['tax']) }}</td>
            </tr>
            @endif
            <tr>
                <td class="totals-label" style="font-size: 14px; padding-top: 5px;"><strong>TOTAL</strong></td>
                <td class="totals-value" style="font-size: 14px; padding-top: 5px;"><strong>{{ format_currency($receiptData['grand_total']) }}</strong></td>
            </tr>
            <tr>
                <td class="totals-label" style="padding-top: 5px;">BAYAR ({{ $receiptData['payment_method'] }})</td>
                <td class="totals-value" style="padding-top: 5px;">{{ format_currency($receiptData['amount_paid']) }}</td>
            </tr>
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
    </div>
</body>
</html>
