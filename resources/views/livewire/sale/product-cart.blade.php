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
        <div class="table position-relative">
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
                    <th class="align-middle text-center">Harga Jual</th>
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
                                <strong>{{ $cart_item->name }}</strong> <br>
                                <span class="badge badge-success">{{ $cart_item->options->code }}</span>
                                @if($cart_item->options->bundle_items)
                                    <br>
                                    <a class="btn btn-link btn-sm p-0" data-bs-toggle="collapse"
                                       href="#bundleCollapse{{ $cart_item->id }}" role="button" aria-expanded="false"
                                       aria-controls="bundleCollapse{{ $cart_item->id }}">
                                        Lihat Paket Penjualan
                                    </a>
                                @endif

                                <!-- Tooltip Container -->
                                <span class="d-inline-block"
                                      data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      title="Harga Jual Terakhir: 0">
                                    <i class="bi bi-info-circle text-primary" style="cursor: pointer;"></i>
                                </span>
                            </td>

                            <td x-data="{ open: false }" class="align-middle text-right">
                                <span x-show="!open"
                                      @click="open = true">{{ format_currency($cart_item->price) }}</span>

                                <!-- Editable input field -->
                                <div x-show="open" @click.away="open = false">
                                    <input
                                        wire:model.defer="unit_price.{{ $cart_item->id }}"
                                        style="min-width: 40px; max-width: 90px;"
                                        type="text"
                                        class="form-control text-right"
                                        @keydown.enter="open = false"
                                        wire:blur="updatePrice('{{ $cart_item->rowId }}', '{{ $cart_item->id }}')"
                                    >
                                </div>
                            </td>

                            <td class="align-middle text-right">
                                <span class="badge badge-info"
                                      data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      title="Stok Non PPN: {{ $cart_item->options->quantity_non_tax }}, Stok PPN: {{ $cart_item->options->quantity_tax }}">
                                    {{ $cart_item->options->stock . ' ' . $cart_item->options->unit }}
                                </span>
                            </td>

                            <td class="align-middle text-right">
                                <div class="input-group d-flex justify-content-center">
                                    <input wire:model="quantity.{{ $cart_item->id }}"
                                           style="min-width: 40px; max-width: 90px;"
                                           type="number"
                                           class="form-control text-right"
                                           value="{{ $cart_item->qty }}"
                                           min="1"
                                           wire:blur="updateQuantity('{{ $cart_item->rowId }}', '{{ $cart_item->id }}')">
                                </div>
                                @if(!empty($quantityBreakdowns[$cart_item->id]))
                                    <div class="text-muted small mt-1">
                                        {{ $quantityBreakdowns[$cart_item->id] }}
                                    </div>
                                @endif
                            </td>

                            <td class="align-middle text-center position-relative">
                                <div class="input-group input-group-sm" style="max-width: 180px;">
                                    <!-- Discount Type Dropdown Inside Input Box -->
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle px-3" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                            style="font-size: 0.875rem; min-width: 50px;">
                                        {{ $discount_type[$cart_item->id] == 'percentage' ? '%' : 'Rp' }}
                                    </button>

                                    <!-- The dropdown menu is now positioned outside the table -->
                                    <ul class="dropdown-menu"
                                        style="color: black; font-size: 0.9rem; position: absolute; left: 0; top: 100%; z-index: 1050;">
                                        <li>
                                            <a class="dropdown-item text-center text-dark" href="#"
                                               wire:click.prevent="setDiscountType('{{ $cart_item->rowId }}', '{{ $cart_item->id }}', 'fixed')">
                                                Rp
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item text-center text-dark" href="#"
                                               wire:click.prevent="setDiscountType('{{ $cart_item->rowId }}', '{{ $cart_item->id }}', 'percentage')">
                                                %
                                            </a>
                                        </li>
                                    </ul>

                                    <!-- Discount Input -->
                                    <input type="number"
                                           wire:model.defer="item_discount.{{ $cart_item->id }}"
                                           wire:change="setProductDiscount('{{ $cart_item->rowId }}', '{{ $cart_item->id }}')"
                                           class="form-control form-control-sm text-right"
                                           style="font-size: 0.875rem; min-width: 70px;"
                                           min="0"
                                           @if($discount_type[$cart_item->id] == 'percentage') max="100" @endif
                                           placeholder="0">
                                </div>

                                <!-- Display Calculated Discount if Percentage -->
                                @if($discount_type[$cart_item->id] == 'percentage' && !empty($item_discount[$cart_item->id]))
                                    <div class="text-muted small mt-1">
                                        = {{ format_currency($cart_item->price * ($item_discount[$cart_item->id] / 100) * $cart_item->qty) }}
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

                                @if (!empty($priceBreakdowns[$cart_item->id]))
                                    <div class="text-muted small">
                                        {{ $priceBreakdowns[$cart_item->id] }}
                                    </div>
                                @endif
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

                        @if($cart_item->options->bundle_items)
                            <tr class="collapse" id="bundleCollapse{{ $cart_item->id }}">
                                <td colspan="9" class="p-0">
                                    <div class="card card-body">
                                        <h6 class="mb-2">Paket Penjualan</h6>
                                        <p class="mb-2">
                                            <strong>Nama Paket:</strong> {{ $cart_item->options->bundle_name ?? '-' }} <br>
                                            <strong>Harga Paket:</strong> {{ format_currency($cart_item->options->bundle_price ?? 0) }}
                                        </p>
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead>
                                            <tr>
                                                <th>Nama Barang</th>
                                                <th>Jumlah</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach($cart_item->options->bundle_items as $bundleItem)
                                                <tr>
                                                    <td>{{ $bundleItem['name'] }}</td>
                                                    <td>{{ $bundleItem['quantity'] }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                @else
                    <tr>
                        <td colspan="9" class="text-center">
                            <span class="text-danger">Please search & select products!</span>
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
    @if($global_discount_type == 'percentage')
        <input type="hidden" name="discount_percentage" value="{{ $global_discount }}">
    @else
        <input type="hidden" name="discount_amount" value="{{ $global_discount }}">
    @endif

    <div class="form-row">
        <div class="col-lg-4">
            <div class="form-group">
                <label for="discount_percentage">Diskon Global</label>
                <div class="input-group input-group-sm">
                    <button class="btn btn-outline-secondary dropdown-toggle px-3 h-100"
                            type="button"
                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                            style="font-size: 0.875rem; min-width: 50px; min-height: 31px; padding-top: 0.25rem; padding-bottom: 0.25rem;">
                        {{ $global_discount_type == 'percentage' ? '%' : 'Rp' }}
                    </button>

                    <ul class="dropdown-menu"
                        style="color: black; font-size: 0.9rem; position: absolute; left: 0; top: 100%; z-index: 1050;">
                        <li>
                            <a class="dropdown-item text-left text-dark" href="#"
                               wire:click.prevent="setGlobalDiscountType('fixed')">
                                Rp
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item text-left text-dark" href="#"
                               wire:click.prevent="setGlobalDiscountType('percentage')">
                                %
                            </a>
                        </li>
                    </ul>

                    <input type="number"
                           wire:model.defer="global_discount"
                           wire:change="updateGlobalDiscount"
                           class="form-control form-control-sm text-right"
                           style="font-size: 0.875rem; min-width: 70px; min-height: 31px;"
                           min="0"
                           @if($global_discount_type == 'percentage') max="100" @endif
                           placeholder="0">
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="form-group">
                <label for="shipping_amount">Ongkos Kirim</label>
                <input wire:model.blur="shipping" type="number" class="form-control text-right" name="shipping_amount"
                       min="0"
                       value="0" required step="0.01">
            </div>
        </div>
    </div>
    @include('livewire.sale.includes.bundle-confirmation-modal')
</div>
