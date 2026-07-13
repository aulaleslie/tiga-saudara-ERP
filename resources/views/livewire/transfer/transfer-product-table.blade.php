<div>
    @if (session()->has('message'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="close" data-dismiss="alert">×</button>
        </div>
    @endif

    <div class="table-responsive position-relative">
        <div wire:loading class="overlay">
            <div class="spinner-border text-primary" role="status"></div>
        </div>

        <table class="table table-bordered">
            <thead>
            <tr class="align-middle">
                <th>#</th>
                <th>Nama Produk</th>
                <th>Stok</th>
                <th>Jumlah</th>
                <th>Alokasi</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($products as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>

                    <td>
                        {{ $p['product_name'] }}
                        <div><span class="badge badge-secondary">{{ $p['product_code'] }}</span></div>

                        @if(!empty($tableValidationErrors["products.{$i}"]))
                            <span class="text-danger small">
                                {{ $tableValidationErrors["products.{$i}"] }}
                            </span>
                        @endif
                    </td>

                    @php
                        $serialRequired = $p['serial_number_required'] ?? false;
                        $serials = collect($p['serial_numbers'] ?? []);
                        $isBrokenMode = $p['is_broken_mode'] ?? false;
                    @endphp

                    <!-- Stock Column: Show tax/non-tax buckets based on mode -->
                    <td class="text-center">
                        <div class="small">
                            @if($isBrokenMode)
                                <div>
                                    <strong>Rusak Non Pajak:</strong> {{ $p['stock']['broken_quantity_non_tax'] ?? 0 }}
                                </div>
                                <div>
                                    <strong>Rusak Pajak:</strong> {{ $p['stock']['broken_quantity_tax'] ?? 0 }}
                                </div>
                                <div class="mt-1 font-weight-bold text-primary">
                                    Total: {{ ($p['stock']['broken_quantity_non_tax'] ?? 0) + ($p['stock']['broken_quantity_tax'] ?? 0) }}
                                </div>
                            @else
                                <div>
                                    <strong>Non Pajak:</strong> {{ $p['stock']['quantity_non_tax'] ?? 0 }}
                                </div>
                                <div>
                                    <strong>Pajak:</strong> {{ $p['stock']['quantity_tax'] ?? 0 }}
                                </div>
                                <div class="mt-1 font-weight-bold text-primary">
                                    Total: {{ ($p['stock']['quantity_non_tax'] ?? 0) + ($p['stock']['quantity_tax'] ?? 0) }}
                                </div>
                            @endif
                        </div>
                    </td>

                    <!-- Single Quantity Input Column -->
                    <td>
                        @if($serialRequired)
                            <!-- Serial Products: Use serial selection, quantity is derived -->
                            <div class="d-flex flex-column">
                                <div class="mb-2">
                                    @if($originLocationId)
                                        <livewire:auto-complete.serial-number-loader
                                            :location-id="$originLocationId"
                                            :product-id="$p['id']"
                                            :serial-index="'transfer-serials-' . $i"
                                            :product-composite-key="$i"
                                            :is-broken="$isBrokenMode"
                                            :is-dispatch="true"
                                            wire:key="transfer-serial-{{ $i }}"
                                        />
                                    @else
                                        <div class="alert alert-warning mb-2 py-1 px-2">
                                            <small>Pilih lokasi asal terlebih dahulu.</small>
                                        </div>
                                    @endif
                                </div>

                                <div class="form-control-plaintext text-center font-weight-bold">
                                    {{ count($serials) }}
                                </div>

                                <div class="mt-2">
                                    @if($serials->isEmpty())
                                        <small class="text-muted">Belum ada nomor seri.</small>
                                    @else
                                        <div class="d-flex flex-wrap">
                                            @foreach($serials as $serialIndex => $serial)
                                                <span class="badge badge-light border d-flex align-items-center mb-1 mr-1">
                                                    <span>{{ $serial['serial_number'] }}</span>
                                                    <button type="button"
                                                        class="btn btn-link btn-sm text-danger p-0 ml-2"
                                                        wire:click="removeSerialNumber({{ $i }}, {{ $serialIndex }})"
                                                        title="Hapus nomor seri">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                @if(isset($tableValidationErrors["products.{$i}.serial_numbers"]))
                                    <div class="text-danger small mt-1">
                                        {{ $tableValidationErrors["products.{$i}.serial_numbers"] }}
                                    </div>
                                @endif
                            </div>
                        @else
                            <!-- Non-Serial Products: Single quantity input with auto-allocation -->
                            <div>
                                <input
                                    type="number"
                                    min="0"
                                    class="form-control"
                                    wire:model.live="products.{{ $i }}.requested_quantity"
                                    placeholder="Jumlah dasar"
                                    title="Masukkan jumlah dalam satuan dasar. Alokasi pajak/non-pajak dihitung otomatis."
                                >

                                @if(isset($tableValidationErrors["products.{$i}.requested_quantity"]))
                                    <div class="text-danger small mt-1">
                                        {{ $tableValidationErrors["products.{$i}.requested_quantity"] }}
                                    </div>
                                @endif
                            </div>
                        @endif
                    </td>

                    <!-- Allocation Display Column -->
                    <td class="text-center">
                        <div class="small">
                            @if($serialRequired)
                                <!-- Serial: Show breakdown of selected serials -->
                                @php
                                    $taxCount = $serials->filter(fn($s) => (bool)($s['taxable'] ?? false) && !(bool)($s['is_broken'] ?? false))->count();
                                    $nonTaxCount = $serials->filter(fn($s) => !(bool)($s['taxable'] ?? false) && !(bool)($s['is_broken'] ?? false))->count();
                                    $brokenTaxCount = $serials->filter(fn($s) => (bool)($s['taxable'] ?? false) && (bool)($s['is_broken'] ?? false))->count();
                                    $brokenNonTaxCount = $serials->filter(fn($s) => !(bool)($s['taxable'] ?? false) && (bool)($s['is_broken'] ?? false))->count();
                                @endphp
                                @if($isBrokenMode)
                                    @if($brokenNonTaxCount > 0)
                                        <div>Rusak Non Pajak: {{ $brokenNonTaxCount }}</div>
                                    @endif
                                    @if($brokenTaxCount > 0)
                                        <div class="text-warning">Rusak Pajak: {{ $brokenTaxCount }} <i class="bi bi-exclamation-circle"></i></div>
                                    @endif
                                @else
                                    @if($nonTaxCount > 0)
                                        <div>Non Pajak: {{ $nonTaxCount }}</div>
                                    @endif
                                    @if($taxCount > 0)
                                        <div class="text-warning">Pajak: {{ $taxCount }} <i class="bi bi-exclamation-circle"></i></div>
                                    @endif
                                @endif
                            @else
                                <!-- Non-Serial: Show calculated allocation -->
                                @php
                                    if ($isBrokenMode) {
                                        $nonTaxAlloc = $p['broken_quantity_non_tax'] ?? 0;
                                        $taxAlloc = $p['broken_quantity_tax'] ?? 0;
                                        $label = 'Rusak';
                                    } else {
                                        $nonTaxAlloc = $p['quantity_non_tax'] ?? 0;
                                        $taxAlloc = $p['quantity_tax'] ?? 0;
                                        $label = '';
                                    }
                                @endphp

                                @if($nonTaxAlloc > 0)
                                    <div>{{ $isBrokenMode ? 'R' : '' }}Non Pajak: {{ $nonTaxAlloc }}</div>
                                @endif
                                @if($taxAlloc > 0)
                                    <div class="text-warning">{{ $isBrokenMode ? 'R' : '' }}Pajak: {{ $taxAlloc }} <i class="bi bi-exclamation-circle" title="Stok pajak harus dikembalikan lintas lokasi"></i></div>
                                @endif
                                @if($nonTaxAlloc === 0 && $taxAlloc === 0)
                                    <span class="text-muted">-</span>
                                @endif
                            @endif
                        </div>
                    </td>

                    <td class="text-center">
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            wire:click="removeProduct({{ $i }})"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                @if(($p['serial_number_required'] ?? false) && !empty($serialNumberErrors[$i]))
                    <tr>
                        <td colspan="8" class="text-danger small">
                            {{ $serialNumberErrors[$i] }}
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        Silahkan cari dan pilih produk!
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
