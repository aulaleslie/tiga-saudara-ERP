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
            @foreach($aggregatedProducts as $product)
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
                               name="dispatched_quantities[{{ $product['product_id'] }}]"
                               value="0"
                               min="0"
                               max="{{ $product['total_quantity'] - $product['dispatched_quantity'] }}"
                               class="form-control">
                    </td>
                    <td>
                        <select id="location_{{ $product['product_id'] }}" class="form-control"
                                wire:model="selectedLocations.{{ $product['product_id'] }}">
                            <option value="">-- Pilih Lokasi --</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>{{ $stockAtLocations[$product['product_id']] ?? 'N/A' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
