<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Ulang Struk Tidak Tersedia</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
        }
        .card {
            background: #ffffff;
            max-width: 480px;
            width: 100%;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 24px;
        }
        .title {
            font-size: 18px;
            font-weight: 700;
            color: #b91c1c;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .message {
            font-size: 14px;
            line-height: 1.5;
            color: #4b5563;
            margin-bottom: 20px;
        }
        .actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-primary {
            background-color: #2563eb;
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        .btn-secondary {
            background-color: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }
        .btn-secondary:hover {
            background-color: #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="title">
            ⚠️ Cetak Ulang Harga Terkini Tidak Tersedia
        </div>
        <div class="message">
            {{ $message ?? 'Struk tidak dapat dicetak ulang dengan harga terkini karena transaksi historis ini memiliki multi-baris yang ambigu tanpa silsilah pemetaan pasti ke rincian penjualan yang diedit.' }}
            <br><br>
            Sistem mencegah perkiraan harga baris yang keliru. Anda tetap dapat membuka dan mencetak <strong>struk historis checkout asli</strong> (snapshot asli).
        </div>
        <div class="actions">
            @if(!empty($historicalReceiptUrl))
                <a href="{{ $historicalReceiptUrl }}" class="btn btn-primary">Lihat Struk Historis Asli</a>
            @endif
            <button onclick="window.history.back()" class="btn btn-secondary">Kembali</button>
        </div>
    </div>
</body>
</html>
