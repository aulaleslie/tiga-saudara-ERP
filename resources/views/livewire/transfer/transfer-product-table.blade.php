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
    <div class="table-responsive">
        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center" style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Loading...</span>
            </div>
        </div>
        <table class="table table-bordered">
            <thead>
            <tr class="align-middle">
                <th class="align-middle">#</th>
                <th class="align-middle">Product Name</th>
                <th class="align-middle">Code</th>
                <th class="align-middle">Stock</th>
                <th class="align-middle">Quantity</th>
                <th class="align-middle">Action</th>
            </tr>
            </thead>
            <tbody>
            @if(!empty($products))
                @foreach($products as $key => $product)
                    <tr>
                        <td class="align-middle">{{ $key + 1 }}</td>
                        <td class="align-middle">{{ $product['product_name'] ?? $product['product']['product_name'] }}</td>
                        <td class="align-middle">{{ $product['product_code'] ?? $product['product']['product_code'] }}</td>
                        <td class="align-middle text-center">
                            <span class="badge badge-info">
                                {{ $product['product_quantity'] }} {{ $product['product_unit'] }}
                            </span>
                        </td>
                        <input type="hidden" name="product_ids[]" value="{{ $product['product']['id'] ?? $product['id'] }}">
                        <td class="align-middle">
                            @if (!empty($product['serial_number_required']))
                                <input type="number"
                                       name="quantities[]"
                                       class="form-control"
                                       wire:model.defer="products.{{ $key }}.quantity"
                                       readonly>
                            @else
                                <input type="number"
                                       name="quantities[]"
                                       min="1"
                                       class="form-control"
                                       wire:model.defer="products.{{ $key }}.quantity">
                            @endif
                        </td>
                        <td class="align-middle text-center">
                            <button type="button" class="btn btn-danger" wire:click="removeProduct({{ $key }})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>

                    @if (!empty($product['serial_number_required']))
                        <tr>
                            <td colspan="6">
                                <div class="p-3 border rounded bg-light">
                                    <strong>Serial Numbers</strong>
                                    {{-- You need a serial number picker here. Example: --}}
                                    <livewire:purchase-return.purchase-order-serial-number-loader
                                        :index="$key"
                                        :product_id="$product['id'] ?? $product['product']['id']"
                                        :location_id="$locationId"
                                        :is_transfer="true"
                                        wire:key="serial-number-{{ $key }}" />

                                    @error("products.{$key}.serial_numbers")
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror

                                    <table class="table table-sm mt-2">
                                        <thead>
                                        <tr>
                                            <th>Serial Number</th>
                                            <th class="text-center" style="width: 5%;">Remove</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($product['serial_numbers'] ?? [] as $serialIndex => $serialNumber)
                                            <tr>
                                                <td>{{ $serialNumber['serial_number'] }}</td>
                                                <input type="hidden" name="serial_numbers[{{ $key }}][]" value="{{ $serialNumber['id'] }}">
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-danger btn-sm rounded-circle"
                                                            wire:click="removeSerialNumber({{ $key }}, {{ $serialIndex }})">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
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
                    <td colspan="7" class="text-center">
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
