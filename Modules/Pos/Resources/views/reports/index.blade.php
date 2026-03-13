@extends('layouts.app')

@section('title', 'Laporan POS')

@push('page_css')
    <style>
        .pos-report-shell {
            --report-bg: #f3f5f9;
            --report-card: #ffffff;
            --report-text: #1f2937;
            --report-muted: #6b7280;
            --report-accent: #0f4c81;
            --report-accent-soft: #e8f1fb;
            --report-success: #0f766e;
            --report-border: #dbe3ef;
            background: linear-gradient(180deg, #eef3fb 0%, #f7f9fc 100%);
            padding-top: 0.25rem;
            padding-bottom: 1rem;
        }

        .pos-report-hero {
            border: 1px solid var(--report-border);
            background: linear-gradient(125deg, #ffffff 0%, #f6fbff 48%, #edf4ff 100%);
        }

        .pos-report-hero h1 {
            color: var(--report-text);
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .pos-report-subtitle {
            color: var(--report-muted);
            margin-bottom: 0;
        }

        .pos-report-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: flex-end;
            align-items: flex-end;
        }

        .pos-report-date-group {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .pos-report-date-field {
            min-width: 160px;
        }

        .pos-report-date-field label {
            display: block;
            color: var(--report-muted);
            font-size: 0.78rem;
            margin-bottom: 0.2rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .pos-report-meta {
            margin-top: 0.75rem;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            font-size: 0.85rem;
        }

        #reports-last-updated {
            color: var(--report-muted);
        }

        #reports-load-status {
            color: var(--report-muted);
            font-weight: 600;
        }

        .pos-report-kpis {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .pos-report-kpi {
            background: var(--report-card);
            border: 1px solid var(--report-border);
            border-radius: 10px;
            padding: 0.75rem;
            min-height: 96px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .pos-report-kpi .kpi-label {
            color: var(--report-muted);
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .pos-report-kpi .kpi-value {
            color: var(--report-text);
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1.2;
            word-break: break-word;
        }

        .pos-report-kpi .kpi-hint {
            color: var(--report-muted);
            font-size: 0.75rem;
        }

        .pos-report-kpi.kpi-primary {
            background: linear-gradient(135deg, #123f70 0%, #255f97 100%);
            border-color: #123f70;
        }

        .pos-report-kpi.kpi-primary .kpi-label,
        .pos-report-kpi.kpi-primary .kpi-value,
        .pos-report-kpi.kpi-primary .kpi-hint {
            color: #f8fbff;
        }

        .pos-report-tabs-card {
            border: 1px solid var(--report-border);
            background: #fff;
        }

        .pos-report-tabs-card .card-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--report-border);
        }

        .pos-report-tabs-card .nav-link {
            font-weight: 600;
            color: #4b5563;
            border: 0;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }

        .pos-report-tabs-card .nav-link.active {
            color: var(--report-accent);
            border-bottom-color: var(--report-accent);
            background: transparent;
        }

        .pos-report-table thead th {
            background: #f8fafc;
            border-bottom: 1px solid var(--report-border);
            color: #374151;
            font-size: 0.78rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .pos-report-table tbody td {
            border-top: 1px solid #edf1f7;
            vertical-align: middle;
            color: #1f2937;
        }

        .pos-report-loading {
            color: #64748b;
            font-weight: 500;
        }

        .pos-report-empty {
            color: #6b7280;
            font-weight: 500;
        }

        .pos-report-error {
            color: #b42318;
            font-weight: 600;
        }

        @media (max-width: 1200px) {
            .pos-report-kpis {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 992px) {
            .pos-report-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .pos-report-toolbar {
                justify-content: flex-start;
            }
        }

        @media (max-width: 576px) {
            .pos-report-kpis {
                grid-template-columns: 1fr;
            }

            .pos-report-date-field {
                min-width: 100%;
            }

            .pos-report-date-group {
                width: 100%;
            }

            .pos-report-toolbar {
                width: 100%;
            }
        }
    </style>
@endpush

@section('content')
    <div class="container-fluid pos-report-shell">
        <div class="card shadow-sm mb-3 pos-report-hero">
            <div class="card-body">
                <div class="row align-items-end">
                    <div class="col-xl-4 mb-3 mb-xl-0">
                        <h1 class="h3 mb-1">Laporan POS</h1>
                        <p class="pos-report-subtitle">Ringkasan performa penjualan kasir dengan tampilan KPI dan detail per area.</p>
                    </div>
                    <div class="col-xl-8">
                        <div class="pos-report-toolbar">
                            <div class="pos-report-date-group">
                                <div class="pos-report-date-field">
                                    <label for="date-from">Dari Tanggal</label>
                                    <input type="date" id="date-from" class="form-control form-control-sm" value="{{ now()->format('Y-m-d') }}">
                                </div>
                                <div class="pos-report-date-field">
                                    <label for="date-to">Sampai Tanggal</label>
                                    <input type="date" id="date-to" class="form-control form-control-sm" value="{{ now()->format('Y-m-d') }}">
                                </div>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Preset tanggal laporan">
                                <button type="button" class="btn btn-outline-secondary js-report-range" data-range="today">Hari Ini</button>
                                <button type="button" class="btn btn-outline-secondary js-report-range" data-range="7d">7 Hari</button>
                                <button type="button" class="btn btn-outline-secondary js-report-range" data-range="30d">30 Hari</button>
                            </div>
                            <button id="report-refresh-btn" class="btn btn-sm btn-primary">
                                <i class="fas fa-sync-alt"></i> Muat
                            </button>
                        </div>
                    </div>
                </div>

                <div class="pos-report-meta">
                    <span id="reports-last-updated">Belum diperbarui</span>
                    <span id="reports-load-status">Siap memuat laporan.</span>
                </div>

                <div class="pos-report-kpis" id="reports-kpi-grid">
                    <div class="pos-report-kpi kpi-primary">
                        <div class="kpi-label">Total Penjualan</div>
                        <div class="kpi-value" id="kpi-grand-total">Rp0</div>
                        <div class="kpi-hint">Akumulasi grand total</div>
                    </div>
                    <div class="pos-report-kpi">
                        <div class="kpi-label">Total Transaksi</div>
                        <div class="kpi-value" id="kpi-transactions">0</div>
                        <div class="kpi-hint">Jumlah transaksi posted</div>
                    </div>
                    <div class="pos-report-kpi">
                        <div class="kpi-label">Rata-rata Basket</div>
                        <div class="kpi-value" id="kpi-average-basket">Rp0</div>
                        <div class="kpi-hint">Grand total / transaksi</div>
                    </div>
                    <div class="pos-report-kpi">
                        <div class="kpi-label">Rasio Tunai</div>
                        <div class="kpi-value" id="kpi-cash-ratio">0%</div>
                        <div class="kpi-hint">Proporsi tunai dari total</div>
                    </div>
                    <div class="pos-report-kpi">
                        <div class="kpi-label">Persetujuan Supervisor</div>
                        <div class="kpi-value" id="kpi-approvals">0</div>
                        <div class="kpi-hint">Jumlah approval pada periode</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm pos-report-tabs-card">
            <div class="card-header border-bottom-0">
                <ul class="nav nav-tabs card-header-tabs" id="reportTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="tab-daily-sales" data-toggle="tab" data-target="#content-daily-sales" data-bs-toggle="tab" data-bs-target="#content-daily-sales" type="button" role="tab">Penjualan Harian</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-cashiers" data-toggle="tab" data-target="#content-cashiers" data-bs-toggle="tab" data-bs-target="#content-cashiers" type="button" role="tab">Ringkasan Kasir</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-payments" data-toggle="tab" data-target="#content-payments" data-bs-toggle="tab" data-bs-target="#content-payments" type="button" role="tab">Metode Pembayaran</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-items" data-toggle="tab" data-target="#content-items" data-bs-toggle="tab" data-bs-target="#content-items" type="button" role="tab">Penjualan Produk</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-approvals" data-toggle="tab" data-target="#content-approvals" data-bs-toggle="tab" data-bs-target="#content-approvals" type="button" role="tab">Persetujuan Supervisor</button>
                    </li>
                </ul>
            </div>
            <div class="card-body p-0">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="content-daily-sales" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 pos-report-table">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th class="text-center">Transaksi</th>
                                        <th class="text-right">Subtotal</th>
                                        <th class="text-right">Diskon</th>
                                        <th class="text-right">Pajak</th>
                                        <th class="text-right">Total Akhir</th>
                                        <th class="text-right">Tunai</th>
                                        <th class="text-right">Non-Tunai</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-daily-sales">
                                    <tr><td colspan="8" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="content-cashiers" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 pos-report-table">
                                <thead>
                                    <tr>
                                        <th>Kasir</th>
                                        <th class="text-center">Transaksi</th>
                                        <th class="text-right">Total Akhir</th>
                                        <th class="text-right">Tunai</th>
                                        <th class="text-right">Non-Tunai</th>
                                        <th class="text-right">Rata-rata Keranjang</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-cashiers">
                                    <tr><td colspan="6" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="content-payments" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 pos-report-table">
                                <thead>
                                    <tr>
                                        <th>Metode Pembayaran</th>
                                        <th class="text-center">Transaksi</th>
                                        <th class="text-right">Total Akhir</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-payments">
                                    <tr><td colspan="3" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="content-items" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 pos-report-table">
                                <thead>
                                    <tr>
                                        <th>Kode Produk</th>
                                        <th>Nama Produk</th>
                                        <th class="text-center">Qty Terjual</th>
                                        <th class="text-right">Pendapatan Kotor</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-items">
                                    <tr><td colspan="4" class="text-center text-muted py-4">Memuat...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="content-approvals" role="tabpanel" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 pos-report-table">
                                <thead>
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

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const btnRefresh = document.getElementById('report-refresh-btn');
            const inputFrom = document.getElementById('date-from');
            const inputTo = document.getElementById('date-to');
            const statusLabel = document.getElementById('reports-load-status');
            const updatedLabel = document.getElementById('reports-last-updated');
            const presetButtons = Array.from(document.querySelectorAll('.js-report-range'));

            const kpiGrandTotal = document.getElementById('kpi-grand-total');
            const kpiTransactions = document.getElementById('kpi-transactions');
            const kpiAverageBasket = document.getElementById('kpi-average-basket');
            const kpiCashRatio = document.getElementById('kpi-cash-ratio');
            const kpiApprovals = document.getElementById('kpi-approvals');

            if (!btnRefresh || !inputFrom || !inputTo) {
                return;
            }

            const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0,
            }).format(Number(value || 0));

            const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(Number(value || 0));

            const formatPercent = (value) => `${new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 1,
            }).format(Number(value || 0))}%`;

            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const formatDate = (value) => {
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) {
                    return String(value || '-');
                }

                return date.toLocaleDateString('id-ID', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                });
            };

            const formatDateTime = (value) => {
                const date = new Date(value);
                if (Number.isNaN(date.getTime())) {
                    return String(value || '-');
                }

                return date.toLocaleString('id-ID');
            };

            const endpoints = {
                'daily-sales': @json(route('pos.reports.daily-sales')),
                'cashiers': @json(route('pos.reports.cashier-summary')),
                'payments': @json(route('pos.reports.payment-methods')),
                'items': @json(route('pos.reports.item-sales')),
                'approvals': @json(route('pos.reports.supervisor-approvals')),
            };

            const tabLabelMap = {
                'daily-sales': 'penjualan harian',
                'cashiers': 'ringkasan kasir',
                'payments': 'metode pembayaran',
                'items': 'penjualan produk',
                'approvals': 'persetujuan supervisor',
            };

            const tabColumnCount = {
                'daily-sales': 8,
                'cashiers': 6,
                'payments': 3,
                'items': 4,
                'approvals': 6,
            };

            let activeTabId = 'daily-sales';
            const tabButtons = Array.from(document.querySelectorAll('#reportTabs .nav-link'));
            const tabPanes = Array.from(document.querySelectorAll('.tab-content .tab-pane'));

            const resolveTabTarget = (tabButton) => (
                tabButton.getAttribute('data-bs-target')
                || tabButton.getAttribute('data-target')
                || tabButton.getAttribute('href')
                || ''
            );

            const activateTab = (tabButton) => {
                const targetSelector = resolveTabTarget(tabButton);
                if (!targetSelector) {
                    return;
                }

                const targetPane = document.querySelector(targetSelector);
                if (!targetPane) {
                    return;
                }

                tabButtons.forEach((button) => {
                    button.classList.remove('active');
                    button.setAttribute('aria-selected', 'false');
                });

                tabPanes.forEach((pane) => {
                    pane.classList.remove('show', 'active');
                });

                tabButton.classList.add('active');
                tabButton.setAttribute('aria-selected', 'true');
                targetPane.classList.add('show', 'active');

                activeTabId = targetSelector.replace('#content-', '') || 'daily-sales';
            };

            const setGlobalStatus = (message, tone = 'muted') => {
                statusLabel.textContent = message || '';
                statusLabel.classList.remove('text-muted', 'text-success', 'text-danger');
                statusLabel.classList.add(tone === 'danger' ? 'text-danger' : (tone === 'success' ? 'text-success' : 'text-muted'));
            };

            const setControlState = (isLoading) => {
                btnRefresh.disabled = Boolean(isLoading);
                inputFrom.disabled = Boolean(isLoading);
                inputTo.disabled = Boolean(isLoading);
                presetButtons.forEach((button) => {
                    button.disabled = Boolean(isLoading);
                });
            };

            const setTableState = (tabId, type, message) => {
                const tbody = document.getElementById(`tbody-${tabId}`);
                if (!tbody) {
                    return;
                }

                const colSpan = Number(tabColumnCount[tabId] || 3);
                const stateClass = type === 'error'
                    ? 'pos-report-error'
                    : (type === 'empty' ? 'pos-report-empty' : 'pos-report-loading');

                const text = message || (type === 'loading'
                    ? `Memuat ${tabLabelMap[tabId] || tabId}...`
                    : (type === 'empty'
                        ? 'Tidak ada data pada rentang tanggal.'
                        : 'Gagal memuat data.'));

                tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center py-4 ${stateClass}">${escapeHtml(text)}</td></tr>`;
            };

            const renderers = {
                'daily-sales': (data, tbody) => {
                    if (!Array.isArray(data) || data.length === 0) {
                        setTableState('daily-sales', 'empty', 'Tidak ada data penjualan harian pada rentang tanggal ini.');
                        return;
                    }

                    tbody.innerHTML = data.map((row) => `
                        <tr>
                            <td><strong>${escapeHtml(formatDate(row.date))}</strong></td>
                            <td class="text-center">${formatNumber(row.transactions_count)}</td>
                            <td class="text-right">${formatCurrency(row.subtotal)}</td>
                            <td class="text-right text-danger">${formatCurrency(row.discount_total)}</td>
                            <td class="text-right">${formatCurrency(row.tax_total)}</td>
                            <td class="text-right font-weight-bold">${formatCurrency(row.grand_total)}</td>
                            <td class="text-right text-success">${formatCurrency(row.cash_total)}</td>
                            <td class="text-right text-primary">${formatCurrency(row.non_cash_total)}</td>
                        </tr>
                    `).join('');
                },
                'cashiers': (data, tbody) => {
                    if (!Array.isArray(data) || data.length === 0) {
                        setTableState('cashiers', 'empty', 'Tidak ada ringkasan kasir pada rentang tanggal ini.');
                        return;
                    }

                    tbody.innerHTML = data.map((row) => `
                        <tr>
                            <td><strong>${escapeHtml(row.cashier_name || '-')}</strong></td>
                            <td class="text-center">${formatNumber(row.transactions_count)}</td>
                            <td class="text-right font-weight-bold">${formatCurrency(row.grand_total)}</td>
                            <td class="text-right text-success">${formatCurrency(row.cash_total)}</td>
                            <td class="text-right text-primary">${formatCurrency(row.non_cash_total)}</td>
                            <td class="text-right text-muted">${formatCurrency(row.average_basket)}</td>
                        </tr>
                    `).join('');
                },
                'payments': (data, tbody) => {
                    if (!Array.isArray(data) || data.length === 0) {
                        setTableState('payments', 'empty', 'Tidak ada transaksi metode pembayaran pada rentang tanggal ini.');
                        return;
                    }

                    tbody.innerHTML = data.map((row) => `
                        <tr>
                            <td><strong>${escapeHtml(row.payment_method_label || '-')}</strong></td>
                            <td class="text-center">${formatNumber(row.transactions_count)}</td>
                            <td class="text-right font-weight-bold">${formatCurrency(row.grand_total)}</td>
                        </tr>
                    `).join('');
                },
                'items': (data, tbody) => {
                    if (!Array.isArray(data) || data.length === 0) {
                        setTableState('items', 'empty', 'Tidak ada data penjualan produk pada rentang tanggal ini.');
                        return;
                    }

                    tbody.innerHTML = data.map((row) => `
                        <tr>
                            <td><small class="text-muted">${escapeHtml(row.product_code || '-')}</small></td>
                            <td><strong>${escapeHtml(row.product_name || '-')}</strong></td>
                            <td class="text-center">${formatNumber(row.quantity_sold)}</td>
                            <td class="text-right font-weight-bold">${formatCurrency(row.gross_revenue)}</td>
                        </tr>
                    `).join('');
                },
                'approvals': (data, tbody) => {
                    if (!Array.isArray(data) || data.length === 0) {
                        setTableState('approvals', 'empty', 'Tidak ada log persetujuan supervisor pada rentang tanggal ini.');
                        return;
                    }

                    tbody.innerHTML = data.map((row) => {
                        const badgeClass = row.approval_result === 'APPROVED' ? 'badge-success' : 'badge-danger';
                        return `
                            <tr>
                                <td>${escapeHtml(formatDateTime(row.occurred_at))}</td>
                                <td><strong>${escapeHtml(String(row.action_type || '-').replace(/_/g, ' '))}</strong></td>
                                <td><span class="badge ${badgeClass}">${escapeHtml(row.approval_result || '-')}</span></td>
                                <td>${escapeHtml(row.requester_name || '-')}</td>
                                <td>${escapeHtml(row.approver_name || '-')}</td>
                                <td class="text-muted">${escapeHtml(row.reason || '-')}</td>
                            </tr>
                        `;
                    }).join('');
                },
            };

            const updateKpis = (dataset) => {
                const dailyRows = Array.isArray(dataset['daily-sales']) ? dataset['daily-sales'] : [];
                const approvalsRows = Array.isArray(dataset.approvals) ? dataset.approvals : [];

                const totals = dailyRows.reduce((carry, row) => ({
                    grandTotal: carry.grandTotal + Number(row.grand_total || 0),
                    transactions: carry.transactions + Number(row.transactions_count || 0),
                    cashTotal: carry.cashTotal + Number(row.cash_total || 0),
                }), {
                    grandTotal: 0,
                    transactions: 0,
                    cashTotal: 0,
                });

                const averageBasket = totals.transactions > 0 ? totals.grandTotal / totals.transactions : 0;
                const cashRatio = totals.grandTotal > 0 ? (totals.cashTotal / totals.grandTotal) * 100 : 0;

                if (kpiGrandTotal) kpiGrandTotal.textContent = formatCurrency(totals.grandTotal);
                if (kpiTransactions) kpiTransactions.textContent = formatNumber(totals.transactions);
                if (kpiAverageBasket) kpiAverageBasket.textContent = formatCurrency(averageBasket);
                if (kpiCashRatio) kpiCashRatio.textContent = formatPercent(cashRatio);
                if (kpiApprovals) kpiApprovals.textContent = formatNumber(approvalsRows.length);
            };

            const loadDataForTab = async (tabId) => {
                const endpoint = endpoints[tabId];
                const tbody = document.getElementById(`tbody-${tabId}`);
                if (!endpoint || !tbody) {
                    return [];
                }

                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('date_from', inputFrom.value);
                url.searchParams.set('date_to', inputTo.value);

                setTableState(tabId, 'loading', `Memuat ${tabLabelMap[tabId] || tabId}...`);

                const response = await fetch(url.toString(), {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (error) {
                    payload = null;
                }

                if (!response.ok) {
                    const message = payload && payload.message
                        ? payload.message
                        : `Gagal memuat ${tabLabelMap[tabId] || tabId}.`;
                    setTableState(tabId, 'error', message);
                    throw new Error(message);
                }

                const data = Array.isArray(payload) ? payload : [];
                renderers[tabId](data, tbody);

                return data;
            };

            const loadAllTabs = async () => {
                const dateFrom = (inputFrom.value || '').trim();
                const dateTo = (inputTo.value || '').trim();

                if (dateFrom === '' || dateTo === '') {
                    setGlobalStatus('Rentang tanggal wajib diisi.', 'danger');
                    return;
                }

                if (dateFrom > dateTo) {
                    setGlobalStatus('Rentang tanggal tidak valid: Dari Tanggal harus sebelum atau sama dengan Sampai Tanggal.', 'danger');
                    return;
                }

                setControlState(true);
                setGlobalStatus('Memuat seluruh data laporan...', 'muted');

                const tabIds = Object.keys(endpoints);
                const dataset = {};
                const failures = [];

                const settled = await Promise.allSettled(tabIds.map((tabId) => loadDataForTab(tabId)));

                settled.forEach((result, index) => {
                    const tabId = tabIds[index];
                    if (result.status === 'fulfilled') {
                        dataset[tabId] = result.value;
                    } else {
                        dataset[tabId] = [];
                        failures.push({ tabId, error: result.reason });
                    }
                });

                updateKpis(dataset);

                if (failures.length > 0) {
                    const activeFailure = failures.find((item) => item.tabId === activeTabId);
                    if (activeFailure) {
                        setGlobalStatus(`Gagal memuat ${tabLabelMap[activeTabId] || activeTabId}. Klik Muat untuk mencoba lagi.`, 'danger');
                    } else {
                        setGlobalStatus(`Sebagian data gagal dimuat (${failures.length}/${tabIds.length}).`, 'danger');
                    }
                } else {
                    setGlobalStatus('Data laporan berhasil diperbarui.', 'success');
                    updatedLabel.textContent = `Terakhir diperbarui: ${new Date().toLocaleString('id-ID')}`;
                }

                setControlState(false);
            };

            const toDateString = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');

                return `${year}-${month}-${day}`;
            };

            const applyDatePreset = (preset) => {
                const now = new Date();
                const end = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                let start = new Date(end);

                if (preset === '7d') {
                    start.setDate(end.getDate() - 6);
                } else if (preset === '30d') {
                    start.setDate(end.getDate() - 29);
                }

                inputFrom.value = toDateString(start);
                inputTo.value = toDateString(end);
            };

            btnRefresh.addEventListener('click', loadAllTabs);

            presetButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    applyDatePreset(button.getAttribute('data-range'));
                    loadAllTabs();
                });
            });

            tabButtons.forEach((tabButton) => {
                tabButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    activateTab(tabButton);
                });

                tabButton.addEventListener('shown.bs.tab', (event) => {
                    const target = resolveTabTarget(event.target);
                    activeTabId = target.replace('#content-', '') || 'daily-sales';
                });

                tabButton.addEventListener('shown.coreui.tab', (event) => {
                    const target = resolveTabTarget(event.target);
                    activeTabId = target.replace('#content-', '') || 'daily-sales';
                });
            });

            const initiallyActiveTab = tabButtons.find((button) => button.classList.contains('active')) || tabButtons[0];
            if (initiallyActiveTab) {
                activateTab(initiallyActiveTab);
            }

            loadAllTabs();
        });
    </script>
@endpush
