@php use Gloudemans\Shoppingcart\Facades\Cart; @endphp
<div class="modal fade" id="checkoutModal" tabindex="-1" role="dialog"
     aria-labelledby="checkoutModalLabel" aria-hidden="true"
     wire:ignore.self>
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="checkoutModalLabel">
                    <i class="bi bi-cart-check text-primary"></i> Konfirmasi Penjualan
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
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
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
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
                            <input type="hidden" value="{{ $shipping }}" name="shipping_amount">
                            <input type="hidden" name="payment_method_id" value="{{ $selected_payment_method_id }}">
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
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="paid_amount_display">Jumlah Diterima <span
                                                class="text-danger">*</span></label>
                                        <div class="pos-currency-input" wire:ignore>
                                            <input id="paid_amount_display" type="text" class="form-control"
                                                   data-pos-currency-target="paid_amount" inputmode="decimal"
                                                   autocomplete="off" required>
                                        </div>
                                        <input id="paid_amount" type="hidden" name="paid_amount"
                                               wire:model.live="paid_amount" value="{{ $paid_amount }}">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="payment_method">Metode Pembayaran <span class="text-danger">*</span></label>
                                <select wire:model="selected_payment_method_id" class="form-control" id="payment_method" required>
                                    <option value="">-- Pilih Metode Pembayaran --</option>
                                    @foreach($paymentMethods as $method)
                                        <option value="{{ $method->id }}">{{ $method->name }}</option>
                                    @endforeach
                                </select>
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
                                    <tr>
                                        <th>Biaya Pengiriman</th>
                                        <input type="hidden" value="{{ $shipping }}" name="shipping_amount">
                                        <td>(+) {{ format_currency($shipping) }}</td>
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
                                        $computedChange = $changeDue ?? 0;
                                        $rawChange = $rawChangeDue ?? $computedChange;
                                        $overPaidWithNonCash = $overPaidWithNonCash ?? false;
                                        $selectedPaymentMethodName = data_get($selectedPaymentMethod ?? null, 'name');

                                        $changeRowClass = $computedChange < 0 ? 'text-danger' : 'text-success';
                                        if ($overPaidWithNonCash) {
                                            $changeRowClass = 'text-warning';
                                        }
                                    @endphp
                                    <tr class="{{ $changeRowClass }}">
                                        <th>Kembalian</th>
                                        <th>
                                            @if($overPaidWithNonCash)
                                                <span class="d-block text-warning">
                                                    Kelebihan pembayaran sebesar {{ format_currency(max($rawChange, 0)) }} hanya diperbolehkan untuk pembayaran tunai
                                                    @if(!empty($selectedPaymentMethodName))
                                                        ({{ $selectedPaymentMethodName }})
                                                    @endif
                                                    . Silakan sesuaikan jumlah yang diterima.
                                                </span>
                                            @else
                                                {{ $computedChange < 0 ? '(-)' : '(+)' }} {{ format_currency(abs($computedChange)) }}
                                            @endif
                                        </th>
                                    </tr>
                                </table>
                            </div>
                            <div class="mt-3">
                                @php
                                    $changeButtonDisabled = !($selectedPaymentMethodFlags['is_cash'] ?? false);
                                @endphp
                                <button type="button"
                                        class="btn btn-outline-success btn-block"
                                        wire:click="openChangeModal"
                                        aria-controls="posChangeModal"
                                        {{ $changeButtonDisabled ? 'disabled' : '' }}>
                                    Tampilkan Kembalian
                                </button>
                                @if($changeButtonDisabled)
                                    <small class="form-text text-muted mt-2">
                                        Pilih metode pembayaran tunai untuk menampilkan informasi kembalian.
                                    </small>
                                @endif
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
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
