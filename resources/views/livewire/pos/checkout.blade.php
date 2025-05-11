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
                        <div class="d-block d-md-none cart-card">
                            <div class="row">
                                <div class="col-6 cart-label">Produk</div>
                                <div class="col-6">
                                    {{ $cart_item->name }}<br>
                                    <span class="badge badge-success">{{ $cart_item->options->code }}</span>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 cart-label">Harga</div>
                                <div class="col-6">{{ format_currency($cart_item->price) }}</div>
                            </div>
                            <div class="row">
                                <div class="col-6 cart-label">Jumlah</div>
                                <div class="col-6">
                                    @include('livewire.includes.product-cart-quantity')
                                    @if(!empty($conversion_breakdowns[$cart_item->id]))
                                        <small class="text-muted">
                                            ({{ $conversion_breakdowns[$cart_item->id] }})
                                        </small>
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
                            <tr>
                                <td class="align-middle">
                                    {{ $cart_item->name }} <br>
                                    <span class="badge badge-success">{{ $cart_item->options->code }}</span>
                                </td>
                                <td class="align-middle">{{ format_currency($cart_item->price) }}</td>
                                <td class="align-middle">
                                    @include('livewire.includes.product-cart-quantity')
                                    @if(!empty($conversion_breakdowns[$cart_item->id]))
                                        <small class="text-muted">({{ $conversion_breakdowns[$cart_item->id] }})</small>
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
    @include('livewire.sale.includes.bundle-confirmation-modal')
    <livewire:customer.create-modal/>
</div>
