<!doctype html>
<html>
<head>
    <meta charset="utf-8"/>
    <title>WS Test</title>
    <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; margin: 20px; }
        pre { background:#0b1020; color:#a7ff83; padding:12px; min-height:220px; border-radius:8px; }
        input, button { padding:8px 10px; }
    </style>
</head>
<body>
@php
    // You can change ?user=42 in the URL to subscribe for a different userId
    $userId = (int) request('user', 1);
    $channelName = "print-receipt-job.$userId";
@endphp

<h1>WebSocket Test</h1>
<p>Subscribing to channel: <code>{{ $channelName }}</code>, event: <code>PrintReceiptJob</code></p>
<p>
    Change user:
    <input id="uid" type="number" value="{{ $userId }}" />
    <button onclick="location.search='?user='+document.getElementById('uid').value">Resubscribe</button>
</p>

<pre id="log"></pre>

<script>
    const logEl = document.getElementById('log');
    function log(...a){ logEl.textContent += a.join(' ') + "\n"; logEl.scrollTop = logEl.scrollHeight; }

    // Debug logs in console (dev only)
    Pusher.logToConsole = true;

    const pusher = new Pusher("{{ env('PUSHER_APP_KEY') }}", {
        wsHost: "{{ env('PUSHER_HOST', '127.0.0.1') }}",
        wsPort: {{ (int) env('PUSHER_PORT', 6001) }},
        wssPort: {{ (int) env('PUSHER_PORT', 6001) }},
        forceTLS: false,
        enabledTransports: ['ws'], // skip xhr-streaming
        cluster: "{{ env('PUSHER_APP_CLUSTER', 'mt1') }}",
        disableStats: true,
    });

    const channelName = "{{ $channelName }}";
    const channel = pusher.subscribe(channelName);

    pusher.connection.bind('state_change', s => log('🔌 state:', s.previous, '→', s.current));
    pusher.connection.bind('error', e => log('❌ error:', JSON.stringify(e)));

    channel.bind('pusher:subscription_succeeded', () => log('✅ subscribed:', channelName));
    channel.bind('PrintReceiptJob', data => log('📦 PrintReceiptJob:', JSON.stringify(data)));
</script>
</body>
</html>
