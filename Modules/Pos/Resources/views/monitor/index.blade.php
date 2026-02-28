@extends('layouts.app')

@section('title', 'Live POS Sessions Monitor')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0 text-gray-800">Live POS Sessions Monitor</h1>
            <div>
                <span id="monitor-last-updated" class="text-muted small me-2">Loading...</span>
                <button id="monitor-refresh-btn" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt"></i> Refresh Now
                </button>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="monitor-table">
                        <thead class="table-light">
                            <tr>
                                <th>Terminal</th>
                                <th>Cashier</th>
                                <th>Opened At</th>
                                <th class="text-end">Opening Float</th>
                                <th class="text-end">Expected Cash</th>
                                <th class="text-end">Threshold</th>
                                <th class="text-center">Safe Drops</th>
                                <th class="text-center">Transactions</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody id="monitor-table-body">
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">Loading active sessions...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.getElementById('monitor-table-body');
    const lastUpdatedLabel = document.getElementById('monitor-last-updated');
    const refreshBtn = document.getElementById('monitor-refresh-btn');

    const formatCurrency = (value) => {
        return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
    };

    const formatDate = (isoString) => {
        if (!isoString) return '-';
        const date = new Date(isoString);
        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };

    const loadSessions = () => {
        refreshBtn.disabled = true;
        
        fetch('{{ route('pos.monitor.sessions') }}', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            tableBody.innerHTML = '';
            
            if (data.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No active POS sessions right now.</td></tr>';
            } else {
                data.forEach(session => {
                    const tr = document.createElement('tr');
                    if (session.is_threshold_breached) {
                        tr.classList.add('table-danger');
                    }

                    tr.innerHTML = `
                        <td>
                            <strong>${session.terminal_code}</strong><br>
                            <small class="text-muted">${session.terminal_name}</small>
                        </td>
                        <td>${session.cashier_name}</td>
                        <td>${formatDate(session.opened_at)}</td>
                        <td class="text-end">${formatCurrency(session.opening_float_total)}</td>
                        <td class="text-end fw-bold ${session.is_threshold_breached ? 'text-danger' : ''}">${formatCurrency(session.expected_cash_total)}</td>
                        <td class="text-end small text-muted">
                            ${formatCurrency(session.threshold_value)}
                            ${session.is_threshold_breached ? '<br><span class="badge bg-danger">Breached</span>' : ''}
                        </td>
                        <td class="text-center">
                            <span class="badge ${session.safe_drops_count > 0 ? 'bg-info' : 'bg-secondary'}">${session.safe_drops_count}</span>
                        </td>
                        <td class="text-center">${session.transactions_count}</td>
                        <td>
                            <small>${formatDate(session.last_activity_at)}</small>
                            <br>
                            <span class="badge bg-primary">Session ${session.session_id}</span>
                        </td>
                    `;
                    tableBody.appendChild(tr);
                });
            }
            
            lastUpdatedLabel.textContent = `Last updated: ${new Date().toLocaleTimeString('id-ID')}`;
            refreshBtn.disabled = false;
        })
        .catch(error => {
            console.error('Failed to fetch monitor data:', error);
            tableBody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">Failed to load sessions data. Please try again.</td></tr>';
            refreshBtn.disabled = false;
        });
    };

    // Initial load
    loadSessions();

    // Refresh button
    refreshBtn.addEventListener('click', loadSessions);

    // Auto refresh every 30 seconds
    setInterval(loadSessions, 30000);
});
</script>
@endpush
