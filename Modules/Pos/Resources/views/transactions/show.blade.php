@extends('layouts.app')

@section('title', 'Detail Transaksi POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.transactions.index') }}">Transaksi POS</a></li>
        <li class="breadcrumb-item active">{{ $transaction->code }}</li>
    </ol>
@endsection

@section('content')
    @php
        $isLoadable = $transaction->status === 'DRAFT';
        $canRequestCancel = auth()->user()->can('pos.sell');
    @endphp

    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">{{ $transaction->code }}</h5>
                    <small class="text-muted">Status: {{ $transaction->status }}</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('pos.transactions.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
                    @if(auth()->user()->can('pos.receipts.reprint'))
                        <button id="btn-reprint-transaction" type="button" class="btn btn-sm btn-outline-primary">Cetak Ulang</button>
                    @endif
                    @if($isLoadable && auth()->user()->can('pos.sell') && auth()->user()->can('pos.transactions.load'))
                        <button id="btn-load-transaction" type="button" class="btn btn-sm btn-primary">Muat ke Kasir</button>
                    @endif
                    @if($isLoadable && $canRequestCancel)
                        <button
                            id="btn-cancel-transaction"
                            type="button"
                            class="btn btn-sm {{ ($cancelApproval['state'] ?? null) === 'approved' ? 'btn-success' : (($cancelApproval['state'] ?? null) === 'pending' ? 'btn-warning' : 'btn-outline-danger') }}"
                            @if(!empty($cancelApproval['request_id'])) data-approval-request-id="{{ $cancelApproval['request_id'] }}" @endif
                            @if(($cancelApproval['state'] ?? null) === 'pending') data-approval-pending="{{ $cancelApproval['request_id'] }}" @endif
                            @if(!empty($cancelApproval['approval_token'])) data-approval-token="{{ $cancelApproval['approval_token'] }}" @endif
                        >
                            @if(($cancelApproval['state'] ?? null) === 'approved')
                                Lanjutkan / Batalkan
                            @elseif(($cancelApproval['state'] ?? null) === 'pending')
                                Periksa Persetujuan
                            @else
                                Batalkan
                            @endif
                        </button>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div id="transaction-action-status" class="small text-muted mb-3">Draft dapat dimuat untuk kolaborasi bila Anda memiliki izin muat. Pembatalan draft memerlukan otorisasi void atau persetujuan supervisor.</div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="small text-muted">Pemilik</div>
                        <div>{{ $transaction->owner?->name ?? '-' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Pelanggan</div>
                        <div>{{ $transaction->customer?->customer_name ?? '-' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Dibuat</div>
                        <div>{{ optional($transaction->created_at)->format('Y-m-d H:i:s') ?? '-' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Disimpan Terakhir</div>
                        <div>{{ optional($transaction->updated_at)->format('Y-m-d H:i:s') ?? '-' }}</div>
                    </div>
                </div>
                <hr class="mt-3">
                <div class="row">
                    <div class="col-md-12">
                        <div class="small text-muted">Catatan</div>
                        <div>{!! $transaction->note ? nl2br(e($transaction->note)) : '<em class="text-muted">Tidak ada catatan</em>' !!}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white">
                <strong>Dokumen Penjualan</strong>
            </div>
            <div class="card-body">
                @php
                    $completedCheckout = $transaction->completedCheckout;
                    $sales = $completedCheckout ? $completedCheckout->getReachableSales() : collect();
                @endphp
                @if(!$completedCheckout)
                    <div class="text-muted small">Transaksi ini belum diselesaikan. Belum ada dokumen penjualan yang dihasilkan.</div>
                @elseif($sales->isEmpty())
                    <div class="text-muted small">Transaksi ini sudah diselesaikan, tetapi tidak ada dokumen penjualan yang dihasilkan (kemungkinan total 0 atau kesalahan sistem).</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Referensi</th>
                                <th>Tanggal</th>
                                <th>Pelanggan</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($sales as $sale)
                                <tr>
                                    <td>{{ $sale->reference }}</td>
                                    <td>{{ \Carbon\Carbon::parse($sale->date)->format('Y-m-d') }}</td>
                                    <td>{{ $sale->customer_name ?? '-' }}</td>
                                    <td>{{ format_currency($sale->total_amount) }}</td>
                                    <td>{{ $sale->status }}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info view-sale-btn" data-sale-id="{{ $sale->id }}" data-checkout-id="{{ $completedCheckout->id }}">Lihat</button>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white">
                <strong>Baris Transaksi</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>Produk</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Diskon</th>
                            <th class="text-right">Sub Total</th>
                            <th>Serial</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($transaction->lines as $line)
                            @php
                                $qty = (float) $line->qty;
                                $unitPrice = (float) $line->unit_price;
                                $discountValue = (float) $line->line_discount_value;
                                $lineBase = $qty * $unitPrice;
                                $lineDiscount = $line->line_discount_type === 'percentage'
                                    ? ($lineBase * ($discountValue / 100))
                                    : $discountValue;
                                $lineSubtotal = max(0, $lineBase - $lineDiscount);
                            @endphp
                            <tr>
                                <td>{{ $line->line_no }}</td>
                                <td>
                                    <div class="font-weight-bold">{{ $line->product_name_snapshot }}</div>
                                    <div class="small text-muted">{{ $line->product_code_snapshot ?? '-' }}</div>
                                    @php
                                        $bundleItems = $bundleCompositionByLine[(int) $line->id] ?? [];
                                    @endphp
                                    @if(!empty($bundleItems))
                                        <div class="mt-1 pl-3 border-left">
                                            @foreach($bundleItems as $item)
                                                <div class="small text-info">- {{ $item['name'] ?? $item['product_name'] ?? 'Unknown' }} x{{ (float)($item['qty'] ?? $item['quantity'] ?? 0) }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="text-right">{{ (float) $qty }}</td>
                                <td class="text-right">{{ number_format($unitPrice, 2, ',', '.') }}</td>
                                <td class="text-right">
                                    @if($line->line_discount_type === 'percentage')
                                        {{ number_format($discountValue, 2, ',', '.') }}%
                                    @else
                                        {{ number_format($discountValue, 2, ',', '.') }}
                                    @endif
                                </td>
                                <td class="text-right font-weight-bold">{{ number_format($lineSubtotal, 2, ',', '.') }}</td>
                                <td>
                                    @if($line->serials->isEmpty())
                                        <span class="text-muted">-</span>
                                    @else
                                        @foreach($line->serials as $serial)
                                            <span class="badge badge-info">{{ $serial->serial_number }}</span>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Tidak ada baris transaksi.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="row">
                    <div class="col-md-6">
                        <div class="small text-muted">Subtotal</div>
                        <div>{{ number_format((float) ($transaction->snapshot_totals['subtotal'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">Diskon</div>
                        <div>{{ number_format((float) ($transaction->snapshot_totals['discount_total'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-6 offset-md-6">
                        <div class="small text-muted">Total</div>
                        <div class="font-weight-bold" style="font-size: 18px;">{{ number_format((float) ($transaction->snapshot_totals['grand_total'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sale Detail Modal -->
    <div class="modal fade" id="saleDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detail Penjualan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="saleDetailModalBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        (function () {
            const loadButton = document.getElementById('btn-load-transaction');
            const reprintButton = document.getElementById('btn-reprint-transaction');
            const cancelButton = document.getElementById('btn-cancel-transaction');
            const statusElement = document.getElementById('transaction-action-status');
            const transactionId = @json((int) $transaction->id);
            const approvalRequestsBaseUrl = @json(url('/pos/sell/approval-requests'));
            const checkoutSalesBaseUrl = @json(url('/pos/checkouts'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const defaultStatusMessage = 'Draft dapat dimuat untuk kolaborasi bila Anda memiliki izin muat. Pembatalan draft memerlukan otorisasi void atau persetujuan supervisor.';

            const setStatus = (message, isError = false) => {
                statusElement.textContent = message || '';
                statusElement.classList.toggle('text-danger', Boolean(isError));
                statusElement.classList.toggle('text-muted', !isError);
            };

            const resetCancelButton = () => {
                if (!cancelButton) {
                    return;
                }

                cancelButton.removeAttribute('data-approval-pending');
                cancelButton.removeAttribute('data-approval-token');
                cancelButton.removeAttribute('data-approval-request-id');
                cancelButton.className = 'btn btn-sm btn-outline-danger';
                cancelButton.textContent = 'Batalkan';
            };

            const request = async (url, method) => {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

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

            const requestJson = async (url, method, payload = null) => {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: payload === null ? null : JSON.stringify(payload),
                });

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

            if (reprintButton) {
                reprintButton.addEventListener('click', () => {
                    const receiptUrl = `/pos/transactions/${transactionId}/receipt/reprint`;
                    window.open(receiptUrl, '_blank');
                });
            }

            if (loadButton) {
                loadButton.addEventListener('click', async () => {
                    loadButton.disabled = true;
                    setStatus('Memuat transaksi ke kasir...');
                    try {
                        await request(`/pos/transactions/${transactionId}/load`, 'POST');
                        window.location.href = @json(route('pos.sell'));
                    } catch (error) {
                        setStatus(error.message || 'Gagal memuat transaksi.', true);
                        loadButton.disabled = false;
                    }
                });
            }

            if (cancelButton) {
                cancelButton.addEventListener('click', async () => {
                    try {
                        const pendingRequestId = cancelButton.getAttribute('data-approval-pending');
                        const approvalToken = cancelButton.getAttribute('data-approval-token');

                        if (pendingRequestId) {
                            const approval = await requestJson(`${approvalRequestsBaseUrl}/${pendingRequestId}`, 'GET');
                            const state = String(approval && (approval.state || approval.status) || '').toLowerCase();

                            if (state === 'pending') {
                                setStatus('Permintaan masih menunggu persetujuan supervisor.');
                                return;
                            }

                            if (state === 'approved' && (approval.approval_token || approval.token)) {
                                cancelButton.removeAttribute('data-approval-pending');
                                cancelButton.setAttribute('data-approval-token', approval.approval_token || approval.token);
                                cancelButton.className = 'btn btn-sm btn-success';
                                cancelButton.textContent = 'Lanjutkan / Batalkan';
                                setStatus('Persetujuan tersedia. Klik lagi untuk melanjutkan atau membuang persetujuan.');
                                return;
                            }

                            resetCancelButton();
                            setStatus('Permintaan pembatalan tidak lagi aktif. Ajukan ulang bila diperlukan.', state === 'rejected' || state === 'expired');
                            return;
                        }

                        if (approvalToken) {
                            const decision = await Swal.fire({
                                title: 'Gunakan Persetujuan Pembatalan?',
                                text: 'Pilih Lanjutkan untuk membatalkan transaksi, atau Buang Persetujuan untuk membatalkan permintaan.',
                                icon: 'question',
                                showCancelButton: true,
                                showDenyButton: true,
                                confirmButtonText: 'Lanjutkan',
                                denyButtonText: 'Buang Persetujuan',
                                cancelButtonText: 'Tutup',
                            });

                            if (decision.isDenied) {
                                await requestJson(`${approvalRequestsBaseUrl}/${cancelButton.getAttribute('data-approval-request-id')}/cancel`, 'POST', {});
                                resetCancelButton();
                                setStatus('Persetujuan pembatalan dibuang tanpa mengubah transaksi.');
                                return;
                            }

                            if (!decision.isConfirmed) {
                                return;
                            }
                        } else {
                            const result = await Swal.fire({
                                title: 'Batalkan Transaksi?',
                                text: 'Pembatalan draft adalah aksi destruktif dan mungkin memerlukan persetujuan supervisor.',
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: '#d33',
                                confirmButtonText: 'Lanjutkan',
                                cancelButtonText: 'Tutup'
                            });

                            if (!result.isConfirmed) {
                                return;
                            }
                        }

                        cancelButton.disabled = true;
                        setStatus('Membatalkan transaksi...');
                        await requestJson(`/pos/transactions/${transactionId}/cancel`, 'POST', approvalToken ? {
                            approval_token: approvalToken,
                        } : {});
                        window.location.reload();
                    } catch (error) {
                        if ((error.message || '') === 'APPROVAL_REQUIRED') {
                            const approvalRequest = await Swal.fire({
                                title: 'Permintaan Persetujuan',
                                text: 'Masukkan alasan pembatalan transaksi (opsional).',
                                input: 'textarea',
                                inputPlaceholder: 'Tulis alasan di sini...',
                                showCancelButton: true,
                                confirmButtonText: 'Kirim Permintaan',
                                cancelButtonText: 'Tutup',
                            });

                            if (!approvalRequest.isDismissed) {
                                const response = await requestJson(approvalRequestsBaseUrl, 'POST', {
                                    action_type: 'TRANSACTION_CANCEL',
                                    target_type: 'pos_transaction',
                                    target_id: transactionId,
                                    payload: {},
                                    reason: (approvalRequest.value || '').trim() || null,
                                });

                                if (response && response.request_id) {
                                    cancelButton.setAttribute('data-approval-pending', String(response.request_id));
                                    cancelButton.setAttribute('data-approval-request-id', String(response.request_id));
                                    cancelButton.removeAttribute('data-approval-token');
                                    cancelButton.className = 'btn btn-sm btn-warning';
                                    cancelButton.textContent = 'Periksa Persetujuan';
                                    setStatus('Permintaan pembatalan dikirim. Klik lagi untuk memeriksa hasil persetujuan.');
                                }
                            } else {
                                setStatus(defaultStatusMessage);
                            }
                        } else {
                            setStatus(error.message || 'Gagal membatalkan transaksi.', true);
                        }
                    }
                    cancelButton.disabled = false;
                });
            }

            // Sale modal viewer logic
            document.querySelectorAll('.view-sale-btn').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const saleId = e.currentTarget.getAttribute('data-sale-id');
                    const checkoutId = e.currentTarget.getAttribute('data-checkout-id');
                    const modalEl = document.getElementById('saleDetailModal');
                    const bodyEl = document.getElementById('saleDetailModalBody');

                    $(modalEl).modal('show');
                    bodyEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div></div>';

                    try {
                        const response = await fetch(`${checkoutSalesBaseUrl}/${checkoutId}/sales/${saleId}`, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        if (!response.ok) {
                            throw new Error('Gagal memuat detail penjualan.');
                        }
                        const html = await response.text();
                        bodyEl.innerHTML = html;
                    } catch (error) {
                        bodyEl.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
                    }
                });
            });
        })();
    </script>
@endpush
