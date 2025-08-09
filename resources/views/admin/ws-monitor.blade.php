@extends('layouts.app')

@section('content')
    <div class="max-w-5xl mx-auto p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-semibold">WebSocket Monitor</h1>
            <div class="flex items-center gap-2">
                <span id="statusDot" class="inline-block w-3 h-3 rounded-full bg-gray-300"></span>
                <span id="statusText" class="text-sm text-gray-600">Idle</span>
            </div>
        </div>

        <div class="flex items-center gap-2 mb-4">
            <input id="prefix" type="text" placeholder="Filter by prefix (e.g. presence-)"
                   class="border rounded px-3 py-2 w-80" />
            <button id="refreshBtn" class="border px-3 py-2 rounded">Refresh</button>
            <span class="text-xs text-gray-500 ml-2">Auto-refresh every 5s</span>
        </div>

        <div class="overflow-x-auto border rounded">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-3 py-2">Channel</th>
                    <th class="text-left px-3 py-2">Subscriptions</th>
                    <th class="text-left px-3 py-2">Users</th>
                    <th class="text-left px-3 py-2"></th>
                </tr>
                </thead>
                <tbody id="rows"></tbody>
            </table>
        </div>

        <div id="presenceBox" class="hidden mt-6 border rounded p-4">
            <div class="flex items-center justify-between">
                <h2 class="font-medium">Presence Members: <span id="presenceName" class="font-mono"></span></h2>
                <button id="closePresence" class="text-sm underline">Close</button>
            </div>
            <ul id="presenceList" class="list-disc pl-5 mt-3 text-sm"></ul>
        </div>
    </div>

    <script>
        const statusDot = document.getElementById('statusDot');
        const statusText = document.getElementById('statusText');
        const rows = document.getElementById('rows');
        const prefixInput = document.getElementById('prefix');
        const refreshBtn = document.getElementById('refreshBtn');

        const presenceBox = document.getElementById('presenceBox');
        const presenceName = document.getElementById('presenceName');
        const presenceList = document.getElementById('presenceList');
        const closePresence = document.getElementById('closePresence');

        let timer = null;

        function setStatus(ok, msg) {
            statusDot.className = 'inline-block w-3 h-3 rounded-full ' + (ok ? 'bg-green-500' : 'bg-red-500');
            statusText.textContent = msg;
        }

        async function fetchData() {
            setStatus(true, 'Refreshing...');
            const params = new URLSearchParams();
            const p = prefixInput.value.trim();
            if (p) params.set('prefix', p);

            try {
                const res = await fetch(`{{ route('ws.monitor.data') }}?` + params.toString(), { headers: { 'Accept': 'application/json' }});
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                renderRows(data.channels || {});
                setStatus(true, 'OK @ ' + new Date().toLocaleTimeString());
            } catch (e) {
                setStatus(false, 'Error: ' + e.message);
                rows.innerHTML = `<tr><td colspan="4" class="px-3 py-3 text-red-600">Failed to load. ${e.message}</td></tr>`;
            }
        }

        function renderRows(channelsObj) {
            const entries = Object.entries(channelsObj);
            if (!entries.length) {
                rows.innerHTML = `<tr><td colspan="4" class="px-3 py-3 text-gray-500">No channels.</td></tr>`;
                return;
            }

            rows.innerHTML = entries.map(([name, info]) => {
                const subs = info.subscription_count ?? '-';
                const users = info.user_count ?? '-';
                const canPresence = name.startsWith('presence-');
                const btn = canPresence
                    ? `<button data-name="${name}" class="text-blue-600 underline text-sm show-presence">View members</button>`
                    : '';
                return `<tr class="border-t">
        <td class="px-3 py-2 font-mono">${name}</td>
        <td class="px-3 py-2">${subs}</td>
        <td class="px-3 py-2">${users}</td>
        <td class="px-3 py-2">${btn}</td>
      </tr>`;
            }).join('');

            document.querySelectorAll('.show-presence').forEach(btn => {
                btn.addEventListener('click', () => loadPresence(btn.dataset.name));
            });
        }

        async function loadPresence(name) {
            presenceBox.classList.add('hidden');
            presenceList.innerHTML = '';
            presenceName.textContent = name;
            try {
                const res = await fetch(`{{ url('/ws-monitor/presence') }}/${encodeURIComponent(name)}`, { headers: { 'Accept': 'application/json' }});
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                const members = (data.users || []).map(u => u.id ?? JSON.stringify(u));
                presenceList.innerHTML = members.length
                    ? members.map(id => `<li><span class="font-mono">${id}</span></li>`).join('')
                    : '<li class="text-gray-500">No members</li>';
                presenceBox.classList.remove('hidden');
            } catch (e) {
                alert('Failed to load presence: ' + e.message);
            }
        }

        closePresence.addEventListener('click', () => presenceBox.classList.add('hidden'));
        refreshBtn.addEventListener('click', fetchData);

        // auto-refresh
        fetchData();
        timer = setInterval(fetchData, 5000);
    </script>
@endsection
