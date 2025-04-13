<div class="card">
    <div class="card-header">
        Daftar Produk
    </div>
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
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <thead>
            <tr>
                <th>Nama Produk</th>
                <th>Jumlah Penjualan</th>
                <th>Jumlah yang Dikirim</th>
                <th>Jumlah yang akan Dikirim</th>
                <th>Lokasi</th>
                <th>Stok di Lokasi</th>
            </tr>
            </thead>
            <tbody>
            @foreach($aggregatedProducts as $key => $product)
                <tr wire:key="product-row-{{ $key }}">
                    <td>
                        {{ $product['product_name'] }}
                        <br>
                        <span class="badge bg-secondary">{{ $product['product_code'] }}</span>
                        @if($product['tax_id'])
                            <span class="badge bg-primary text-white">PPN</span>
                        @else
                            <span class="badge bg-secondary">Non PPN</span>
                        @endif
                    </td>
                    <td>{{ $product['total_quantity'] }}</td>
                    <td>{{ $product['dispatched_quantity'] }}</td>
                    <td>
                        <input type="number"
                               name="dispatchedQuantities[{{ $key }}]"
                               value="{{ $dispatchedQuantities[$key] ?? 0 }}"
                               min="0"
                               max="{{ $product['total_quantity'] - $product['dispatched_quantity'] }}"
                               class="form-control"
                               wire:model="dispatchedQuantities.{{ $key }}"
                               wire:change="quantityUpdated($event.target.value, '{{ $key }}')">
                    </td>
                    <td>
                        <select id="location_{{ $key }}" class="form-control"
                                wire:model="selectedLocations.{{ $key }}"
                                wire:change="locationChanged($event.target.value, '{{ $key }}')">
                            <option value="">-- Pilih Lokasi --</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>{{ $stockAtLocations[$key] ?? 'N/A' }}</td>
                </tr>
                @if($serialNumberRequiredFlags[$key] && (($selectedLocations[$key] ?? 0) > 0) && (($dispatchedQuantities[$key] ?? 0) > 0))
                    <tr wire:key="serial-loader-{{ $key }}">
                        <td colspan="6">
                            @for ($i = 0; $i < ($dispatchedQuantities[$key] ?? 0); $i++)
                                <div class="row mb-3">
                                    <div class="col-sm-6">
                                        <livewire:auto-complete.serial-number-loader
                                            :locationId="$selectedLocations[$key]"
                                            :productId="$product['product_id']"
                                            :isTaxed="$product['tax_id']"
                                            :isBroken="false"
                                            :isDispatch="true"
                                            :serialIndex="$i"
                                            :productCompositeKey="$key"
                                            wire:key="{{ $key . '-' . $i . '-' . ($selectedLocations[$key] ?? 0) . '-' . ($dispatchedQuantities[$key] ?? 0) }}"
                                        />
                                    </div>
                                </div>
                            @endfor
                        </td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
    @foreach($selectedSerialNumbers as $compositeKey => $serials)
        @foreach($serials as $index => $serial)
            <input type="hidden" name="selectedSerialNumbers[{{ $compositeKey }}][{{ $index }}]" value="{{ $serial }}">
        @endforeach
    @endforeach
    @foreach($selectedLocations as $key => $location)
        <input type="hidden" name="selectedLocations[{{ $key }}]" value="{{ $location }}">
    @endforeach

    @foreach($stockAtLocations as $key => $stock)
        <input type="hidden" name="stockAtLocations[{{ $key }}]" value="{{ $stock }}">
    @endforeach
</div>
