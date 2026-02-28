@extends('layouts.app')

@section('title', 'POS Reports')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0 text-gray-800">POS Reports</h1>
            <div class="d-flex align-items-center">
                <div class="input-group input-group-sm me-2">
                    <span class="input-group-text">From</span>
                    <input type="date" id="date-from" class="form-control" value="{{ now()->format('Y-m-d') }}">
                    <span class="input-group-text">To</span>
                    <input type="date" id="date-to" class="form-control" value="{{ now()->format('Y-m-d') }}">
                </div>
                <button id="report-refresh-btn" class="btn btn-sm btn-outline-primary whitespace-nowrap">
                    <i class="fas fa-sync-alt"></i> Load
                </button>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header border-bottom-0">
                <ul class="nav nav-tabs card-header-tabs" id="reportTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="tab-daily-sales" data-bs-toggle="tab" data-bs-target="#content-daily-sales" type="button" role="tab">Daily Sales</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-cashiers" data-bs-toggle="tab" data-bs-target="#content-cashiers" type="button" role="tab">Cashier Summary</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-payments" data-bs-toggle="tab" data-bs-target="#content-payments" type="button" role="tab">Payment Methods</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-items" data-bs-toggle="tab" data-bs-target="#content-items" type="button" role="tab">Item Sales</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-approvals" data-bs-toggle="tab" data-bs-target="#content-approvals" type="button" role="tab">Supervisor Approvals</button>
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
                                        <th>Date</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">Tax</th>
                                        <th class="text-end">Grand Total</th>
                                        <th class="text-end">Cash</th>
                                        <th class="text-end">Non-Cash</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-daily-sales">
                                    <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
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
                                        <th>Cashier</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-end">Grand Total</th>
                                        <th class="text-end">Cash</th>
                                        <th class="text-end">Non-Cash</th>
                                        <th class="text-end">Avg Basket</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-cashiers">
                                    <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
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
                                        <th>Method Code</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-end">Grand Total</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-payments">
                                    <tr><td colspan="3" class="text-center text-muted py-4">Loading...</td></tr>
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
                                        <th>Product Code</th>
                                        <th>Product Name</th>
                                        <th class="text-center">Qty Sold</th>
                                        <th class="text-end">Gross Revenue</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-items">
                                    <tr><td colspan="4" class="text-center text-muted py-4">Loading...</td></tr>
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
                                        <th>Time</th>
                                        <th>Action</th>
                                        <th>Result</th>
                                        <th>Requester</th>
                                        <th>Approver</th>
                                        <th>Reason/Note</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-approvals">
                                    <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
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
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No data found in date range.</td></tr>';
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
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No data found in date range.</td></tr>';
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
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">No data found in date range.</td></tr>';
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
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No data found in date range.</td></tr>';
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
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No data found in date range.</td></tr>';
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

    let activeTabId = 'daily-sales'; // Default

    const loadDataForTab = (tabId) => {
        const url = new URL(endpoints[tabId]);
        url.searchParams.append('date_from', inputFrom.value);
        url.searchParams.append('date_to', inputTo.value);

        const tbody = document.getElementById(`tbody-${tabId}`);
        tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i> Loading ${tabId.replace('-', ' ')}...</td></tr>`;
        btnRefresh.disabled = true;

        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('API request failed');
            return res.json();
        })
        .then(data => {
            renderers[tabId](data, tbody);
            btnRefresh.disabled = false;
        })
        .catch(err => {
            console.error('Fetch error:', err);
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4">Error loading data. Try again.</td></tr>';
            btnRefresh.disabled = false;
        });
    };

    const loadAllTabs = () => {
        // Load the currently visible tab first, then the others in the background
        const tabs = Object.keys(endpoints);
        loadDataForTab(activeTabId);

        tabs.forEach(tab => {
            if (tab !== activeTabId) {
                loadDataForTab(tab);
            }
        });
    };

    // Bind events
    btnRefresh.addEventListener('click', loadAllTabs);

    // Bootstrap tab change events
    const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabEls.forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', event => {
            const targetId = event.target.getAttribute('data-bs-target').replace('#content-', '');
            activeTabId = targetId;
            // Optionally reload on tab switch, or assume already loaded by loadAllTabs
        });
    });

    // Initial load
    loadAllTabs();
});
</script>
@endpush
