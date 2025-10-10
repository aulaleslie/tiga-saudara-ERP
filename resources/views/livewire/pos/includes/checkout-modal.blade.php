@php use Gloudemans\Shoppingcart\Facades\Cart; @endphp
<div class="modal fade" id="checkoutModal" tabindex="-1" role="dialog"
     aria-labelledby="checkoutModalLabel" aria-hidden="true"
     wire:ignore.self>
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="checkoutModalLabel">
                    <i class="bi bi-cart-check text-primary"></i> Confirm Sale
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
                                        <label for="total_amount_display">Total Amount <span
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
                                        <label for="paid_amount_display">Received Amount <span
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
                                <label for="note">Note (If Needed)</label>
                                <textarea name="note" id="note" rows="5" class="form-control"></textarea>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <tr>
                                        <th>Total Products</th>
                                        <td>
                                                <span class="badge badge-success">
                                                    {{ Cart::instance($cart_instance)->count() }}
                                                </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Order Tax ({{ $global_tax }}%)</th>
                                        <td>(+) {{ format_currency(Cart::instance($cart_instance)->tax()) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Discount ({{ $global_discount }}%)</th>
                                        <td>(-) {{ format_currency(Cart::instance($cart_instance)->discount()) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Shipping</th>
                                        <input type="hidden" value="{{ $shipping }}" name="shipping_amount">
                                        <td>(+) {{ format_currency($shipping) }}</td>
                                    </tr>
                                    <tr class="text-primary">
                                        <th>Grand Total</th>
                                        <th>
                                            (=) {{ format_currency($total_amount) }}
                                        </th>
                                    </tr>
                                    <tr>
                                        <th>Received Amount</th>
                                        <td>
                                            {{ format_currency($paidAmount ?? 0) }}
                                        </td>
                                    </tr>
                                    @php $computedChange = $changeDue ?? 0; @endphp
                                    <tr class="{{ $computedChange < 0 ? 'text-danger' : 'text-success' }}">
                                        <th>Change</th>
                                        <th>
                                            {{ $computedChange < 0 ? '(-)' : '(+)' }} {{ format_currency(abs($computedChange)) }}
                                        </th>
                                    </tr>
                                </table>
                            </div>
                            <div class="mt-3">
                                <div class="p-4 rounded border {{ $computedChange < 0 ? 'border-danger bg-light text-danger' : 'border-success bg-light text-success' }}">
                                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                                        <span class="h5 mb-3 mb-md-0">Change Due</span>
                                        <span class="display-4 font-weight-bold mb-0">
                                            {{ $computedChange < 0 ? '-' : '' }}{{ format_currency(abs($computedChange)) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" formaction="{{ route('app.pos.store-as-quotation') }}"
                            class="btn btn-warning">
                        Simpan Sebagai Dokumen Penjualan
                    </button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>
