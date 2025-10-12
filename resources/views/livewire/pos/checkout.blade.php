@push('page_css')
    <style>
        @media (max-width: 767.98px) {
            .cart-card {
                border: 1px solid #dee2e6;
                border-radius: 0.5rem;
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .cart-card .row + .row {
                margin-top: 0.5rem;
            }

            .cart-label {
                font-weight: 600;
                color: #6c757d;
            }

            .cart-summary th,
            .cart-summary td {
                display: block;
                text-align: left;
                padding: 0.25rem 0;
            }

            .cart-summary tr {
                margin-bottom: 0.75rem;
            }

            .cart-summary th {
                color: #6c757d;
                font-weight: 600;
            }

            .cart-summary-total {
                font-size: 1.1rem;
                font-weight: bold;
                color: #007bff;
            }
        }

        .badge.badge-pill a { cursor: pointer; }
    </style>
@endpush

@php use Gloudemans\Shoppingcart\Facades\Cart; @endphp
<div>
    <div class="card border-0 shadow-sm">
        <div class="card-body">

            {{-- Flash Message --}}
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

            {{-- Customer Picker --}}
            <div class="form-group">
                <label for="customer_id">Pelanggan <span class="text-danger">*</span></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <button type="button" class="btn btn-primary" wire:click="triggerCustomerModal"
                                style="z-index: 1;">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    </div>
                    <div class="flex-grow-1">
                        <livewire:auto-complete.customer-loader/>
                    </div>
                </div>
            </div>

            {{-- Cart Items: Mobile --}}
            <div>
                @if($cart_items->isNotEmpty())
                    @foreach($cart_items as $cart_item)
                        @php
                            $options = $cart_item->options->toArray();
                            $cartKey = $options['cart_key'] ?? $cart_item->id;
                            $lineTotal = data_get($options, 'sub_total', $cart_item->price * $cart_item->qty);
                            $bundleName = data_get($options, 'bundle_name');
                            $bundleItems = data_get($options, 'bundle_items', []);
                        @endphp
                        <div class="d-block d-md-none cart-card">
                            <div class="row">
                                <div class="col-6 cart-label">Produk</div>
                                <div class="col-6">
                                    {{ $cart_item->name }}<br>
                                    <span class="badge badge-success">{{ $cart_item->options->code }}</span>
                                    @if(!empty($bundleName))
                                        <div class="small text-muted">Bundle: {{ $bundleName }}</div>
                                    @endif
                                    @if(!empty($bundleItems))
                                        <ul class="mb-0 mt-2 pl-3 small">
                                            @foreach($bundleItems as $bundleItem)
                                                @php
                                                    $bundleItem = is_array($bundleItem) ? $bundleItem : (array) $bundleItem;
                                                    $lineQty = $bundleItem['line_quantity'] ?? ($cart_item->qty * ($bundleItem['quantity_per_bundle'] ?? 0));
                                                $lineQtyDisplay = rtrim(rtrim(number_format($lineQty, 2, '.', ''), '0'), '.');
                                            @endphp
                                            <li class="mb-1">
                                                <div><strong>{{ $bundleItem['name'] ?? 'Bundle Item' }}</strong></div>
                                                <div class="text-muted">
                                                        {{ $lineQtyDisplay }} × {{ format_currency($bundleItem['price'] ?? 0) }}
                                                        (= {{ format_currency($bundleItem['sub_total'] ?? 0) }})
                                                        <br>
                                                        <small>{{ $bundleItem['quantity_per_bundle'] ?? 0 }} per bundle</small>
                                                </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 cart-label">Harga</div>
                                <div class="col-6">{{ format_currency($lineTotal) }}</div>
                            </div>
                            <div class="row">
                                <div class="col-6 cart-label">Jumlah</div>
                                <div class="col-6">
                                    @if(($cart_item->options->serial_number_required ?? false))
                                        {{-- Read-only qty derived from serial count --}}
                                        <div class="d-inline-flex align-items-center">
                                            <span class="badge badge-secondary mr-2">Qty</span>
                                            <strong>{{ $cart_item->qty }}</strong>
                                        </div>
                                    @else
                                        @include('livewire.includes.product-cart-quantity')
                                    @endif
                                    @if(!empty($conversion_breakdowns[$cartKey] ?? ''))
                                        <small class="text-muted">
                                            ({{ $conversion_breakdowns[$cartKey] }})
                                        </small>
                                    @endif
                                    @if(($cart_item->options->serial_number_required ?? false))
                                        @php $serials = $cart_item->options->serial_numbers ?? []; @endphp
                                        @if(!empty($serials))
                                            <div class="mt-2">
                                                @foreach($serials as $sn)
                                                    @php $snStr = is_array($sn) ? ($sn['serial_number'] ?? '') : (string)$sn; @endphp
                                                    <span class="badge badge-info badge-pill mr-1 mb-1">
                                                {{ $snStr }}
                                                <a href="#"
                                                   class="text-white ml-1"
                                                   style="text-decoration:none;"
                                                   wire:click.prevent='removeSerial("{{ $cart_item->rowId }}", "{{ $snStr }}")'>
                                                   &times;
                                                </a>
                                              </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 cart-label">Aksi</div>
                                <div class="col-6 text-right">
                                    <a href="#" wire:click.prevent="removeItem('{{ $cart_item->rowId }}')">
                                        <i class="bi bi-x-circle font-2xl text-danger"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="alert alert-warning d-block d-md-none">
                        Silahkan cari dan pilih produk terlebih dahulu!
                    </div>
                @endif
            </div>

            {{-- Cart Items: Desktop --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table">
                    <thead>
                    <tr class="text-center">
                        <th class="align-middle">Produk</th>
                        <th class="align-middle">Harga</th>
                        <th class="align-middle">Jumlah</th>
                        <th class="align-middle">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @if($cart_items->isNotEmpty())
                    @foreach($cart_items as $cart_item)
                        @php
                            $options = $cart_item->options->toArray();
                            $cartKey = $options['cart_key'] ?? $cart_item->id;
                            $lineTotal = data_get($options, 'sub_total', $cart_item->price * $cart_item->qty);
                            $bundleName = data_get($options, 'bundle_name');
                            $bundleItems = data_get($options, 'bundle_items', []);
                        @endphp
                        <tr>
                            <td class="align-middle">
                                {{ $cart_item->name }} <br>
                                <span class="badge badge-success">{{ $cart_item->options->code }}</span>
                                @if(!empty($bundleName))
                                    <div class="small text-muted">Bundle: {{ $bundleName }}</div>
                                @endif
                                @if(!empty($bundleItems))
                                    <ul class="mb-0 mt-2 pl-3 small">
                                        @foreach($bundleItems as $bundleItem)
                                            @php
                                                $bundleItem = is_array($bundleItem) ? $bundleItem : (array) $bundleItem;
                                                $lineQty = $bundleItem['line_quantity'] ?? ($cart_item->qty * ($bundleItem['quantity_per_bundle'] ?? 0));
                                                $lineQtyDisplay = rtrim(rtrim(number_format($lineQty, 2, '.', ''), '0'), '.');
                                            @endphp
                                            <li class="mb-1">
                                                <div><strong>{{ $bundleItem['name'] ?? 'Bundle Item' }}</strong></div>
                                                <div class="text-muted">
                                                    {{ $lineQtyDisplay }} × {{ format_currency($bundleItem['price'] ?? 0) }}
                                                    (= {{ format_currency($bundleItem['sub_total'] ?? 0) }})
                                                    <br>
                                                    <small>{{ $bundleItem['quantity_per_bundle'] ?? 0 }} per bundle</small>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="align-middle">{{ format_currency($lineTotal) }}</td>
                            <td class="align-middle">
                                @if(($cart_item->options->serial_number_required ?? false))
                                    {{-- Read-only qty derived from serial count --}}
                                    <div class="d-inline-flex align-items-center">
                                        <span class="badge badge-secondary mr-2">Qty</span>
                                            <strong>{{ $cart_item->qty }}</strong>
                                        </div>
                                @else
                                    @include('livewire.includes.product-cart-quantity')
                                @endif
                                @if(!empty($conversion_breakdowns[$cartKey] ?? ''))
                                    <small class="text-muted">({{ $conversion_breakdowns[$cartKey] }})</small>
                                @endif
                                @if(($cart_item->options->serial_number_required ?? false))
                                    @php $serials = $cart_item->options->serial_numbers ?? []; @endphp
                                    @if(!empty($serials))
                                        <div class="mt-2">
                                                @foreach($serials as $sn)
                                                    @php $snStr = is_array($sn) ? ($sn['serial_number'] ?? '') : (string)$sn; @endphp
                                                    <span class="badge badge-info badge-pill mr-1 mb-1">
                                                    {{ $snStr }}
                                                    <a href="#"
                                                       class="text-white ml-1"
                                                       style="text-decoration:none;"
                                                       wire:click.prevent='removeSerial("{{ $cart_item->rowId }}", "{{ $snStr }}")'>
                                                       &times;
                                                    </a>
                                                  </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif

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
                            <td colspan="4" class="text-center">
                                <span class="text-danger">Silahkan cari dan pilih produk terlebih dahulu!</span>
                            </td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>

            {{-- Summary: Desktop --}}
            <div class="table-responsive d-none d-md-block mt-3">
                <table class="table table-striped">
                    <tr class="text-primary">
                        <th>Total Keseluruhan</th>
                        <th>(=) {{ format_currency($total_amount) }}</th>
                    </tr>
                </table>
            </div>

            {{-- Summary: Mobile --}}
            <div class="cart-summary d-block d-md-none mt-3">
                <div class="mb-2">
                    <div class="cart-label">Total Keseluruhan</div>
                    <div class="cart-summary-total">
                        (=) {{ format_currency($total_amount) }}
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="form-group d-flex justify-content-center flex-wrap mb-0 mt-3">
                <button wire:click="resetCart" type="button" class="btn btn-pill btn-danger mr-3">
                    <i class="bi bi-x"></i> Reset
                </button>
                <button wire:loading.attr="disabled" wire:click="proceed" type="button"
                        class="btn btn-pill btn-primary" {{ $total_amount == 0 ? 'disabled' : '' }}>
                    <i class="bi bi-check"></i> Lanjutkan
                </button>
            </div>
        </div>
    </div>

    {{-- Modals --}}
    @include('livewire.pos.includes.checkout-modal')
    @include('livewire.pos.includes.change-modal')
    @include('livewire.sale.includes.bundle-confirmation-modal')
    <livewire:customer.create-modal/>
    <livewire:pos.serial-number-picker />
</div>
