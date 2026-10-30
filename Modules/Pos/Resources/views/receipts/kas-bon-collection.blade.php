<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Struk Pembayaran Kas Bon {{ $collection->reference }}</title>
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

        table, tr, td, th {
            border-collapse: collapse;
        }

        table {
            width: 100%;
        }

        tr {
            border-bottom: 1px dashed #000;
        }

        td, th {
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
            width: 22mm;
            white-space: nowrap;
        }

        .meta-separator {
            width: 3mm;
            text-align: center;
        }

        .totals-table tr:last-child {
            border-bottom: 0;
        }

        .no-print {
            margin-bottom: 12px;
            text-align: center;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 8px 12px;
        }

        .no-print button {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            background: #007bff;
            color: #fff;
            border: 1px solid #007bff;
            border-radius: 4px;
        }

        .no-print .receipt-note-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            line-height: 16px;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }

        .no-print .receipt-note-toggle input[type="checkbox"] {
            width: 14px;
            height: 14px;
            cursor: pointer;
            margin: 0;
        }

        .receipt-note[hidden],
        [data-receipt-note][hidden] {
            display: none !important;
        }

        .reprint-badge {
            margin-bottom: 6px;
            padding: 4px;
            border: 1px dashed #444;
            background: #fafafa;
            text-align: center;
            font-size: 10px;
            font-weight: 700;
        }

        .footer-note {
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px dashed #000;
            text-align: center;
            font-size: 9px;
            line-height: 12px;
            font-weight: 400;
        }

        @media print {
            html, body {
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

            .no-print,
            .receipt-note[hidden],
            [data-receipt-note][hidden] {
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
    $snapshot = $collection->receipt_snapshot ?? [];
    $setting = $collection->setting ?? settings();
    $visibility = $receiptVisibility ?? [];
    $showNote = (bool) ($visibility['show_note'] ?? $visibility['pos_receipt_show_note'] ?? false);
    $resolvedTransactionNote = isset($transactionNote) && trim((string) $transactionNote) !== ''
        ? (string) $transactionNote
        : null;
    $collectedAt = $collection->collected_at 
        ? \Carbon\Carbon::parse($collection->collected_at)->translatedFormat('d M, Y H:i:s')
        : now()->translatedFormat('d M, Y H:i:s');
    $customerName = $snapshot['customer_name'] 
        ?? $collection->transaction?->customer?->customer_name 
        ?? '-';
    $cashierName = $snapshot['cashier_name'] 
        ?? $collection->user?->name 
        ?? 'Kasir';
    $terminalName = $snapshot['terminal_name'] 
        ?? ($collection->terminal?->name ?? 'Non-Terminal');
    $posRef = $snapshot['pos_reference'] 
        ?? $collection->transaction?->code 
        ?? '-';
    $origReceipt = $snapshot['receipt_number'] 
        ?? $collection->transaction?->completedCheckout?->receipt_number 
        ?? '-';
    $amountCollected = (float) ($snapshot['amount_collected'] ?? $collection->amount);
    $tenderTotal = (float) ($snapshot['tender_total'] ?? $collection->cash_tender_amount);
    $changeTotal = (float) ($snapshot['change_total'] ?? $collection->change_amount);
    $remainingDebt = (float) ($snapshot['remaining_debt'] ?? $collection->remaining_debt);
@endphp

@include('pos::receipts.partials.print-actions', ['showNote' => $showNote])

<div class="receipt-container">
    @if(!empty($isReprint))
        <div class="reprint-badge">
            SALINAN / REPRINT STRUK
            <div class="small" style="font-weight: 400; font-size: 8px;">
                Cetak ke-{{ $printHistory['count'] ?? 1 }} ({{ now()->translatedFormat('d M, Y H:i:s') }})
            </div>
        </div>
    @endif

    <div class="centered">
        <h2>{{ $setting->company_name ?? 'POS TIGA SAUDARA' }}</h2>
        @if(!empty($setting->company_address))
            <div class="small" style="font-weight: 400;">{{ $setting->company_address }}</div>
        @endif
        @if(!empty($setting->company_phone))
            <div class="small" style="font-weight: 400;">Telp: {{ $setting->company_phone }}</div>
        @endif
        <div style="margin-top: 4px; font-weight: 700; text-transform: uppercase;">
            PEMBAYARAN KAS BON
        </div>
    </div>

    <div class="meta">
        <table class="meta-table">
            <tr>
                <td class="meta-label">No. Koleksi</td>
                <td class="meta-separator">:</td>
                <td>{{ $collection->reference }}</td>
            </tr>
            <tr>
                <td class="meta-label">Ref. Transaksi</td>
                <td class="meta-separator">:</td>
                <td>{{ $posRef }}</td>
            </tr>
            @if($origReceipt !== '-')
            <tr>
                <td class="meta-label">No. Struk Asli</td>
                <td class="meta-separator">:</td>
                <td>{{ $origReceipt }}</td>
            </tr>
            @endif
            <tr>
                <td class="meta-label">Waktu Koleksi</td>
                <td class="meta-separator">:</td>
                <td>{{ $collectedAt }}</td>
            </tr>
            <tr>
                <td class="meta-label">Pelanggan</td>
                <td class="meta-separator">:</td>
                <td>{{ $customerName }}</td>
            </tr>
            <tr>
                <td class="meta-label">Kasir Penerima</td>
                <td class="meta-separator">:</td>
                <td>{{ $cashierName }}</td>
            </tr>
            <tr>
                <td class="meta-label">Terminal / Sesi</td>
                <td class="meta-separator">:</td>
                <td>{{ $terminalName }} (Sesi #{{ $collection->pos_session_id }})</td>
            </tr>
        </table>
    </div>

    <div style="border-top: 1px dashed #000; margin: 4px 0;"></div>

    <table class="totals-table">
        <tr>
            <td style="text-align: left;">Jumlah Dibayar</td>
            <td style="text-align: right; font-weight: 700;">{{ format_currency($amountCollected) }}</td>
        </tr>
    </table>

    <div style="border-top: 1px dashed #000; margin: 4px 0;"></div>

    <div style="font-size: 10px; font-weight: 700; margin-bottom: 2px;">Metode Pembayaran:</div>
    <table class="totals-table">
        @if(!empty($collection->methods) && $collection->methods->count() > 0)
            @foreach($collection->methods as $m)
                <tr>
                    <td style="text-align: left; font-size: 10px; font-weight: 400;">
                        {{ $m->payment_method_name }}
                    </td>
                    <td style="text-align: right; font-size: 10px; font-weight: 700;">
                        {{ format_currency($m->amount) }}
                    </td>
                </tr>
            @endforeach
        @elseif(!empty($snapshot['methods']))
            @foreach($snapshot['methods'] as $m)
                <tr>
                    <td style="text-align: left; font-size: 10px; font-weight: 400;">
                        {{ $m['payment_method_name'] ?? ('Metode #' . $m['payment_method_id']) }}
                    </td>
                    <td style="text-align: right; font-size: 10px; font-weight: 700;">
                        {{ format_currency($m['amount']) }}
                    </td>
                </tr>
            @endforeach
        @endif
        @if($tenderTotal > 0 && $tenderTotal > $amountCollected)
            <tr>
                <td style="text-align: left; font-size: 10px; font-weight: 400;">Total Diterima (Tender)</td>
                <td style="text-align: right; font-size: 10px; font-weight: 700;">{{ format_currency($tenderTotal) }}</td>
            </tr>
        @endif
        @if($changeTotal > 0)
            <tr>
                <td style="text-align: left; font-size: 10px; font-weight: 700;">Kembalian</td>
                <td style="text-align: right; font-size: 10px; font-weight: 700;">{{ format_currency($changeTotal) }}</td>
            </tr>
        @endif
    </table>

    <div style="border-top: 1px dashed #000; margin: 4px 0;"></div>

    <table class="totals-table">
        <tr>
            <td style="text-align: left; font-size: 11px; font-weight: 700;">Sisa Hutang Kas Bon</td>
            <td style="text-align: right; font-size: 11px; font-weight: 700; color: {{ $remainingDebt > 0 ? '#b30000' : '#006600' }};">
                {{ format_currency($remainingDebt) }}
            </td>
        </tr>
    </table>

    @include('pos::receipts.partials.transaction-note', [
        'showNote' => $showNote,
        'noteText' => $resolvedTransactionNote,
    ])

    <div class="footer-note">
        <div>Bukti pembayaran sah atas pelunasan / angsuran Kas Bon.</div>
        <div>Terima kasih atas pembayaran Anda.</div>
    </div>
</div>
@include('pos::receipts.partials.note-toggle-script')
</body>
</html>
