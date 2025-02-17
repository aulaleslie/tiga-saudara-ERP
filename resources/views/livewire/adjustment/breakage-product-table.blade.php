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
                <span class="sr-only">Memuat...</span>
            </div>
        </div>
        <table class="table table-bordered">
            <thead>
            <tr class="align-middle">
                <th class="align-middle">No</th>
                <th class="align-middle">Nama Produk</th>
                <th class="align-middle">Kode Produk</th>
                <th class="align-middle">Stok</th>
                <th class="align-middle">Kuantitas</th>
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
                        <td class="align-middle text-center">
                            <span class="badge badge-info">
                                {{ $product['quantity_tax'] + $product['quantity_non_tax'] }} {{ $product['unit'] }}
                            </span>
                            <!-- Tooltip Container -->
                            <span class="d-inline-block"
                                  data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  title="Stok Pajak: {{ $product['quantity_tax'] }} {{ $product['unit'] }} | Stok Non-Pajak: {{ $product['quantity_non_tax'] }} {{ $product['unit'] }} | Rusak Pajak: {{ $product['broken_quantity_tax'] }} {{ $product['unit'] }} | Rusak Non-Pajak: {{ $product['broken_quantity_non_tax'] }} {{ $product['unit'] }}">
                                <i class="bi bi-info-circle text-primary" style="cursor: pointer;"></i>
                            </span>
                        </td>
                        <input type="hidden" name="product_ids[]" value="{{ $product['product']['id'] ?? $product['id'] }}">
                        <td class="align-middle">
                            <input type="number" name="quantities[]" min="1" class="form-control" value="{{ 1 }}">

                            @if (empty($product['serial_number_required']))
                                <div class="form-check">
                                    <!-- Hidden input ensures false (0) is sent when checkbox is unchecked -->
                                    <input type="hidden" name="is_taxables[{{ $key }}]" value="0">

                                    <!-- Checkbox input for taxable option -->
                                    <input type="checkbox"
                                           name="is_taxables[{{ $key }}]"
                                           value="1"
                                           class="form-check-input"
                                           id="taxable-{{ $key }}"
                                        {{ old("is_taxables.{$key}", $product['is_taxable'] ?? 0) == 1 ? 'checked' : '' }}>

                                    <label class="form-check-label" for="taxable-{{ $key }}">Kena Pajak</label>
                                </div>
                            @endif
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
                            <td colspan="6">
                                <div class="p-3 border rounded bg-light">
                                    <strong>Serial Numbers</strong>
                                    <livewire:purchase-return.purchase-order-serial-number-loader
                                        :index="$key"
                                        :product_id="$product['id']"
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
                            Silahkan Cari & Pilih Produk!
                        </span>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
</div>
