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

        <!-- Hidden input for location_id -->
        <input type="hidden" name="location_id" value="{{ $locationId }}">

        <table class="table table-bordered">
            <thead>
            <tr class="align-middle">
                <th class="align-middle">No</th>
                <th class="align-middle">Nama Produk</th>
                <th class="align-middle">Kode Produk</th>
                <th class="align-middle">Satuan</th>
                <th class="align-middle">Stok</th>
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
                        <input type="hidden" name="product_ids[]" value="{{ $product['product']['id'] ?? $product['id'] }}">
                        <td class="align-middle">{{ $product['unit'] ?? '' }}</td>
                        <td class="align-middle text-center">
                            <span class="d-inline-flex align-items-center justify-content-center gap-1">
                                <span class="badge badge-info mb-0">
                                    {{ ($product['quantity_tax'] ?? 0) + ($product['quantity_non_tax'] ?? 0) }} {{ $product['unit'] ?? '' }}
                                </span>
                                <span class="d-inline-flex"
                                      data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      title="Stok Pajak: {{ $product['quantity_tax'] ?? 0 }} {{ $product['unit'] ?? '' }} | Stok Non-Pajak: {{ $product['quantity_non_tax'] ?? 0 }} {{ $product['unit'] ?? '' }} | Rusak Pajak: {{ $product['broken_quantity_tax'] ?? 0 }} {{ $product['unit'] ?? '' }} | Rusak Non-Pajak: {{ $product['broken_quantity_non_tax'] ?? 0 }} {{ $product['unit'] ?? '' }}">
                                    <i class="bi bi-info-circle text-primary" style="cursor: pointer;"></i>
                                </span>
                            </span>
                        </td>
                        <td class="align-middle">
                            <input type="number"
                                   name="quantities_non_tax[{{ $key }}]"
                                   class="form-control"
                                   wire:model.lazy="quantities.{{ $key }}.non_tax"
                                   inputmode="numeric"
                                   pattern="[0-9]*"
                                   min="0"
                                {{ !empty($product['serial_number_required']) ? 'readonly' : '' }}>
                        </td>
                        <td class="align-middle">
                            <input type="number"
                                   name="quantities_tax[{{ $key }}]"
                                   class="form-control"
                                   wire:model.lazy="quantities.{{ $key }}.tax"
                                   inputmode="numeric"
                                   pattern="[0-9]*"
                                   min="0"
                                {{ !empty($product['serial_number_required']) ? 'readonly' : '' }}>
                        </td>
                        <td class="align-middle">
                            @php
                                $totalQuantity = !empty($product['serial_number_required'])
                                    ? count($product['serial_numbers'] ?? [])
                                    : (int) ($quantities[$key]['non_tax'] ?? 0) + (int) ($quantities[$key]['tax'] ?? 0);
                            @endphp
                            <input type="number"
                                   class="form-control text-center"
                                   value="{{ $totalQuantity }}"
                                {{ !empty($product['serial_number_required']) ? 'readonly' : '' }}>
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
                                        :location_id="$locationId"   {{-- <-- camelCase prop name --}}
                                        :is_broken="false"
                                        wire:key="serial-number-{{ $key }}-prod-{{ $product['id'] }}-loc-{{ $locationId ?? 'none' }}"
                                    />

                                    @error("products.{$key}.serial_numbers")
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                    @if (!empty($serialNumberErrors[$key]))
                                        <span class="text-danger">{{ $serialNumberErrors[$key] }}</span>
                                    @endif

                                    <table class="table table-sm mt-2">
                                        <thead>
                                        <tr>
                                            <th>Serial Number</th>
                                            <th>Kategori</th>
                                            <th class="text-center" style="width: 5%;">Remove</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($product['serial_numbers'] ?? [] as $serialIndex => $serialNumber)
                                            <tr>
                                                <td>{{ $serialNumber['serial_number'] }}</td>
                                                <input type="hidden" name="serial_numbers[{{ $key }}][]" value="{{ $serialNumber['id'] }}">
                                                <td>
                                                    <span class="badge {{ ($serialNumber['taxable'] ?? false) ? 'badge-success' : 'badge-secondary' }}">
                                                        {{ ($serialNumber['taxable'] ?? false) ? 'Kena Pajak' : 'Tidak Kena Pajak' }}
                                                    </span>
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
