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
                <th>Jumlah Pajak</th>
                <th>Jumlah Non Pajak</th>
                <th>Rusak Pajak</th>
                <th>Rusak Non Pajak</th>
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

                        @if(!empty($tableValidationErrors["row.{$i}"]))
                            <span class="text-danger small">
                                {{ $tableValidationErrors["row.{$i}"] }}
                            </span>
                        @endif
                    </td>

                    <td class="text-center">
                        <span
                            class="badge badge-info"
                            title="Pajak: {{ $p['stock']['quantity_tax'] }} | Non Pajak: {{ $p['stock']['quantity_non_tax'] }} | Rusak Pajak: {{ $p['stock']['broken_quantity_tax'] }} | Rusak Non Pajak: {{ $p['stock']['broken_quantity_non_tax'] }}">
                            {{ $p['stock']['total'] }}
                        </span>
                    </td>

                    @php
                        $fields = [
                            'quantity_tax'             => ['label' => 'Jumlah Pajak', 'is_taxed' => true,  'is_broken' => false],
                            'quantity_non_tax'         => ['label' => 'Jumlah Non Pajak', 'is_taxed' => false, 'is_broken' => false],
                            'broken_quantity_tax'      => ['label' => 'Rusak Pajak', 'is_taxed' => true,  'is_broken' => true],
                            'broken_quantity_non_tax'  => ['label' => 'Rusak Non Pajak', 'is_taxed' => false, 'is_broken' => true],
                        ];
                        $serialRequired = $p['serial_number_required'] ?? false;
                        $serials = collect($p['serial_numbers'] ?? []);
                    @endphp

                    @foreach($fields as $field => $config)
                        <td>
                            @if($serialRequired)
                                <div class="d-flex flex-column">
                                    <div class="mb-2">
                                        @if($originLocationId)
                                            <livewire:auto-complete.serial-number-loader
                                                :location-id="$originLocationId"
                                                :product-id="$p['id']"
                                                :is-taxed="$config['is_taxed']"
                                                :is-broken="$config['is_broken']"
                                                :serial-index="'transfer-' . $field . '-' . $i"
                                                :product-composite-key="$i"
                                                :is-dispatch="true"
                                                wire:key="transfer-serial-{{ $field }}-{{ $i }}"
                                            />
                                        @else
                                            <div class="alert alert-warning mb-2 py-1 px-2">
                                                <small>Pilih lokasi asal terlebih dahulu.</small>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="form-control-plaintext text-center font-weight-bold">
                                        {{ $p[$field] ?? 0 }}
                                    </div>

                                    @php
                                        $filteredSerials = $serials->filter(function ($serial, $serialIndex) use ($config) {
                                            $matchesTax   = (bool) ($serial['taxable'] ?? false) === (bool) $config['is_taxed'];
                                            $matchesBroken = (bool) ($serial['is_broken'] ?? false) === (bool) $config['is_broken'];

                                            return $matchesTax && $matchesBroken;
                                        });
                                    @endphp

                                    <div class="mt-2">
                                        @if($filteredSerials->isEmpty())
                                            <small class="text-muted">Belum ada nomor seri.</small>
                                        @else
                                            <div class="d-flex flex-wrap">
                                                @foreach($filteredSerials as $serialIndex => $serial)
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

                                    @if($loop->first && isset($tableValidationErrors["row.{$i}.serial_numbers"]))
                                        <div class="text-danger small mt-1">
                                            {{ $tableValidationErrors["row.{$i}.serial_numbers"] }}
                                        </div>
                                    @endif

                                    @if(isset($tableValidationErrors["row.{$i}.{$field}"]))
                                        <div class="text-danger small mt-1">
                                            {{ $tableValidationErrors["row.{$i}.{$field}"] }}
                                        </div>
                                    @endif
                                </div>
                            @else
                                <input
                                    type="number"
                                    min="0"
                                    class="form-control"
                                    wire:model.lazy="products.{{ $i }}.{{ $field }}"
                                >

                                {{-- field-level error --}}
                                @if(isset($tableValidationErrors["row.{$i}.{$field}"]))
                                    <div class="text-danger small mt-1">
                                        {{ $tableValidationErrors["row.{$i}.{$field}"] }}
                                    </div>
                                @endif
                            @endif
                        </td>
                    @endforeach

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
