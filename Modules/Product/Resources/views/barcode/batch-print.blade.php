<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Barcode ({{ count($labels) }} label)</title>
    <style>
        @page {
            size: 55mm 40mm;
            margin: 0;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
        }

        .label-page {
            box-sizing: border-box;
            width: 55mm;
            height: 40mm;
            padding: 2mm;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid;
            page-break-after: always;
            break-after: page;
        }

        .label-page:last-of-type {
            page-break-after: auto;
            break-after: auto;
        }

        .label-name {
            font-size: 8pt;
            line-height: 1.1;
            max-height: 8mm;
            overflow: hidden;
            width: 100%;
            word-break: break-word;
        }

        /*
         * SKU display rule (deterministic, applied server-side):
         * <= 40 characters renders in full; longer values render the first 39
         * characters followed by a visible Unicode ellipsis. The SKU always uses
         * the standard readable label font — it is never shrunk to fit, and
         * truncation is never delegated to CSS ellipsis or hidden overflow.
         * The stored product_code and the barcode value are unaffected.
         */
        .label-sku {
            font-size: 7pt;
            line-height: 1.05;
            width: 100%;
            word-break: break-all;
            overflow-wrap: anywhere;
            white-space: normal;
            flex-shrink: 0;
        }

        .label-barcode {
            width: 100%;
            overflow: hidden;
        }

        .label-barcode svg {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .label-value {
            font-size: 7pt;
            letter-spacing: 0.5px;
            line-height: 1.1;
        }

        .label-price {
            font-size: 10pt;
            font-weight: bold;
            line-height: 1.1;
        }

        .print-toolbar {
            padding: 8px;
            text-align: center;
            font-size: 12px;
            background: #f2f2f2;
        }

        @media print {
            .print-toolbar {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="print-toolbar">
    <button type="button" id="manual-print-button" onclick="window.print()">Cetak</button>
    <span>Salinan (copies) harus tetap 1 — {{ count($labels) }} label sudah digandakan oleh aplikasi.</span>
    <div data-testid="printer-guidance" style="margin-top: 6px;">
        Blueprint ECO80BT (USB): ukuran kertas 55 mm × 40 mm, media label gap, skala ukuran asli/100%,
        tanpa margin, satu halaman per lembar, header/footer &amp; duplex dimatikan.
        Aplikasi tidak dapat memilih printer, memaksa pengaturan driver, mendeteksi gangguan gap/media,
        atau memverifikasi hasil cetak fisik.
    </div>
</div>

@foreach($labels as $label)
    <div class="label-page">
        <div class="label-name">{{ $label['product_name'] }}</div>
        <div class="label-sku">{{ \Modules\Product\Services\BarcodeBatchService::displaySku($label['product_code']) }}</div>
        <div class="label-barcode">
            {{-- Trusted server-generated SVG from the barcode library only. --}}
            {!! $label['svg'] !!}
        </div>
        <div class="label-value">{{ $label['barcode'] }}</div>
        <div class="label-price">{{ format_currency($label['sale_price']) }}</div>
    </div>
@endforeach

<script>
    window.addEventListener('load', function () {
        window.print();
    });
</script>
</body>
</html>
