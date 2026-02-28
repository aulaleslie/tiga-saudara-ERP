@extends('layouts.app')

@section('title', 'Rekonsiliasi POS')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0 text-gray-800">Rekonsiliasi POS</h1>
            <div class="d-flex align-items-center">
                <div class="me-2 d-flex align-items-center">
                    <label for="date-from" class="me-2 mb-0 whitespace-nowrap">Dari</label>
                    <input type="date" id="date-from" class="form-control form-control-sm" value="{{ date('Y-m-d') }}">
                </div>
                <div class="me-3 d-flex align-items-center">
                    <label for="date-to" class="me-2 mb-0 whitespace-nowrap">Sampai</label>
                    <input type="date" id="date-to" class="form-control form-control-sm" value="{{ date('Y-m-d') }}">
                </div>
                <button id="reconciliation-refresh-btn" class="btn btn-sm btn-outline-primary whitespace-nowrap">
                    <i class="fas fa-sync-alt"></i> Muat
                </button>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header border-bottom-0">
                <h6 class="m-0 font-weight-bold text-primary">Referensi Silang Sesi & Rekonsiliasi</h6>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sesi / Terminal</th>
                                <th>Siklus</th>
                                <th class="text-end">Saldo Awal</th>
                                <th class="text-end">Kas Diharapkan</th>
                                <th class="text-end">Kas Dihitung</th>
                                <th class="text-end">Selisih</th>
                                <th class="text-end">Total POS</th>
                                <th class="text-end">Penjualan Tercatat</th>
                                <th class="text-end">Pembayaran Tercatat</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="reconciliation-tbody">
                            <tr><td colspan="10" class="text-center text-muted py-4">Klik Muat untuk mengambil data.</td></tr>
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
    const btnRefresh = document.getElementById('reconciliation-refresh-btn');
    const inputFrom = document.getElementById('date-from');
    const inputTo = document.getElementById('date-to');
    const tbody = document.getElementById('reconciliation-tbody');

    const apiEndpoint = '{{ route("pos.reconciliation.sessions") }}';

    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    };

    const formatDate = (isoString) => {
        if (!isoString) return '-';
        const d = new Date(isoString);
        return d.toLocaleString('id-ID', { 
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute:'2-digit'
        });
    };

    const loadData = async () => {
        const dateFrom = inputFrom.value;
        const dateTo = inputTo.value;

        if (!dateFrom || !dateTo) return;

        btnRefresh.disabled = true;
        btnRefresh.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Memuat';
        tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4"><i class="fas fa-spinner fa-spin text-primary fs-3"></i></td></tr>';

        try {
            const response = await fetch(`${apiEndpoint}?date_from=${dateFrom}&date_to=${dateTo}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                let msg = 'Gagal memuat data.';
                if (response.status === 422) {
                    const errs = await response.json();
                    msg = Object.values(errs.errors).flat().join('\n');
                }
                throw new Error(msg);
            }

            const data = await response.json();
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">Tidak ada sesi tutup pada rentang tanggal.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map(row => {
                const trClass = row.has_mismatch ? 'table-danger' : '';
                const badge = row.has_mismatch 
                    ? `<span class="badge bg-danger" title="${row.mismatch_details}">Tidak Cocok</span>` 
                    : `<span class="badge bg-success">Sesuai</span>`;
                
                return `
                    <tr class="${trClass}">
                        <td>
                            <div class="fw-bold">ID: ${row.session_id}</div>
                            <div class="small text-muted">${row.terminal_name}</div>
                            <div class="small text-muted">Oleh: ${row.cashier_name}</div>
                        </td>
                        <td class="small">
                            Buka: ${formatDate(row.opened_at)}<br>
                            Tutup: ${formatDate(row.closed_at)}
                        </td>
                        <td class="text-end">${formatCurrency(row.opening_float)}</td>
                        <td class="text-end fw-bold">${formatCurrency(row.expected_cash)}<br>
                            <span class="badge bg-secondary">Setoran: ${formatCurrency(row.safe_drop_total)}</span>
                        </td>
                        <td class="text-end">${formatCurrency(row.counted_cash)}</td>
                        <td class="text-end ${row.variance < 0 ? 'text-danger fw-bold' : (row.variance > 0 ? 'text-success fw-bold' : '')}">
                            ${formatCurrency(row.variance)}
                        </td>
                        <td class="text-end fw-bold">${formatCurrency(row.pos_checkout_total)}<br>
                            <span class="small text-muted text-nowrap">
                                <i class="fas fa-money-bill"></i> ${formatCurrency(row.pos_cash_sales_total)}<br>
                                <i class="fas fa-credit-card"></i> ${formatCurrency(row.pos_non_cash_sales_total)}
                            </span>
                        </td>
                        <td class="text-end ${row.has_mismatch && row.pos_checkout_total !== row.posted_sales_total ? 'text-danger fw-bold' : ''}">
                            ${formatCurrency(row.posted_sales_total)}
                        </td>
                        <td class="text-end ${row.has_mismatch && row.pos_checkout_total !== row.posted_payments_total ? 'text-danger fw-bold' : ''}">
                            ${formatCurrency(row.posted_payments_total)}
                        </td>
                        <td class="text-center align-middle">${badge}</td>
                    </tr>
                `;
            }).join('');

        } catch (error) {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger py-4"><i class="fas fa-exclamation-circle"></i> ${error.message}</td></tr>`;
        } finally {
            btnRefresh.disabled = false;
            btnRefresh.innerHTML = '<i class="fas fa-sync-alt"></i> Muat';
        }
    };

    btnRefresh.addEventListener('click', loadData);
    
    // Auto load on init
    loadData();
});
</script>
@endpush
