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
        $isLoadable = in_array($transaction->status, ['DRAFT', 'LOADED'], true);
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
            </div>
        </div>

        <div class="card shadow-sm border-0">
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
                            <th class="text-right">Pajak</th>
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
                                $lineAfterDiscount = max(0, $lineBase - $lineDiscount);
                                $lineTax = ((float) ($line->tax_rate_snapshot ?? 0)) > 0
                                    ? ($lineAfterDiscount * ((float) $line->tax_rate_snapshot / 100))
                                    : 0;
                                $lineSubtotal = $lineAfterDiscount + $lineTax;
                            @endphp
                            <tr>
                                <td>{{ $line->line_no }}</td>
                                <td>
                                    <div class="font-weight-bold">{{ $line->product_name_snapshot }}</div>
                                    <div class="small text-muted">{{ $line->product_code_snapshot ?? '-' }}</div>
                                </td>
                                <td class="text-right">{{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') }}</td>
                                <td class="text-right">{{ number_format($unitPrice, 2, ',', '.') }}</td>
                                <td class="text-right">
                                    @if($line->line_discount_type === 'percentage')
                                        {{ number_format($discountValue, 2, ',', '.') }}%
                                    @else
                                        {{ number_format($discountValue, 2, ',', '.') }}
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($lineTax, 2, ',', '.') }}</td>
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
                    <div class="col-md-3">
                        <div class="small text-muted">Subtotal</div>
                        <div>{{ number_format((float) ($transaction->snapshot_totals['subtotal'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Diskon</div>
                        <div>{{ number_format((float) ($transaction->snapshot_totals['discount_total'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Pajak</div>
                        <div>{{ number_format((float) ($transaction->snapshot_totals['tax_total'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Grand Total</div>
                        <div class="font-weight-bold">{{ number_format((float) ($transaction->snapshot_totals['grand_total'] ?? 0), 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        (function () {
            const loadButton = document.getElementById('btn-load-transaction');
            const cancelButton = document.getElementById('btn-cancel-transaction');
            const statusElement = document.getElementById('transaction-action-status');
            const transactionId = @json((int) $transaction->id);
            const approvalRequestsBaseUrl = @json(url('/pos/sell/approval-requests'));
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
        })();
    </script>
@endpush
