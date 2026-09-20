@php use Carbon\Carbon; @endphp
<div data-global-pos-payment-table-root>
    <!-- Filter Panel -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="row g-3">
                <!-- Business Filter -->
                <div class="col-md-3">
                    @include('livewire.reports.business-source-selector', [
                        'selectId' => 'globalPosBusinessFilters',
                        'availableSettings' => $availableSettings,
                        'livewireProperty' => 'draftGlobalBusinessFilters',
                        'selectedValues' => $draftGlobalBusinessFilters,
                        'label' => 'Bisnis',
                        'placeholder' => 'Pilih bisnis (kosongkan untuk semua)'
                    ])
                    <small class="text-muted d-block mt-1">Pilih bisnis (kosongkan untuk semua)</small>
                </div>

                <!-- Transaction Date Range -->
                <div class="col-md-3">
                    <label class="form-label d-block">Tanggal Transaksi</label>
                    <div class="row g-2">
                        <div class="col">
                            <label class="form-label small">Dari</label>
                            <input type="date" class="form-control" wire:model="draftTransactionDateFrom">
                        </div>
                        <div class="col">
                            <label class="form-label small">Hingga</label>
                            <input type="date" class="form-control" wire:model="draftTransactionDateTo">
                        </div>
                    </div>
                </div>

                <!-- Due Date Range -->
                <div class="col-md-3">
                    <label class="form-label d-block">Tanggal Jatuh Tempo</label>
                    <div class="row g-2">
                        <div class="col">
                            <label class="form-label small">Dari</label>
                            <input type="date" class="form-control" wire:model="draftDueDateFrom">
                        </div>
                        <div class="col">
                            <label class="form-label small">Hingga</label>
                            <input type="date" class="form-control" wire:model="draftDueDateTo">
                        </div>
                    </div>
                </div>

                <!-- Payment Status & Terminal / Cashier -->
                <div class="col-md-3">
                    <label class="form-label">Status Pembayaran</label>
                    <select class="form-control mb-2" wire:model="draftPaymentStatusFilter">
                        <option value="">Semua Status</option>
                        <option value="Paid">Lunas</option>
                        <option value="Partial">Dibayar Sebagian</option>
                        <option value="Unpaid">Belum Dibayar</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label">Kasir</label>
                    <select class="form-control" wire:model="draftCashierUserId">
                        <option value="">Semua Kasir</option>
                        @foreach ($cashiers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Terminal</label>
                    <select class="form-control" wire:model="draftTerminalId">
                        <option value="">Semua Terminal</option>
                        @foreach ($terminals as $t)
                            <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->code }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row g-2 mt-2">
                <div class="col-12">
                    <button type="button" class="btn btn-primary" wire:click="applyFilter">
                        <i class="bi bi-check2-circle"></i> Terapkan Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary" wire:click="resetFilters">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset semua filter
                    </button>
                </div>
            </div>

            <!-- Applied Filters Feedback -->
            @if ((!empty($globalBusinessFilters) && count($globalBusinessFilters) > 0) || $transactionDateFrom || $transactionDateTo || $dueDateFrom || $dueDateTo || $paymentStatusFilter || $cashierUserId || $terminalId)
            <div class="row mt-3">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="bi bi-funnel-fill"></i> Filter aktif:
                        @if (!empty($globalBusinessFilters) && count($globalBusinessFilters) > 0)
                            <span class="badge bg-primary">
                                Bisnis: {{ collect($globalBusinessFilters)->map(fn($id) => \Modules\Setting\Entities\Setting::find($id)?->company_name ?? 'N/A')->join(', ') }}
                            </span>
                        @endif
                        @if ($transactionDateFrom || $transactionDateTo)
                            <span class="badge bg-primary">
                                Tgl Transaksi: {{ $transactionDateFrom ?? '...' }} s/d {{ $transactionDateTo ?? '...' }}
                            </span>
                        @endif
                        @if ($dueDateFrom || $dueDateTo)
                            <span class="badge bg-primary">
                                Jatuh Tempo: {{ $dueDateFrom ?? '...' }} s/d {{ $dueDateTo ?? '...' }}
                            </span>
                        @endif
                        @if ($paymentStatusFilter)
                            <span class="badge bg-primary">
                                Status: {{ $paymentStatusFilter }}
                            </span>
                        @endif
                    </small>
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- Search & PerPage -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center" style="gap: 1rem;">
            <select class="form-control form-control-sm" style="width: 80px;" wire:model.live="perPage">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <span class="text-muted small">data per halaman</span>
        </div>
        <form class="d-flex" wire:submit.prevent="searchSubmit" style="gap: 0.5rem;">
            <input type="text"
                   class="form-control"
                   placeholder="Cari kode transaksi, struk, pelanggan, produk, barcode, serial, ref penjualan, catatan..."
                   wire:model.defer="searchText"
                   style="width: 380px;"
                   autocomplete="off"
            >
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i>
            </button>
            @if ($search)
                <button type="button" wire:click="clearSearch" class="btn btn-secondary">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
        </form>
    </div>

    <!-- Table -->
    <div class="table-responsive global-payment-table-scroll">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
            <tr>
                <th wire:click="sortBy('code')" style="cursor:pointer">
                    Kode & Struk {!! $this->sortIcon('code') !!}
                </th>
                <th>Bisnis</th>
                <th>Catatan</th>
                <th wire:click="sortBy('created_at')" style="cursor:pointer">
                    Tanggal Selesai {!! $this->sortIcon('created_at') !!}
                </th>
                <th>Pelanggan</th>
                <th>Total Transaksi</th>
                <th>Total Terbayar</th>
                <th>Sisa Tagihan</th>
                <th>Jatuh Tempo</th>
                <th>Status Pembayaran</th>
                <th>Kasir & Terminal</th>
                <th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transactions as $trx)
                @php
                    $proj = $trx->projection;
                    $checkout = $trx->completedCheckout;
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('pos.global-payments.show', $trx->id) }}" class="text-primary fw-bold">
                            {{ $trx->code }}
                        </a>
                        @if ($checkout && $checkout->receipt_number)
                            <br><small class="text-muted">Struk: {{ $checkout->receipt_number }}</small>
                        @endif
                    </td>
                    <td>
                        {{ $trx->setting->company_name ?? '-' }}
                    </td>
                    <td class="document-note-cell">
                        <x-document-note :note="$trx->note" :row-id="'pos-note-'.$trx->id" />
                    </td>
                    <td>
                        {{ Carbon::parse($trx->created_at)->format('d M Y H:i') }}
                    </td>
                    <td>
                        {{ $trx->customer->customer_name ?? '-' }}
                    </td>
                    <td class="text-end fw-bold">{{ format_currency($proj['total_amount']) }}</td>
                    <td class="text-end text-success">{{ format_currency($proj['effective_paid']) }}</td>
                    <td class="text-end {{ $proj['live_due'] > 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                        {{ format_currency($proj['live_due']) }}
                    </td>
                    <td>
                        @if ($proj['effective_due_date'])
                            <span class="{{ $proj['is_overdue'] ? 'text-danger fw-bold' : '' }}">
                                {{ Carbon::parse($proj['effective_due_date'])->format('d M Y') }}
                            </span>
                            @if ($proj['is_overdue'])
                                <br><small class="badge bg-danger">Telat</small>
                            @endif
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if ($proj['payment_status'] === 'Paid')
                            <span class="badge bg-success">Lunas</span>
                        @elseif ($proj['payment_status'] === 'Partial')
                            <span class="badge bg-warning text-dark">Dibayar Sebagian</span>
                        @else
                            <span class="badge bg-danger">Belum Dibayar</span>
                        @endif
                    </td>
                    <td>
                        <small>
                            <strong>Kasir:</strong> {{ $checkout && $checkout->cashier ? $checkout->cashier->name : ($trx->creator ? $trx->creator->name : '-') }}<br>
                            <strong>Terminal:</strong> {{ $checkout && $checkout->terminal ? $checkout->terminal->name : '-' }}
                        </small>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            <a href="{{ route('pos.global-payments.show', $trx->id) }}" class="btn btn-info btn-sm" title="Lihat Detail">
                                <i class="bi bi-eye"></i>
                            </a>

                            @can('posPayments.global.history')
                                <a href="{{ route('pos.global-payments.history', $trx->id) }}" class="btn btn-warning btn-sm" title="Riwayat Pembayaran">
                                    <i class="bi bi-clock-history"></i>
                                </a>
                            @endcan

                            @can('posPayments.global.create')
                                @if ($proj['is_payable'] && $proj['live_due'] > 0)
                                    <a href="{{ route('pos.global-payments.create', $trx->id) }}" class="btn btn-success btn-sm" title="Bayar POS">
                                        <i class="bi bi-cash-stack"></i>
                                    </a>
                                @endif
                            @endcan

                            @can('pos.receipts.reprint')
                                <form action="{{ route('pos.global-payments.receipt.reprint', $trx->id) }}" method="POST" target="_blank" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Cetak Ulang Struk">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center py-4 text-muted">
                        Tidak ada data transaksi POS yang ditemukan.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <div>
            Menampilkan {{ $transactions->firstItem() ?? 0 }} sampai {{ $transactions->lastItem() ?? 0 }} dari {{ $transactions->total() }} transaksi
        </div>
        <div>
            {{ $transactions->links() }}
        </div>
    </div>
</div>
