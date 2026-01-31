<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-0 pb-0">
        <h5 class="mb-1">Daftar Produk</h5>
        <p class="text-muted small mb-0">Kelola kuantitas pengiriman dan scan nomor seri untuk produk yang relevan.</p>
    </div>

    @if (session()->has('message'))
        <div class="px-3 pt-3">
            <div class="alert alert-warning alert-dismissible fade show mb-0" role="alert">
                <div class="alert-body d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle mr-2"></i>
                    <span>{{ session('message') }}</span>
                    <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="card-body">
        <div class="table-responsive rounded border">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr class="text-uppercase small text-muted font-weight-bold">
                    <th class="px-3" style="width: 35%;">Produk</th>
                    <th class="text-center" style="width: 10%;">Jml Pesanan</th>
                    <th class="text-center" style="width: 10%;">Terkirim</th>
                    <th class="text-center" style="width: 15%;">Lokasi</th>
                    <th class="text-center" style="width: 12%;">Stok</th>
                    <th class="text-center" style="width: 18%;">Akan Dikirim</th>
                </tr>
                </thead>
                <tbody>
                @foreach($aggregatedProducts as $key => $product)
                    {{-- PARENT ROW --}}
                    <tr wire:key="product-row-{{ $key }}" class="{{ $serialNumberRequiredFlags[$key] ? 'bg-light-row' : '' }}">
                        <td class="px-3">
                            <div class="d-flex flex-column">
                                <span class="fw-bold text-dark">{{ $product['product_name'] }}</span>
                                <div class="mt-1 d-flex align-items-center gap-2">
                                    <span class="badge bg-light text-muted border">{{ $product['product_code'] }}</span>
                                    @if($product['tax_id'])
                                        <span class="badge bg-primary-soft text-primary">PPN</span>
                                    @else
                                        <span class="badge bg-secondary-soft text-secondary">Non PPN</span>
                                    @endif
                                </div>

                                @if($serialNumberRequiredFlags[$key])
                                    <div class="mt-3" wire:ignore>
                                        <div class="input-group input-group-sm mb-1" style="max-width: 250px;">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text bg-white border-right-0">
                                                    <i class="bi bi-qr-code-scan text-primary"></i>
                                                </span>
                                            </div>
                                            <input type="text"
                                                   class="form-control serial-input border-left-0 pl-1"
                                                   id="serial-input-{{ $key }}"
                                                   placeholder="Scan S/N..."
                                                   onkeydown="handleSerialKeydown(event, '{{ $key }}', {{ $product['product_id'] }}, {{ $product['tax_id'] ?? 'null' }})">
                                            <div class="input-group-append">
                                                <button class="btn btn-primary" type="button" 
                                                        onclick="addSerialFromInput('{{ $key }}', {{ $product['product_id'] }}, {{ $product['tax_id'] ?? 'null' }})">
                                                    <i class="bi bi-plus-lg"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div id="serial-error-{{ $key }}" class="text-danger small mt-1 d-none font-italic"></div>
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="fw-bold">{{ $product['total_quantity'] }}</span>
                        </td>
                        <td class="text-center text-muted">
                            {{ $product['dispatched_quantity'] }}
                        </td>

                        @if($serialNumberRequiredFlags[$key])
                            <td colspan="2" class="text-center">
                                <span class="text-muted small font-italic">
                                    <i class="bi bi-info-circle mr-1"></i> Per-Serial
                                </span>
                            </td>
                            <td>
                                <div class="input-group input-group-sm mx-auto" style="width: 80px;">
                                    <input type="number"
                                           id="quantity-{{ $key }}"
                                           name="dispatchedQuantities[{{ $key }}]"
                                           value="{{ $dispatchedQuantities[$key] ?? 0 }}"
                                           readonly
                                           class="form-control text-center bg-light fw-bold text-primary border-primary">
                                </div>
                            </td>
                        @else
                            <td class="px-2">
                                <select id="location_{{ $key }}" class="form-control form-control-sm custom-select"
                                        wire:model.live="selectedLocations.{{ $key }}">
                                    <option value="">-- Pilih --</option>
                                    @foreach($locations as $location)
                                        <option value="{{ $location->id }}">
                                            {{ $location->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="selectedLocations[{{ $key }}]" value="{{ $selectedLocations[$key] ?? '' }}">
                            </td>
                            <td class="text-center">
                                <span class="badge {{ ($stockAtLocations[$key] ?? 0) > 0 ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger' }} px-2 py-1">
                                    {{ $stockAtLocations[$key] ?? 0 }}
                                </span>
                                <input type="hidden" name="stockAtLocations[{{ $key }}]" value="{{ $stockAtLocations[$key] ?? 0 }}">
                            </td>
                            <td>
                                <div class="input-group input-group-sm mx-auto" style="width: 80px;">
                                    <input type="number"
                                           id="quantity-{{ $key }}"
                                           name="dispatchedQuantities[{{ $key }}]"
                                           value="{{ $dispatchedQuantities[$key] ?? 0 }}"
                                           min="0"
                                           class="form-control text-center shadow-none"
                                           wire:model="dispatchedQuantities.{{ $key }}"
                                           wire:change="quantityUpdated($event.target.value, '{{ $key }}')">
                                </div>
                            </td>
                        @endif
                    </tr>

                    {{-- CHILD ROWS FOR SERIAL NUMBERS --}}
                    @if($serialNumberRequiredFlags[$key])
                        <tr wire:key="serial-sub-table-{{ $key }}" class="bg-light-row border-top-0" wire:ignore>
                            <td colspan="6" class="px-4 py-2 border-top-0">
                                <div class="bg-white rounded border p-2 mb-2 shadow-sm">
                                    <table class="table table-sm table-borderless mb-0">
                                        <thead class="border-bottom small text-muted">
                                            <tr>
                                                <th class="pl-4">SERIAL NUMBER</th>
                                                <th class="text-center">LOKASI ASAL</th>
                                                <th class="text-right pr-4">AKSI</th>
                                            </tr>
                                        </thead>
                                        <tbody id="serial-rows-{{ $key }}">
                                            {{-- JS will inject rows here with addSerialRow --}}
                                        </tbody>
                                        <tfoot id="no-serial-placeholder-{{ $key }}">
                                            @if(empty($serialNumberData[$key]))
                                                <tr>
                                                    <td colspan="3" class="text-center py-3 text-muted small">
                                                        <i class="bi bi-upc-scan mr-1"></i> Belum ada serial number yang di-scan.
                                                    </td>
                                                </tr>
                                            @endif
                                        </tfoot>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    

    @push('page_scripts')
    <style>
        .bg-light-row { background-color: #fbfcfe; }
        .bg-primary-soft { background-color: #e7f1ff; }
        .bg-secondary-soft { background-color: #f0f2f5; }
        .bg-success-soft { background-color: #e3f9eb; }
        .bg-danger-soft { background-color: #fbe9e9; }
        .badge-soft { border: none; }
        .fw-bold { font-weight: 600 !important; }
    </style>
    <script>
        document.addEventListener('livewire:load', function () {
            // Re-render hidden inputs and serial rows if Livewire refreshes
                @foreach($serialNumberData as $compositeKey => $serials)
                    @foreach($serials as $serial => $data)
                        addSerialRow('{{ $compositeKey }}', '{{ $serial }}', {{ $data['location_id'] }}, '{{ $data['location_name'] }}', {{ $data['tax_id'] ?? 'null' }}, false);
                    @endforeach
                @endforeach
        });
    </script>
    @endpush
</div>

