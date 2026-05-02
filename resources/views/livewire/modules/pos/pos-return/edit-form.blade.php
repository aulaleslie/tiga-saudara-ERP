<div>
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Edit Retur: {{ $return->code }} (Snapshot Transaksi: {{ $snapshot['header']['transaction_code'] }})</strong>
                    </div>
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
                            <h6 class="mb-3">Pembayaran Asli:</h6>
                            @foreach($snapshot['payments'] as $payment)
                                <div>{{ $payment['method_name'] }}: {{ format_currency($payment['amount']) }}</div>
                            @endforeach
                            <div class="mt-2">Total: <strong>{{ format_currency($snapshot['header']['grand_total']) }}</strong></div>
                        </div>
                        <div class="col-sm-4">
                            <h6 class="mb-3">Opsi Retur:</h6>
                            <div class="form-group">
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="option_cash" wire:model.live="returnOption" value="{{ \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN }}" class="custom-control-input">
                                    <label class="custom-control-label" for="option_cash">Kembali Uang (Cash Return)</label>
                                </div>
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="option_product" wire:model.live="returnOption" value="{{ \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT }}" class="custom-control-input">
                                    <label class="custom-control-label" for="option_product">Ganti Produk (Product Replacement)</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="thead-light">
                                <tr>
                                    <th>Produk</th>
                                    <th class="text-center">Tersedia</th>
                                    <th class="text-center" style="width: 150px;">Jumlah Retur</th>
                                    <th class="text-right">Harga Satuan</th>
                                    <th class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($snapshot['lines'] as $line)
                                    @php
                                        $detailId = $line['sale_detail_id'];
                                        // When editing, "available" quantity should include what's currently in THIS return
                                        $currentlyInReturn = $return->lines()->where('sale_detail_id', $detailId)->first();
                                        $availableQuantity = $line['returnable_quantity'] + ($currentlyInReturn ? $currentlyInReturn->quantity : 0);
                                        $isReturnable = $availableQuantity > 0;
                                    @endphp
                                    <tr>
                                        <td>
                                            <div>{{ $line['product_name'] }}</div>
                                            <small class="text-muted">{{ $line['product_code'] }}</small>
                                            @if($line['is_bundle'])
                                                <div class="mt-1">
                                                    <small class="badge badge-info">Bundle</small>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            {{ $availableQuantity }}
                                            @if($line['returned_quantity'] - ($currentlyInReturn ? $currentlyInReturn->quantity : 0) > 0)
                                                <div class="small text-danger">Retur Lain: {{ $line['returned_quantity'] - ($currentlyInReturn ? $currentlyInReturn->quantity : 0) }}</div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($isReturnable)
                                                <input type="number" 
                                                       wire:model.live="quantities.{{ $detailId }}" 
                                                       class="form-control form-control-sm text-center" 
                                                       min="0" 
                                                       max="{{ $availableQuantity }}"
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
                                    <th colspan="4" class="text-right">Total Retur Baru:</th>
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
                        <a href="{{ route('pos.returns.show', $return->id) }}" class="btn btn-secondary btn-lg mr-2">Batal</a>
                        <button wire:click="submit" class="btn btn-success btn-lg" wire:loading.attr="disabled">
                            <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" role="status"></span>
                            Simpan Perubahan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
