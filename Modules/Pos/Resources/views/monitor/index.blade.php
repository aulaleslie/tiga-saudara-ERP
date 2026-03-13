@extends('layouts.app')

@section('title', 'Monitor Sesi POS Aktif')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0 text-gray-800">Monitor Sesi POS Aktif</h1>
            <div>
                <span id="monitor-last-updated" class="text-muted small me-2">Memuat...</span>
                <button id="monitor-refresh-btn" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-sync-alt"></i> Segarkan Sekarang
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
                                <th>Kasir</th>
                                <th>Dibuka Pada</th>
                                <th class="text-end">Saldo Awal</th>
                                <th class="text-end">Kas Diharapkan</th>
                                <th class="text-end">Batas</th>
                                <th class="text-center">Setoran Aman</th>
                                <th class="text-center">Transaksi</th>
                                <th>Aktivitas Terakhir</th>
                            </tr>
                        </thead>
                        <tbody id="monitor-table-body">
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">Memuat sesi aktif...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
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
            if (!response.ok) throw new Error('Respons jaringan tidak valid');
            return response.json();
        })
        .then(data => {
            tableBody.innerHTML = '';
            
            if (data.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Tidak ada sesi POS aktif saat ini.</td></tr>';
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
                            ${session.is_threshold_breached ? '<br><span class="badge bg-danger">Terlampaui</span>' : ''}
                        </td>
                        <td class="text-center">
                            <span class="badge ${session.safe_drops_count > 0 ? 'bg-info' : 'bg-secondary'}">${session.safe_drops_count}</span>
                        </td>
                        <td class="text-center">${session.transactions_count}</td>
                        <td>
                            <small>${formatDate(session.last_activity_at)}</small>
                            <br>
                            <span class="badge bg-primary">Sesi ${session.session_id}</span>
                        </td>
                    `;
                    tableBody.appendChild(tr);
                });
            }
            
            lastUpdatedLabel.textContent = `Terakhir diperbarui: ${new Date().toLocaleTimeString('id-ID')}`;
            refreshBtn.disabled = false;
        })
        .catch(error => {
            console.error('Gagal mengambil data monitor:', error);
            tableBody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">Gagal memuat data sesi. Silakan coba lagi.</td></tr>';
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
