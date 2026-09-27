<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Uji Cetak Diagnostik ({{ count($labels) }} label)</title>
    <style>
        /*
         * Diagnostic label sheet for physical printer acceptance only.
         * Page geometry and the 2 mm safe area match the production label
         * exactly; the extra border and markers sit INSIDE that safe area.
         */
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
            page-break-inside: avoid;
            break-inside: avoid;
            page-break-after: always;
            break-after: page;
        }

        .label-page:last-of-type {
            page-break-after: auto;
            break-after: auto;
        }

        /* Border drawn exactly on the 2 mm safe-area boundary. Any edge of this
           box that is missing on paper means the label is clipped or drifting. */
        .diagnostic-frame {
            box-sizing: border-box;
            width: 100%;
            height: 100%;
            border: 0.3mm solid #000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            overflow: hidden;
        }

        /* Top and bottom alignment markers: vertical drift shows up as unequal
           marker thickness between the first and last label. */
        .alignment-marker {
            width: 100%;
            height: 1.5mm;
            background: #000;
            flex-shrink: 0;
        }

        .diagnostic-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-around;
            width: 100%;
            overflow: hidden;
        }

        .diagnostic-sequence {
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: 1px;
            line-height: 1.1;
        }

        .diagnostic-barcode {
            width: 100%;
            overflow: hidden;
        }

        .diagnostic-barcode svg {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .diagnostic-value {
            font-size: 7pt;
            line-height: 1.1;
        }

        .print-toolbar {
            padding: 8px;
            text-align: center;
            font-size: 12px;
            background: #ffe9b3;
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
    <strong>UJI CETAK DIAGNOSTIK — bukan label produk.</strong>
    <button type="button" id="manual-print-button" onclick="window.print()">Cetak</button>
    <span>Salinan (copies) harus tetap 1 — {{ count($labels) }} label diagnostik.</span>
</div>

@foreach($labels as $label)
    <div class="label-page">
        <div class="diagnostic-frame">
            <div class="alignment-marker"></div>
            <div class="diagnostic-body">
                <div class="diagnostic-sequence">{{ $label['sequence'] }}</div>
                <div class="diagnostic-barcode">{!! $label['svg'] !!}</div>
                <div class="diagnostic-value">{{ $label['barcode'] }}</div>
            </div>
            <div class="alignment-marker"></div>
        </div>
    </div>
@endforeach

<script>
    window.addEventListener('load', function () {
        window.print();
    });
</script>
</body>
</html>
