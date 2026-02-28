@extends('layouts.app')

@section('title', 'Laporan POS')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0 text-gray-800">Laporan POS</h1>
            <div class="d-flex align-items-center">
                <div class="input-group input-group-sm me-2">
                    <span class="input-group-text">Dari</span>
                    <input type="date" id="date-from" class="form-control" value="{{ now()->format('Y-m-d') }}">
                    <span class="input-group-text">Sampai</span>
                    <input type="date" id="date-to" class="form-control" value="{{ now()->format('Y-m-d') }}">
                </div>
                <button id="report-refresh-btn" class="btn btn-sm btn-outline-primary whitespace-nowrap">
                    <i class="fas fa-sync-alt"></i> Muat
                </button>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header border-bottom-0">
                <ul class="nav nav-tabs card-header-tabs" id="reportTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="tab-daily-sales" data-bs-toggle="tab" data-bs-target="#content-daily-sales" type="button" role="tab">Penjualan Harian</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-cashiers" data-bs-toggle="tab" data-bs-target="#content-cashiers" type="button" role="tab">Ringkasan Kasir</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-payments" data-bs-toggle="tab" data-bs-target="#content-payments" type="button" role="tab">Metode Pembayaran</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-items" data-bs-toggle="tab" data-bs-target="#content-items" type="button" role="tab">Penjualan Produk</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-approvals" data-bs-toggle="tab" data-bs-target="#content-approvals" type="button" role="tab">Persetujuan Supervisor</button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content">
                    <!-- Daily Sales Tab -->
                    <div class="tab-pane fade show active" id="content-daily-sales" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tanggal</th>
                                        <th class="text-center">Transaksi</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-end">Diskon</th>
                                        <th class="text-end">Pajak</th>
                                        <th class="text-end">Total Akhir</th>
                                        <th class="text-end">Tunai</th>
                                        <th class="text-end">Non-Tunai</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-daily-sales">
                                    <tr><td colspan="8" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Cashier Summary Tab -->
                    <div class="tab-pane fade" id="content-cashiers" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kasir</th>
                                        <th class="text-center">Transaksi</th>
                                        <th class="text-end">Total Akhir</th>
                                        <th class="text-end">Tunai</th>
                                        <th class="text-end">Non-Tunai</th>
                                        <th class="text-end">Rata-rata Keranjang</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-cashiers">
                                    <tr><td colspan="6" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment Methods Tab -->
                    <div class="tab-pane fade" id="content-payments" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode Metode</th>
                                        <th class="text-center">Transaksi</th>
                                        <th class="text-end">Total Akhir</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-payments">
                                    <tr><td colspan="3" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Item Sales Tab -->
                    <div class="tab-pane fade" id="content-items" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode Produk</th>
                                        <th>Nama Produk</th>
                                        <th class="text-center">Qty Terjual</th>
                                        <th class="text-end">Pendapatan Kotor</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-items">
                                    <tr><td colspan="4" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Supervisor Approvals Tab -->
                    <div class="tab-pane fade" id="content-approvals" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Aksi</th>
                                        <th>Hasil</th>
                                        <th>Peminta</th>
                                        <th>Penyetuju</th>
                                        <th>Alasan/Catatan</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-approvals">
                                    <tr><td colspan="6" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnRefresh = document.getElementById('report-refresh-btn');
    const inputFrom = document.getElementById('date-from');
    const inputTo = document.getElementById('date-to');

    const formatCurrency = (value) => {
        return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
    };

    const formatNumber = (value) => {
        return new Intl.NumberFormat('id-ID').format(value);
    };

    // Endpoints mapping
    const endpoints = {
        'daily-sales': '{{ route("pos.reports.daily-sales") }}',
        'cashiers': '{{ route("pos.reports.cashier-summary") }}',
        'payments': '{{ route("pos.reports.payment-methods") }}',
        'items': '{{ route("pos.reports.item-sales") }}',
        'approvals': '{{ route("pos.reports.supervisor-approvals") }}'
    };

    // Renderers mapping
    const renderers = {
        'daily-sales': (data, tbody) => {
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data pada rentang tanggal.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(row => `
                <tr>
                    <td><strong>${row.date}</strong></td>
                    <td class="text-center">${formatNumber(row.transactions_count)}</td>
                    <td class="text-end">${formatCurrency(row.subtotal)}</td>
                    <td class="text-end text-danger">${formatCurrency(row.discount_total)}</td>
                    <td class="text-end">${formatCurrency(row.tax_total)}</td>
                    <td class="text-end fw-bold">${formatCurrency(row.grand_total)}</td>
                    <td class="text-end text-success">${formatCurrency(row.cash_total)}</td>
                    <td class="text-end text-primary">${formatCurrency(row.non_cash_total)}</td>
                </tr>
            `).join('');
        },
        'cashiers': (data, tbody) => {
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Tidak ada data pada rentang tanggal.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(row => `
                <tr>
                    <td><strong>${row.cashier_name}</strong></td>
                    <td class="text-center">${formatNumber(row.transactions_count)}</td>
                    <td class="text-end fw-bold">${formatCurrency(row.grand_total)}</td>
                    <td class="text-end text-success">${formatCurrency(row.cash_total)}</td>
                    <td class="text-end text-primary">${formatCurrency(row.non_cash_total)}</td>
                    <td class="text-end text-muted">${formatCurrency(row.average_basket)}</td>
                </tr>
            `).join('');
        },
        'payments': (data, tbody) => {
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Tidak ada data pada rentang tanggal.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(row => `
                <tr>
                    <td><span class="badge bg-secondary text-uppercase">${row.payment_method_code}</span></td>
                    <td class="text-center">${formatNumber(row.transactions_count)}</td>
                    <td class="text-end fw-bold">${formatCurrency(row.grand_total)}</td>
                </tr>
            `).join('');
        },
        'items': (data, tbody) => {
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Tidak ada data pada rentang tanggal.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(row => `
                <tr>
                    <td><small class="text-muted">${row.product_code}</small></td>
                    <td><strong>${row.product_name}</strong></td>
                    <td class="text-center">${formatNumber(row.quantity_sold)}</td>
                    <td class="text-end fw-bold">${formatCurrency(row.gross_revenue)}</td>
                </tr>
            `).join('');
        },
        'approvals': (data, tbody) => {
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Tidak ada data pada rentang tanggal.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(row => {
                const badgeColor = row.approval_result === 'APPROVED' ? 'bg-success' : 'bg-danger';
                return `
                <tr>
                    <td>${new Date(row.occurred_at).toLocaleString('id-ID')}</td>
                    <td><strong>${row.action_type.replace(/_/g, ' ')}</strong></td>
                    <td><span class="badge ${badgeColor}">${row.approval_result}</span></td>
                    <td>${row.requester_name}</td>
                    <td>${row.approver_name}</td>
                    <td class="text-muted fst-italic">${row.reason || '-'}</td>
                </tr>
            `}).join('');
        }
    };

    let activeTabId = 'daily-sales';
    const tabLabelMap = {
        'daily-sales': 'penjualan harian',
        'cashiers': 'ringkasan kasir',
        'payments': 'metode pembayaran',
        'items': 'penjualan produk',
        'approvals': 'persetujuan supervisor',
    };

    const loadDataForTab = (tabId) => {
        const url = new URL(endpoints[tabId]);
        url.searchParams.append('date_from', inputFrom.value);
        url.searchParams.append('date_to', inputTo.value);

        const tbody = document.getElementById(`tbody-${tabId}`);
        tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i> Memuat ${tabLabelMap[tabId] || tabId}...</td></tr>`;
        btnRefresh.disabled = true;

        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('Permintaan API gagal');
            return res.json();
        })
        .then(data => {
            renderers[tabId](data, tbody);
            btnRefresh.disabled = false;
        })
        .catch(err => {
            console.error('Gagal mengambil data:', err);
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4">Gagal memuat data. Coba lagi.</td></tr>';
            btnRefresh.disabled = false;
        });
    };

    const loadAllTabs = () => {
        const tabs = Object.keys(endpoints);
        loadDataForTab(activeTabId);

        tabs.forEach(tab => {
            if (tab !== activeTabId) {
                loadDataForTab(tab);
            }
        });
    };

    btnRefresh.addEventListener('click', loadAllTabs);

    const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabEls.forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            const targetId = event.target.getAttribute('data-bs-target').replace('#content-', '');
            activeTabId = targetId;
        });
    });

    loadAllTabs();
});
</script>
@endpush
