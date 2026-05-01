<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Struk POS {{ $receiptData['receipt_number'] }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @page {
            size: 72mm auto;
            margin: 0;
            orientation: portrait;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 12px;
            line-height: 16px;
            font-weight: 700;
        }

        body {
            width: 72mm;
            max-width: 72mm;
            margin: 0 auto;
            padding: 0;
            background: #fff;
        }

        .receipt-container {
            width: 68mm;
            max-width: 68mm;
            margin: 0 auto;
            padding: 2mm 0;
        }

        h2 {
            font-size: 14px;
            font-weight: 700;
        }

        table,
        tr,
        td,
        th {
            border-collapse: collapse;
        }

        table {
            width: 100%;
        }

        tr {
            border-bottom: 1px dashed #000;
        }

        td,
        th {
            padding: 3px 0;
            font-size: 11px;
            font-weight: 700;
            vertical-align: top;
        }

        .centered {
            text-align: center;
            align-content: center;
        }

        .small {
            font-size: 10px;
            line-height: 14px;
        }

        .meta {
            margin-top: 5px;
        }

        .meta-table td {
            font-size: 10px;
            line-height: 13px;
            padding: 1px 0;
            vertical-align: top;
        }

        .meta-table tr {
            border-bottom: 0;
        }

        .meta-label {
            width: 19mm;
            white-space: nowrap;
        }

        .meta-separator {
            width: 3mm;
            text-align: center;
        }

        .print-history {
            margin-top: 5px;
        }

        .receipt-tail-space {
            height: 28mm;
        }

        .receipt-tail-line {
            border-top: 1px dashed #000;
            margin-top: 4mm;
        }

        .tail-print-history {
            margin-top: 3mm;
            text-align: center;
            font-size: 8px;
            line-height: 10px;
            font-weight: 400;
        }

        .totals-table tr:last-child,
        .payment-table tr:last-child,
        .items-table tbody tr:last-child {
            border-bottom: 0;
        }

        .no-print {
            margin-bottom: 12px;
            text-align: center;
        }

        .no-print button {
            padding: 10px 20px;
            font-size: 14px;
            cursor: pointer;
        }

        @media print {
            html,
            body {
                width: 72mm;
                max-width: 72mm;
                margin: 0 auto;
                padding: 0;
            }

            * {
                font-size: 11px;
                line-height: 14px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            td,
            th {
                padding: 2px 0;
            }

            .no-print {
                display: none !important;
            }

            body {
                margin: 0 auto;
                padding: 0;
                background: #fff;
            }

            .receipt-container {
                width: 68mm;
                max-width: 68mm;
                margin: 0 auto;
                padding: 2mm 0;
            }
        }

        @media screen {
            body {
                background: #f0f0f0;
                padding: 12px 0;
            }

            .receipt-container {
                background: #fff;
                padding: 10px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }
        }
    </style>
</head>
<body>
@php
    $displayDate = \Carbon\Carbon::parse($receiptData['date'])->translatedFormat('d M, Y H:i');
    $hasPrintHistory = !empty($receiptData['print_history']) && ($receiptData['print_history']['count'] ?? 0) > 0;
@endphp

<div class="no-print">
    <button onclick="window.print()">Cetak Struk</button>
</div>

<div class="receipt-container">
    <div id="receipt-data">
        <div class="centered">
            <h2 style="margin-bottom: 5px">{{ $receiptData['business_name'] }}</h2>

            <p class="small">
                @if(!empty($receiptData['business_email']) || !empty($receiptData['business_phone']))
                    {{ collect([$receiptData['business_email'] ?? null, $receiptData['business_phone'] ?? null])->filter()->implode(', ') }}
                    <br>
                @endif
                {{ $receiptData['business_address'] ?? '' }}
            </p>
        </div>

        <table class="meta meta-table">
            <tbody>
                <tr>
                    <td class="meta-label">Tanggal</td>
                    <td class="meta-separator">:</td>
                    <td>{{ $displayDate }}</td>
                </tr>
                <tr>
                    <td class="meta-label">No. Struk</td>
                    <td class="meta-separator">:</td>
                    <td>{{ $receiptData['receipt_number'] }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Pelanggan</td>
                    <td class="meta-separator">:</td>
                    <td>{{ $receiptData['customer_name'] ?? '-' }}</td>
                </tr>
                @if(!empty($receiptData['cashier_name']) && $receiptData['cashier_name'] !== 'N/A')
                    <tr>
                        <td class="meta-label">Kasir</td>
                        <td class="meta-separator">:</td>
                        <td>{{ $receiptData['cashier_name'] }}</td>
                    </tr>
                @endif
                @if(!empty($receiptData['terminal_name']) && $receiptData['terminal_name'] !== 'N/A')
                    <tr>
                        <td class="meta-label">Terminal</td>
                        <td class="meta-separator">:</td>
                        <td>{{ $receiptData['terminal_name'] }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <table class="items-table" style="margin-top: 5px; margin-bottom: 5px;">
            <thead>
                <tr>
                    <th style="text-align:left; width: 30px;">Qty</th>
                    <th style="text-align:left">Nama Barang</th>
                    <th style="text-align:right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($receiptData['lines'] as $line)
                    <tr>
                        <td style="width: 30px;">{{ rtrim(rtrim(number_format((float) $line['qty'], 2, '.', ''), '0'), '.') }}</td>
                        <td>
                            {{ $line['product_name'] }}
                            @if(!empty($line['unit_breakdown']))
                                <br>
                                <span class="small">{{ $line['unit_breakdown'] }}</span>
                            @endif
                            @if(($line['discount'] ?? 0) > 0)
                                <br>
                                <span class="small">Diskon: -{{ format_currency($line['discount']) }}</span>
                            @endif
                            @if(!empty($line['bundle_composition']))
                                @foreach($line['bundle_composition'] as $item)
                                    <br>
                                    <span class="small" style="font-weight: 400;">&nbsp;&nbsp;- {{ $item['name'] }} x{{ (float)$item['qty'] }}</span>
                                @endforeach
                            @endif
                            @if(!empty($line['assigned_serials']))
                                <br>
                                <span class="small" style="font-weight: 400;">&nbsp;&nbsp;SN: {{ implode(', ', $line['assigned_serials']) }}</span>
                            @endif
                        </td>
                        <td style="text-align:right">{{ number_format((float) $line['sub_total'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach

                @if(($receiptData['discount'] ?? 0) > 0)
                    <tr>
                        <th colspan="2" style="text-align:left">Diskon</th>
                        <th style="text-align:right">{{ number_format((float) $receiptData['discount'], 0, ',', '.') }}</th>
                    </tr>
                @endif
            </tbody>
        </table>

        <table class="totals-table">
            <tbody>
                <tr>
                    <th colspan="2" style="text-align:left">Total</th>
                    <th style="text-align:right">{{ number_format((float) $receiptData['grand_total'], 0, ',', '.') }}</th>
                </tr>
            </tbody>
        </table>

        <table class="payment-table">
            <tbody>
                @if(!empty($receiptData['payment_breakdown']) && count($receiptData['payment_breakdown']) > 0)
                    @foreach($receiptData['payment_breakdown'] as $payment)
                        <tr style="background-color:#ddd;">
                            <th colspan="2" style="text-align:left; padding: 4px;">
                                Bayar: {{ $payment['method_name'] }}
                            </th>
                            <th style="text-align:right; padding: 4px;">{{ number_format((float) $payment['amount'], 0, ',', '.') }}</th>
                        </tr>
                    @endforeach
                @else
                    <tr style="background-color:#ddd;">
                        <th colspan="2" style="text-align:left; padding: 4px;">
                            Bayar: {{ $receiptData['payment_method'] }}
                        </th>
                        <th style="text-align:right; padding: 4px;">{{ number_format((float) $receiptData['amount_paid'], 0, ',', '.') }}</th>
                    </tr>
                @endif

                @if(($receiptData['change'] ?? 0) > 0)
                    <tr>
                        <th colspan="2" style="text-align:left">Kembalian</th>
                        <th style="text-align:right">{{ number_format((float) $receiptData['change'], 0, ',', '.') }}</th>
                    </tr>
                @endif
            </tbody>
        </table>

        <div class="receipt-tail-space"></div>
        <div class="receipt-tail-line"></div>
        
        {{-- Task 2.3, 2.4, 2.5: Footer updates --}}
        <div class="tail-print-history" style="margin-top: 2mm;">
            Harga Sudah Termasuk PPN
            <br>
            Terima Kasih Telah Berbelanja
        </div>
    </div>
</div>
</body>
</html>
