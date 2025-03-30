<div class="card">
    <div class="card-header">
        Daftar Produk
    </div>
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
                <tr>
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
                    <tr>
                        <td colspan="6">
                            hello

                        </td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
</div>
