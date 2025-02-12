<div>
    <div>
        @if (session()->has('message'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <div class="alert-body">
                    <span>{{ session('message') }}</span>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
            </div>
        @endif
        <div class="table-responsive position-relative">
            <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center"
                 style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
            </div>
            <table class="table table-bordered">
                <thead class="thead-dark">
                <tr>
                    <th class="align-middle">Produk</th>
                    <th class="align-middle text-center">Harga Beli</th>
                    <th class="align-middle text-center">Stok</th>
                    <th class="align-middle text-center">Jumlah</th>
                    <th class="align-middle text-center">Diskon</th>
                    <th class="align-middle text-center">Pajak</th>
                    <th class="align-middle text-center">Sub Total Sebelum Pajak</th>
                    <th class="align-middle text-center">Sub Total</th>
                    <th class="align-middle text-center">Aksi</th>
                </tr>
                </thead>
                <tbody>
                @if($cart_items->isNotEmpty())
                    @foreach($cart_items as $cart_item)
                        <tr>
                            <td class="align-middle">
                                {{ $cart_item->name }} <br>
                                <span class="badge badge-success">
                                        {{ $cart_item->options->code }}
                                    </span>
                                <br>
                                Harga Beli Rata-Rata: {{ format_currency($cart_item->options->average_purchase_price) }}
                                <br>
                                {{ format_currency($cart_item->options->last_purchase_price) }}
                                Harga Beli Terakhir:
                            </td>

                            <td x-data="{ open: false }" class="align-middle text-center">
                                <!-- Display formatted price when not editing -->
                                <span x-show="!open"
                                      @click="open = true">{{ format_currency($cart_item->price) }}</span>

                                <!-- Editable input field -->
                                <div x-show="open" @click.away="open = false">
                                    <input
                                        wire:model.defer="unit_price.{{ $cart_item->id }}"
                                        style="min-width: 40px; max-width: 90px;"
                                        type="text"
                                        class="form-control text-center"
                                        @keydown.enter="open = false"
                                        wire:blur="updatePrice('{{ $cart_item->rowId }}', {{ $cart_item->id }})"
                                    >
                                </div>
                            </td>

                            <td class="align-middle text-center text-center">
                                <span
                                    class="badge badge-info">{{ $cart_item->options->stock . ' ' . $cart_item->options->unit }}</span>
                            </td>

                            <td class="align-middle text-center">
                                @include('livewire.includes.product-cart-quantity')
                            </td>

                            <td class="align-middle text-center">
                                <div class="input-group" style="max-width: 150px;">
                                    <!-- Dropdown for Discount Type -->
                                    <select wire:model.defer="discount_type.{{ $cart_item->id }}"
                                            wire:change="setProductDiscount('{{ $cart_item->rowId }}', '{{ $cart_item->id }}')"
                                            class="form-select form-select-sm bg-light border border-gray-300 rounded-start">
                                        <option value="fixed">Rp</option>
                                        <option value="percentage">%</option>
                                    </select>

                                    <!-- Discount Input Field -->
                                    <input type="number"
                                           wire:model.defer="item_discount.{{ $cart_item->id }}"
                                           wire:change="setProductDiscount('{{ $cart_item->rowId }}', '{{ $cart_item->id }}')"
                                           class="form-control form-control-sm text-center border border-gray-300 rounded-end"
                                           style="max-width: 60px;"
                                           min="0"
                                           @if($discount_type[$cart_item->id] == 'percentage') max="100" @endif
                                           placeholder="0">
                                </div>

                                <!-- Display Calculated Discount if Percentage -->
                                @if($discount_type[$cart_item->id] == 'percentage' && !empty($item_discount[$cart_item->id]))
                                    <div class="text-muted small mt-1">
                                        = {{ format_currency(($cart_item->price * $cart_item->qty) * ($item_discount[$cart_item->id] / 100)) }}
                                    </div>
                                @endif
                            </td>

                            <td class="align-middle text-center">
                                <select
                                    wire:model.defer="product_tax.{{ $cart_item->id }}"
                                    class="form-control"
                                    wire:change="updateTax('{{ $cart_item->rowId }}', '{{ $cart_item->id }}')"
                                >
                                    <option value="">Non Pajak</option>
                                    @foreach($taxes as $tax)
                                        <option
                                            value="{{ $tax->id }}"
                                            {{ $tax->id == $cart_item->options->product_tax ? 'selected' : '' }}>
                                            {{ $tax->name }} ({{ $tax->value }}%)
                                        </option>
                                    @endforeach
                                </select>
                                @error('product_tax.' . $cart_item->id)
                                <span class="text-danger">{{ $message }}</span>
                                @enderror
                            </td>

                            <td class="align-middle text-center">
                                {{ format_currency($cart_item->options->sub_total_before_tax ?? ($cart_item->price * $cart_item->qty - $cart_item->options->product_discount)) }}
                            </td>

                            <td class="align-middle text-center">
                                {{ format_currency($cart_item->options->sub_total) }}
                            </td>

                            <td class="align-middle text-center">
                                <a href="#" wire:click.prevent="removeItem('{{ $cart_item->rowId }}')">
                                    <i class="bi bi-x-circle font-2xl text-danger"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="11" class="text-center">
                        <span class="text-danger">
                            Please search & select products!
                        </span>
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="row justify-content-md-end">
        <div class="col-md-4">
            <div class="table-responsive">
                <table class="table table-striped">
                    <tr>
                        <th>Termasuk Pajak</th>
                        <td>
                            <div class="form-check">
                                <input
                                    wire:model="is_tax_included"
                                    wire:change="handleTaxIncluded"
                                    type="checkbox"
                                    class="form-check-input"
                                    id="taxIncludedCheckbox"
                                    {{ $is_tax_included ? 'checked' : '' }}
                                >
                                <input type="hidden" name="is_tax_included" value="{{ $is_tax_included ? 1 : 0 }}">
                                <label class="form-check-label" for="taxIncludedCheckbox">Termasuk Pajak</label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Total Sebelum Pajak</th>
                        <td>{{ format_currency($grand_total_before_tax) }}</td>
                    </tr>
                    <tr>
                        <th>Pajak (%)</th>
                        <td>(+) {{ format_currency($product_tax_total) }}</td>
                    </tr>
                    <tr>
                        <th>Total Setelah Pajak</th>
                        <td>{{ format_currency($total_sub_total) }}</td>
                    </tr>
                    <tr>
                        <th>Diskon Global</th>
                        <td>(-) {{ format_currency($global_discount_amount) }}</td>
                    </tr>
                    <tr>
                        <th>Biaya Ongkir</th>
                        <input type="hidden" value="{{ $shipping }}" name="shipping_amount">
                        <td>(+) {{ format_currency($shipping) }}</td>
                    </tr>
                    <tr>
                        <th>Grand Total</th>
                        <th>(=) {{ format_currency($grand_total) }}</th>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <input type="hidden" name="total_amount" value="{{ $grand_total }}">
    <input type="hidden" name="discount_amount" value="{{ $global_discount_amount }}">

    <div class="form-row">
        <div class="col-lg-4">
            <div class="form-group">
                <label for="discount_percentage">Diskon (%)</label>
                <input wire:model.blur="global_discount" type="number" class="form-control" name="discount_percentage"
                       min="0" max="100" value="{{ $global_discount }}" required>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="form-group">
                <label for="shipping_amount">Ongkos Kirim</label>
                <input wire:model.blur="shipping" type="number" class="form-control" name="shipping_amount" min="0"
                       value="0" required step="0.01">
            </div>
        </div>
    </div>
</div>
