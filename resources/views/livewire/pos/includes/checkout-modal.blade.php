@php use Gloudemans\Shoppingcart\Facades\Cart; @endphp
<style>
    .payment-table-wrapper .table {
        margin-bottom: 0;
    }

    .payment-table-wrapper .table td,
    .payment-table-wrapper .table th {
        vertical-align: middle;
    }

    .payment-table-wrapper .form-control {
        min-width: 8rem;
    }

    @media (max-width: 575.98px) {
        .payment-table-wrapper .table thead {
            display: none;
        }

        .payment-table-wrapper .table tbody tr {
            display: block;
            margin-bottom: 1rem;
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 0.5rem;
            padding: 0.75rem;
            background-color: var(--bs-body-bg, #fff);
        }

        .payment-table-wrapper .table tbody tr:last-child {
            margin-bottom: 0;
        }

        .payment-table-wrapper .table tbody tr td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: none;
            padding: 0.35rem 0;
        }

        .payment-table-wrapper .table tbody tr td[data-label]::before {
            content: attr(data-label);
            font-weight: 600;
            margin-right: 1rem;
        }

        .payment-table-wrapper .table tbody tr td:last-child {
            justify-content: flex-end;
        }

        .payment-table-wrapper .table tbody tr td .form-control {
            max-width: none;
            width: 100%;
        }
    }
</style>
<div class="modal fade" id="checkoutModal" tabindex="-1" role="dialog"
     aria-labelledby="checkoutModalLabel" aria-hidden="true"
     wire:ignore.self>
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="checkoutModalLabel">
                    <i class="bi bi-cart-check text-primary"></i> Konfirmasi Penjualan
                </h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="checkout-form" action="{{ route('app.pos.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    @if (session()->has('checkout_message'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <div class="alert-body">
                                <span>{{ session('checkout_message') }}</span>
                                <button type="button" class="close" data-bs-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                        </div>
                    @endif
                    <div class="row">
                        <div class="col-lg-7">
                            <input type="hidden" value="{{ $customer_id }}" name="customer_id">
                            <input type="hidden" value="{{ $global_tax }}" name="tax_percentage">
                            <input type="hidden" value="{{ $global_discount }}" name="discount_percentage">
                            <input type="hidden" name="paid_amount" wire:model.live="paid_amount" value="{{ $paid_amount }}">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="total_amount_display">Total Pembayaran <span
                                                class="text-danger">*</span></label>
                                        <div class="pos-currency-input" wire:ignore>
                                            <input id="total_amount_display" type="text" class="form-control"
                                                   data-pos-currency-target="total_amount" readonly>
                                        </div>
                                        <input id="total_amount" type="hidden" name="total_amount"
                                               wire:model.live="total_amount" value="{{ $total_amount }}">
                                    </div>
                                </div>
                                <div class="col-lg-12">
                                    <div class="form-group">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="mb-0">
                                                Rincian Pembayaran <span class="text-danger">*</span>
                                            </label>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    wire:click="addPaymentRow">
                                                Tambah Metode
                                            </button>
                                        </div>
                                        <div class="payment-table-wrapper">
                                            <table class="table table-sm align-middle payment-table">
                                                <thead>
                                                <tr>
                                                    <th scope="col">Metode</th>
                                                    <th scope="col" class="text-end">Jumlah</th>
                                                    <th scope="col" class="text-center">&nbsp;</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($payments as $index => $payment)
                                                    <tr wire:key="payment-row-{{ $payment['uuid'] ?? $index }}">
                                                        <td data-label="Metode">
                                                            <label class="sr-only" for="payment-method-{{ $payment['uuid'] ?? $index }}">Metode Pembayaran</label>
                                                            <select
                                                                id="payment-method-{{ $payment['uuid'] ?? $index }}"
                                                                class="form-control"
                                                                name="payments[{{ $index }}][method_id]"
                                                                wire:model="payments.{{ $index }}.method_id"
                                                                required>
                                                                <option value="">-- Pilih Metode --</option>
                                                                @foreach($paymentMethods as $method)
                                                                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                        <td data-label="Jumlah" class="text-end text-md-right">
                                                            <label class="sr-only" for="payment-amount-{{ $payment['uuid'] ?? $index }}">Jumlah</label>
                                                            <input
                                                                id="payment-amount-{{ $payment['uuid'] ?? $index }}"
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                class="form-control text-end"
                                                                name="payments[{{ $index }}][amount]"
                                                                wire:model.live="payments.{{ $index }}.amount"
                                                                required>
                                                        </td>
                                                        <td data-label="Aksi" class="text-center text-md-right">
                                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                                    wire:click="removePaymentRow({{ $index }})"
                                                                    @if(count($payments) <= 1) disabled @endif>
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="note">Catatan (Jika Diperlukan)</label>
                                <textarea name="note" id="note" rows="5" class="form-control"></textarea>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <tr>
                                        <th>Total Produk</th>
                                        <td>
                                                <span class="badge badge-success">
                                                    {{ Cart::instance($cart_instance)->count() }}
                                                </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Pajak Pesanan ({{ $global_tax }}%)</th>
                                        <td>(+) {{ format_currency(Cart::instance($cart_instance)->tax()) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Diskon ({{ $global_discount }}%)</th>
                                        <td>(-) {{ format_currency(Cart::instance($cart_instance)->discount()) }}</td>
                                    </tr>
                                    <tr class="text-primary">
                                        <th>Total Akhir</th>
                                        <th>
                                            (=) {{ format_currency($total_amount) }}
                                        </th>
                                    </tr>
                                    <tr>
                                        <th>Jumlah Diterima</th>
                                        <td>
                                            {{ format_currency($paidAmount ?? 0) }}
                                        </td>
                                    </tr>
                                    @php
                                        $resolvedChangeDue = $changeDue ?? 0;
                                        $resolvedRawChangeDue = $rawChangeDue ?? $resolvedChangeDue;
                                        $overPaidWithNonCash = $overPaidWithNonCash ?? false;
                                        $changeRowClass = $resolvedChangeDue < 0 ? 'text-danger' : 'text-success';

                                        if ($overPaidWithNonCash) {
                                            $changeRowClass = 'text-warning';
                                        } elseif (abs($resolvedChangeDue) < 0.01) {
                                            $changeRowClass = 'text-primary';
                                        }
                                    @endphp
                                    <tr class="{{ $changeRowClass }}">
                                        <th>{{ $changeDescriptor ?? 'Kembalian' }}</th>
                                        <th>
                                            @if($overPaidWithNonCash)
                                                <span class="d-block text-warning">
                                                    Kelebihan pembayaran sebesar {{ format_currency(max($resolvedRawChangeDue, 0)) }} hanya diperbolehkan jika terdapat entri pembayaran tunai. Silakan sesuaikan rincian pembayaran.
                                                </span>
                                            @else
                                                {{ $formattedChangeDue ?? ( ($resolvedChangeDue < 0 ? '(-)' : '(+)') . ' ' . format_currency(abs($resolvedChangeDue)) ) }}
                                            @endif
                                        </th>
                                    </tr>
                                </table>
                            </div>
                            <div class="mt-3">
                                <button type="button"
                                        class="btn btn-outline-success btn-block"
                                        wire:click="openChangeModal"
                                        aria-controls="posChangeModal"
                                        {{ $hasCashPayment ? '' : 'disabled' }}>
                                    Tampilkan Kembalian
                                </button>
                                <dl class="row small mt-3 mb-0">
                                    <dt class="col-7">Selisih Pembayaran</dt>
                                    @php
                                        $rawChangeClass = $resolvedRawChangeDue < 0 ? 'text-danger' : ($resolvedRawChangeDue > 0 ? 'text-success' : 'text-muted');
                                    @endphp
                                    <dd class="col-5 text-end {{ $rawChangeClass }}">
                                        {{ $formattedRawChangeDue ?? ( ($resolvedRawChangeDue < 0 ? '(-)' : '(+)') . ' ' . format_currency(abs($resolvedRawChangeDue)) ) }}
                                    </dd>
                                    <dt class="col-7">{{ $changeDescriptor ?? 'Kembalian' }}</dt>
                                    @php
                                        $changeSummaryClass = $overPaidWithNonCash ? 'text-warning' : ($resolvedChangeDue < 0 ? 'text-danger' : ($resolvedChangeDue > 0 ? 'text-success' : 'text-muted'));
                                    @endphp
                                    <dd class="col-5 text-end {{ $changeSummaryClass }}">
                                        {{ $formattedChangeDue ?? ( ($resolvedChangeDue < 0 ? '(-)' : '(+)') . ' ' . format_currency(abs($resolvedChangeDue)) ) }}
                                    </dd>
                                </dl>
                                @if(! $hasCashPayment)
                                    <small class="form-text text-muted mt-2">
                                        Pilih metode pembayaran tunai untuk menampilkan informasi kembalian.
                                    </small>
                                @endif
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" formaction="{{ route('app.pos.store-as-quotation') }}"
                            class="btn btn-warning">
                        Simpan Sebagai Dokumen Penjualan
                    </button>
                    <button type="submit" class="btn btn-primary">Kirim</button>
                </div>
            </form>
        </div>
    </div>
</div>
