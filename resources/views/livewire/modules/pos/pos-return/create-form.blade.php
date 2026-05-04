<div>
    @if(!$snapshot)
        <div class="card">
            <div class="card-body">
                <form wire:submit.prevent="lookup">
                    <div class="form-group">
                        <label for="identifier">Masukkan Kode Transaksi atau Nomor Struk POS</label>
                        <div class="input-group">
                            <input wire:model="identifier" type="text" id="identifier" class="form-control @error('identifier') is-invalid @enderror" placeholder="Contoh: TRX-2026..., RCP-2026...">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                                    <span wire:loading wire:target="lookup" class="spinner-border spinner-border-sm" role="status"></span>
                                    Cari Transaksi
                                </button>
                            </div>
                        </div>
                        @error('identifier') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </form>

                @if($error)
                    <div class="alert alert-danger mt-3">
                        {{ $error }}
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Snapshot Transaksi: {{ $snapshot['header']['transaction_code'] }}</strong>
                        </div>
                        <button wire:click="resetLookup" class="btn btn-sm btn-secondary">Cari Transaksi Lain</button>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-4">
                                <h6 class="mb-3">Informasi Transaksi:</h6>
                                <div>Struk: <strong>{{ $snapshot['header']['receipt_number'] }}</strong></div>
                                <div>Tanggal: {{ \Carbon\Carbon::parse($snapshot['header']['date'])->format('d M Y H:i') }}</div>
                                <div>Pelanggan: {{ $snapshot['header']['customer_name'] ?? 'Guest' }}</div>
                            </div>
                            <div class="col-sm-4">
                                <h6 class="mb-3">Pembayaran:</h6>
                                @foreach($snapshot['payments'] as $payment)
                                    <div>{{ $payment['method_name'] }}: {{ format_currency($payment['amount']) }}</div>
                                @endforeach
                                <div class="mt-2">Total: <strong>{{ format_currency($snapshot['header']['grand_total']) }}</strong></div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center" title="Jumlah produk yang dapat diretur dari pembelian awal">
                                            Tersedia
                                            <i class="fas fa-info-circle text-muted" style="font-size: 0.85em; cursor: help;" data-toggle="tooltip" data-placement="top" title="Jumlah produk yang tersedia untuk diretur dari pembelian awal"></i>
                                        </th>
                                        <th class="text-center" style="width: 150px;">Jumlah Retur</th>
                                        <th class="text-right">Harga Satuan</th>
                                        <th class="text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($snapshot['lines'] as $line)
                                        @php
                                            $detailId = $line['sale_detail_id'];
                                            $isReturnable = $line['returnable_quantity'] > 0;
                                        @endphp
                                        @if($line['is_bundle'] && !empty($line['bundle_items']))
                                            {{-- Bundle Parent Row --}}
                                            <tr class="bundle-parent-row">
                                                <td>
                                                    <div class="font-weight-bold">{{ $line['product_name'] }}</div>
                                                    <small class="text-muted">{{ $line['product_code'] }}</small>
                                                    <div class="mt-1">
                                                        <small class="badge badge-info">Bundle</small>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <strong>{{ $line['returnable_quantity'] }}</strong>
                                                    @if($line['returned_quantity'] > 0)
                                                        <div class="small text-danger">Ter-retur: {{ $line['returned_quantity'] }}</div>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    @if($isReturnable)
                                                        <input type="number"
                                                               wire:model.live="quantities.{{ $detailId }}"
                                                               class="form-control form-control-sm text-center font-weight-bold"
                                                               min="0"
                                                               max="{{ $line['returnable_quantity'] }}"
                                                               step="1"
                                                               title="Jumlah bundle yang akan diretur">
                                                    @else
                                                        <span class="badge badge-secondary">Habis</span>
                                                    @endif
                                                </td>
                                                <td class="text-right">{{ format_currency($line['unit_price']) }}</td>
                                                <td class="text-right">
                                                    {{ format_currency(($quantities[$detailId] ?? 0) * $line['unit_price']) }}
                                                </td>
                                            </tr>
                                            {{-- Bundle Item Rows (nested, read-only) --}}
                                            @foreach($line['bundle_items'] as $bundleItem)
                                                @php
                                                    $maxBundleItemReturnable = $line['returnable_quantity'] * $bundleItem['quantity_per_bundle'];
                                                    $selectedBundleItemQty = ($quantities[$detailId] ?? 0) * $bundleItem['quantity_per_bundle'];
                                                @endphp
                                                <tr class="bundle-item-row table-light">
                                                    <td class="pl-5">
                                                        <div>{{ $bundleItem['product_name'] }}</div>
                                                        <small class="text-muted">{{ $bundleItem['product_code'] }}</small>
                                                    </td>
                                                    <td class="text-center">
                                                        <small class="text-muted">({{ $bundleItem['quantity_per_bundle'] }} per bundle)</small><br>
                                                        <strong>{{ $maxBundleItemReturnable }}</strong>
                                                    </td>
                                                    <td class="text-center text-muted">
                                                        <small>Otomatis</small><br>
                                                        <small class="text-muted">{{ $selectedBundleItemQty }}</small>
                                                    </td>
                                                    <td class="text-right text-muted">
                                                        <small>-</small>
                                                    </td>
                                                    <td class="text-right text-muted">
                                                        <small>-</small>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            {{-- Regular (Non-Bundle) Row --}}
                                            <tr>
                                                <td>
                                                    <div>{{ $line['product_name'] }}</div>
                                                    <small class="text-muted">{{ $line['product_code'] }}</small>
                                                </td>
                                                <td class="text-center">
                                                    {{ $line['returnable_quantity'] }}
                                                    @if($line['returned_quantity'] > 0)
                                                        <div class="small text-danger">Ter-retur: {{ $line['returned_quantity'] }}</div>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    @if($isReturnable)
                                                        <input type="number"
                                                               wire:model.live="quantities.{{ $detailId }}"
                                                               class="form-control form-control-sm text-center"
                                                               min="0"
                                                               max="{{ $line['returnable_quantity'] }}"
                                                               step="1">
                                                    @else
                                                        <span class="badge badge-secondary">Habis</span>
                                                    @endif
                                                </td>
                                                <td class="text-right">{{ format_currency($line['unit_price']) }}</td>
                                                <td class="text-right">
                                                    {{ format_currency(($quantities[$detailId] ?? 0) * $line['unit_price']) }}
                                                </td>
                                            </tr>
                                        @endif
                                        @if(($quantities[$detailId] ?? 0) > 0 && !empty($line['serial_number_ids']))
                                            <tr>
                                                <td colspan="5" class="bg-light py-2">
                                                    <div class="small font-weight-bold mb-1 text-primary">Pilih Serial Number (Harus pilih {{ $quantities[$detailId] }}):</div>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        @foreach($line['serial_number_ids'] as $snId)
                                                            <div class="custom-control custom-checkbox mr-3">
                                                                <input type="checkbox"
                                                                       id="sn_{{ $detailId }}_{{ $snId }}"
                                                                       wire:model="selectedSerials.{{ $detailId }}"
                                                                       value="{{ $snId }}"
                                                                       class="custom-control-input">
                                                                <label class="custom-control-label" for="sn_{{ $detailId }}_{{ $snId }}">SN-{{ $snId }}</label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                    @error("selectedSerials.{$detailId}") <span class="text-danger small">{{ $message }}</span> @enderror
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="4" class="text-right">Total Retur:</th>
                                        <th class="text-right">
                                            @php
                                                $totalRetur = 0;
                                                foreach($snapshot['lines'] as $line) {
                                                    $totalRetur += ($quantities[$line['sale_detail_id']] ?? 0) * $line['unit_price'];
                                                }
                                            @endphp
                                            <strong>{{ format_currency($totalRetur) }}</strong>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        @if($error)
                            <div class="alert alert-danger mt-3">
                                {{ $error }}
                            </div>
                        @endif

                        <div class="mt-4 d-flex justify-content-end">
                            <button wire:click="submit" class="btn btn-success btn-lg" wire:loading.attr="disabled">
                                <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" role="status"></span>
                                Submit Retur
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
