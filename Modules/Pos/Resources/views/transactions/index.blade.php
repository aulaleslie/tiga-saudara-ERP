@extends('layouts.app')

@section('title', 'Transaksi POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Transaksi POS</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label for="pos-transaction-search" class="small text-muted mb-1">Cari Kode</label>
                        <input id="pos-transaction-search" type="text" class="form-control" placeholder="Contoh: DOC-TXN-2026-03-0001">
                    </div>
                    <div class="col-md-2">
                        <label for="pos-transaction-status" class="small text-muted mb-1">Status</label>
                        <select id="pos-transaction-status" class="form-control">
                            <option value="">Semua</option>
                            <option value="DRAFT">DRAFT</option>
                            <option value="LOADED">LOADED</option>
                            <option value="COMPLETED">COMPLETED</option>
                            <option value="CANCELLED">CANCELLED</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="pos-transaction-date-from" class="small text-muted mb-1">Dari Tanggal</label>
                        <input id="pos-transaction-date-from" type="date" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label for="pos-transaction-date-to" class="small text-muted mb-1">Sampai Tanggal</label>
                        <input id="pos-transaction-date-to" type="date" class="form-control">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button id="pos-transaction-filter" type="button" class="btn btn-primary">Muat Data</button>
                        @can('pos.sell')
                            <a href="{{ route('pos.sell') }}" class="btn btn-outline-secondary">Buka POS Kasir</a>
                        @endcan
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div id="pos-transaction-status-note" class="small text-muted mb-2"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>Kode</th>
                            <th>Status</th>
                            <th>Pemilik</th>
                            <th>Pelanggan</th>
                            <th class="text-right">Grand Total</th>
                            <th>Diperbarui</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                        </thead>
                        <tbody id="pos-transaction-table-body">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Memuat data...</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <small id="pos-transaction-pagination-label" class="text-muted"></small>
                    <div class="btn-group btn-group-sm">
                        <button id="pos-transaction-prev" type="button" class="btn btn-outline-secondary" disabled>Sebelumnya</button>
                        <button id="pos-transaction-next" type="button" class="btn btn-outline-secondary" disabled>Berikutnya</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        (function () {
            const tableBody = document.getElementById('pos-transaction-table-body');
            const statusNote = document.getElementById('pos-transaction-status-note');
            const filterButton = document.getElementById('pos-transaction-filter');
            const searchInput = document.getElementById('pos-transaction-search');
            const statusSelect = document.getElementById('pos-transaction-status');
            const dateFromInput = document.getElementById('pos-transaction-date-from');
            const dateToInput = document.getElementById('pos-transaction-date-to');
            const prevButton = document.getElementById('pos-transaction-prev');
            const nextButton = document.getElementById('pos-transaction-next');
            const paginationLabel = document.getElementById('pos-transaction-pagination-label');

            const dataEndpoint = @json(route('pos.transactions.data'));
            const transactionsBaseUrl = @json(url('/pos/transactions'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const canLoad = @json(auth()->user()->can('pos.sell') && auth()->user()->can('pos.transactions.load'));
            const canEditAny = @json(auth()->user()->can('pos.transactions.edit.any'));
            const currentUserId = @json((int) auth()->id());

            let page = 1;
            let pagination = null;
            let latestLoadToken = 0;

            const formatCurrency = (value) => {
                const amount = Number(value || 0);
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    maximumFractionDigits: 0,
                }).format(Number.isFinite(amount) ? amount : 0);
            };

            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const jsonRequest = async (url, method = 'GET', payload = null) => {
                const options = {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                };

                if (method !== 'GET') {
                    options.headers['X-CSRF-TOKEN'] = csrfToken;
                    options.headers['Content-Type'] = 'application/json';
                }

                if (payload !== null) {
                    options.body = JSON.stringify(payload);
                }

                const response = await fetch(url, options);
                let body = null;
                try {
                    body = await response.json();
                } catch (error) {
                    body = null;
                }

                if (!response.ok) {
                    throw new Error(body && body.message ? body.message : 'Permintaan gagal diproses.');
                }

                return body;
            };

            const statusBadgeClass = (status) => {
                if (status === 'COMPLETED') return 'badge-success';
                if (status === 'CANCELLED') return 'badge-secondary';
                if (status === 'LOADED') return 'badge-info';
                return 'badge-primary';
            };

            const canCancelRow = (row) => {
                if (!row || (row.status !== 'DRAFT' && row.status !== 'LOADED')) {
                    return false;
                }

                return canEditAny || Number(row.owner_user_id || 0) === Number(currentUserId);
            };

            const buildActions = (row) => {
                const actions = [
                    `<a class="btn btn-sm btn-outline-secondary" href="${transactionsBaseUrl}/${row.id}">Detail</a>`,
                ];

                if (canLoad && (row.status === 'DRAFT' || row.status === 'LOADED')) {
                    actions.push(`<button type="button" class="btn btn-sm btn-primary js-load-transaction" data-id="${row.id}">Muat</button>`);
                }

                if (canCancelRow(row)) {
                    actions.push(`<button type="button" class="btn btn-sm btn-outline-danger js-cancel-transaction" data-id="${row.id}">Batalkan</button>`);
                }

                return actions.join(' ');
            };

            const renderLoadingState = (message = 'Memuat transaksi...') => {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">${escapeHtml(message)}</td></tr>`;
            };

            const renderEmptyState = (message = 'Tidak ada transaksi pada filter saat ini.') => {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">${escapeHtml(message)}</td></tr>`;
            };

            const renderErrorState = (message = 'Gagal memuat transaksi. Silakan klik Muat Data untuk mencoba lagi.') => {
                tableBody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">${escapeHtml(message)}</td></tr>`;
            };

            const renderRows = (rows) => {
                tableBody.innerHTML = rows.map((row) => {
                    const ownerName = row.owner && row.owner.name ? row.owner.name : '-';
                    const customerName = row.customer && row.customer.customer_name ? row.customer.customer_name : '-';
                    const grandTotal = row.snapshot_totals && row.snapshot_totals.grand_total
                        ? row.snapshot_totals.grand_total
                        : 0;
                    const updatedAt = row.updated_at ? new Date(row.updated_at).toLocaleString('id-ID') : '-';

                    return `
                        <tr>
                            <td><strong>${escapeHtml(row.code)}</strong></td>
                            <td><span class="badge ${statusBadgeClass(row.status)}">${escapeHtml(row.status || '-')}</span></td>
                            <td>${escapeHtml(ownerName)}</td>
                            <td>${escapeHtml(customerName)}</td>
                            <td class="text-right">${formatCurrency(grandTotal)}</td>
                            <td>${escapeHtml(updatedAt)}</td>
                            <td class="text-right">${buildActions(row)}</td>
                        </tr>
                    `;
                }).join('');
            };

            const updatePaginationState = () => {
                const currentPage = Number(pagination?.current_page || 1);
                const lastPage = Number(pagination?.last_page || 1);
                const total = Number(pagination?.total || 0);
                prevButton.disabled = currentPage <= 1;
                nextButton.disabled = currentPage >= lastPage;
                paginationLabel.textContent = `Halaman ${currentPage} / ${lastPage} • Total ${total} transaksi`;
            };

            const buildQueryUrl = () => {
                const url = new URL(dataEndpoint, window.location.origin);
                url.searchParams.set('page', String(page));

                const search = (searchInput.value || '').trim();
                if (search !== '') {
                    url.searchParams.set('q', search);
                }

                const status = (statusSelect.value || '').trim();
                if (status !== '') {
                    url.searchParams.set('status[]', status);
                }

                const dateFrom = (dateFromInput.value || '').trim();
                if (dateFrom !== '') {
                    url.searchParams.set('date_from', dateFrom);
                }

                const dateTo = (dateToInput.value || '').trim();
                if (dateTo !== '') {
                    url.searchParams.set('date_to', dateTo);
                }

                return url.toString();
            };

            const setStatus = (message, tone = 'muted') => {
                statusNote.textContent = message || '';
                statusNote.classList.remove('text-danger', 'text-success', 'text-muted');
                statusNote.classList.add(tone === 'danger' ? 'text-danger' : (tone === 'success' ? 'text-success' : 'text-muted'));
            };

            const setLoadingControls = (isLoading) => {
                filterButton.disabled = Boolean(isLoading);
                searchInput.disabled = Boolean(isLoading);
                statusSelect.disabled = Boolean(isLoading);
                dateFromInput.disabled = Boolean(isLoading);
                dateToInput.disabled = Boolean(isLoading);
                prevButton.disabled = true;
                nextButton.disabled = true;
            };

            const loadRows = async ({ showLoading = true } = {}) => {
                const loadToken = ++latestLoadToken;
                if (showLoading) {
                    renderLoadingState();
                }
                setLoadingControls(true);
                setStatus('Memuat transaksi...', 'muted');
                try {
                    const response = await jsonRequest(buildQueryUrl(), 'GET');
                    if (loadToken !== latestLoadToken) {
                        return;
                    }
                    pagination = response.pagination || null;
                    const rows = Array.isArray(response.data) ? response.data : [];
                    if (rows.length === 0) {
                        renderEmptyState();
                    } else {
                        renderRows(rows);
                    }
                    updatePaginationState();
                    if (rows.length === 0) {
                        setStatus('Tidak ada transaksi pada filter saat ini.', 'muted');
                    } else {
                        const total = Number(pagination?.total || rows.length);
                        setStatus(`Menampilkan ${rows.length} transaksi (total ${total}).`, 'success');
                    }
                } catch (error) {
                    if (loadToken !== latestLoadToken) {
                        return;
                    }
                    pagination = {
                        current_page: 1,
                        last_page: 1,
                        total: 0,
                    };
                    renderErrorState();
                    updatePaginationState();
                    setStatus(error.message || 'Gagal memuat transaksi.', 'danger');
                } finally {
                    if (loadToken === latestLoadToken) {
                        setLoadingControls(false);
                        updatePaginationState();
                    }
                }
            };

            tableBody.addEventListener('click', async (event) => {
                const loadButton = event.target.closest('.js-load-transaction');
                if (loadButton) {
                    const id = Number(loadButton.getAttribute('data-id') || 0);
                    if (id <= 0) return;

                    loadButton.disabled = true;
                    try {
                        await jsonRequest(`${transactionsBaseUrl}/${id}/load`, 'POST', {});
                        window.location.href = @json(route('pos.sell'));
                    } catch (error) {
                        setStatus(error.message || 'Gagal memuat transaksi.', 'danger');
                    } finally {
                        loadButton.disabled = false;
                    }
                    return;
                }

                const cancelButton = event.target.closest('.js-cancel-transaction');
                if (cancelButton) {
                    const id = Number(cancelButton.getAttribute('data-id') || 0);
                    if (id <= 0) return;
                    if (!confirm('Batalkan transaksi ini?')) return;

                    cancelButton.disabled = true;
                    try {
                        await jsonRequest(`${transactionsBaseUrl}/${id}/cancel`, 'POST', {});
                        await loadRows();
                        setStatus('Transaksi berhasil dibatalkan.', 'success');
                    } catch (error) {
                        setStatus(error.message || 'Gagal membatalkan transaksi.', 'danger');
                    } finally {
                        cancelButton.disabled = false;
                    }
                }
            });

            filterButton.addEventListener('click', async () => {
                page = 1;
                await loadRows();
            });

            searchInput.addEventListener('keydown', async (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                page = 1;
                await loadRows();
            });

            prevButton.addEventListener('click', async () => {
                if (!pagination || Number(pagination.current_page || 1) <= 1) return;
                page = Number(pagination.current_page) - 1;
                await loadRows();
            });

            nextButton.addEventListener('click', async () => {
                if (!pagination || Number(pagination.current_page || 1) >= Number(pagination.last_page || 1)) return;
                page = Number(pagination.current_page) + 1;
                await loadRows();
            });

            loadRows();
        })();
    </script>
@endpush
