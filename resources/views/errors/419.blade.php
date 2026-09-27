<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ __('Sesi Berakhir') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7;
            color: #22292f;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: .5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
            max-width: 32rem;
            width: 90%;
            padding: 2rem;
        }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; }
        .incident {
            background: #f4f5f7;
            border-radius: .375rem;
            padding: .75rem 1rem;
            font-family: monospace;
            margin: 1rem 0;
            word-break: break-all;
        }
        .warning {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: .375rem;
            padding: .75rem 1rem;
            margin: 1rem 0;
        }
        .actions { margin-top: 1.5rem; }
        .actions a, .actions button {
            display: inline-block;
            padding: .6rem 1.2rem;
            border-radius: .375rem;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin-right: .5rem;
        }
        .btn-primary { background: #2779bd; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #22292f; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Sesi Anda Telah Berakhir') }}</h1>
        <p>{{ $message ?? __('Sesi Anda telah berakhir. Silakan muat ulang halaman atau masuk kembali.') }}</p>

        @if(!empty($transaction_sensitive))
            <div class="warning">
                {{ __('Sebelum mencoba lagi, periksa apakah transaksi sebelumnya sudah tersimpan agar tidak terjadi duplikasi.') }}
            </div>
        @endif

        <p>{{ __('Nomor insiden (sampaikan ke administrator jika masalah berulang):') }}</p>
        <div class="incident">{{ $incident_id ?? 'ERP-INCIDENT-UNAVAILABLE' }}</div>

        <div class="actions">
            @if(($is_safe_reload ?? false))
                <a href="{{ url()->current() }}" class="btn-primary">{{ __('Muat Ulang Halaman') }}</a>
            @endif
            <a href="{{ app('router')->has('home') ? route('home') : url('/') }}" class="btn-secondary">{{ __('Kembali ke Beranda') }}</a>
        </div>
    </div>
</body>
</html>
