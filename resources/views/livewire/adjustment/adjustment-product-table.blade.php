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
        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat...</span>
            </div>
        </div>

        <!-- Hidden input for location_id -->
        <input type="hidden" name="location_id" value="{{ $locationId }}">

        <table class="table table-bordered">
            <thead>
            <tr class="align-middle">
                <th class="align-middle">No</th>
                <th class="align-middle">Nama Produk</th>
                <th class="align-middle">Kode Produk</th>
                <th class="align-middle">Satuan</th>
                <th class="align-middle">Kuantitas Non Pajak</th>
                <th class="align-middle">Kuantitas Pajak</th>
                <th class="align-middle">Total Kuantitas</th>
                <th class="align-middle">Aksi</th>
            </tr>
            </thead>
            <tbody>
            @if(!empty($products))
                @foreach($products as $key => $product)
                    <tr>
                        <td class="align-middle">{{ $key + 1 }}</td>
                        <td class="align-middle">{{ $product['product_name'] ?? $product['product']['product_name'] }}</td>
                        <td class="align-middle">{{ $product['product_code'] ?? $product['product']['product_code'] }}</td>
                        <input type="hidden" name="product_ids[]"
                               value="{{ $product['product']['id'] ?? $product['id'] }}">
                        <td class="align-middle">{{ $product['unit'] }}</td>

                        <td class="align-middle">
                            <input type="number" name="quantities_non_tax[{{ $key }}]" class="form-control"
                                   wire:model.lazy="quantities.{{ $key }}.non_tax"
                                   inputmode="numeric" pattern="[0-9]*" min="0"
                                {{ $product['serial_number_required'] ? 'readonly' : '' }}>
                        </td>

                        <td class="align-middle">
                            <input type="number" name="quantities_tax[{{ $key }}]" class="form-control"
                                   wire:model.lazy="quantities.{{ $key }}.tax"
                                   inputmode="numeric" pattern="[0-9]*" min="0"
                                {{ $product['serial_number_required'] ? 'readonly' : '' }}>
                        </td>

                        <td class="align-middle text-center">
                            {{ ($quantities[$key]['non_tax'] ?? 0) + ($quantities[$key]['tax'] ?? 0) }}
                        </td>
                        <td class="align-middle text-center">
                            <button type="button" class="btn btn-danger" wire:click="removeProduct({{ $key }})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>

                    {{-- Serial Number Section --}}
                    @if (!empty($product['serial_number_required']))
                        <tr>
                            <td colspan="9">
                                <div class="p-3 border rounded bg-light">
                                    <strong>Serial Numbers</strong>
                                    <livewire:purchase-return.purchase-order-serial-number-loader
                                        :index="$key"
                                        :product_id="$product['id']"
                                        :location_id="$locationId"
                                        wire:key="serial-number-{{ $key }}"/>

                                    @error("products.{$key}.serial_numbers")
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror

                                    <table class="table table-sm mt-2">
                                        <thead>
                                        <tr>
                                            <th>Serial Number</th>
                                            <th>Pajak</th> {{-- ✅ New column --}}
                                            <th class="text-center" style="width: 5%;">Remove</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($product['serial_numbers'] ?? [] as $serialIndex => $serialNumber)
                                            <tr>
                                                <td>{{ $serialNumber['serial_number'] }}</td>
                                                <input type="hidden" name="serial_numbers[{{ $key }}][{{ $serialIndex }}][id]" value="{{ $serialNumber['id'] }}">
                                                <input type="hidden" name="serial_numbers[{{ $key }}][{{ $serialIndex }}][taxable]" value="{{ $serialNumber['taxable'] ? 1 : 0 }}">

                                                {{-- ✅ New checkbox based on tax_id --}}
                                                <td class="text-center">
                                                    <input
                                                        type="checkbox"
                                                        wire:change="toggleSerialTaxable({{ $key }}, {{ $serialIndex }})"
                                                        wire:model="products.{{ $key }}.serial_numbers.{{ $serialIndex }}.taxable"
                                                    >
                                                </td>

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
                    <td colspan="9" class="text-center">
                            <span class="text-danger">
                                Silahkan Cari & Pilih Produk!
                            </span>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
</div>
