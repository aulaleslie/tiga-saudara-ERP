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
                    <div class="card-header d-flex justify-content-between">
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
                            <div class="col-sm-4">
                                <h6 class="mb-3">Status Snapshot:</h6>
                                <div>Hash: <code class="small">{{ $snapshot['hash'] }}</code></div>
                                <div class="text-muted small">Snapshot ini bersifat immutable untuk memastikan integritas data saat retur.</div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center">Dipesan</th>
                                        <th class="text-center">Telah Diretur</th>
                                        <th class="text-center">Tersedia</th>
                                        <th class="text-right">Harga Satuan</th>
                                        <th class="text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($snapshot['lines'] as $line)
                                        <tr>
                                            <td>
                                                <div>{{ $line['product_name'] }}</div>
                                                <small class="text-muted">{{ $line['product_code'] }}</small>
                                                @if($line['is_bundle'])
                                                    <div class="mt-1">
                                                        <small class="badge badge-info">Bundle</small>
                                                        <ul class="list-unstyled mb-0 small ml-2">
                                                            @foreach($line['bundle_items'] as $bi)
                                                                <li>- {{ $bi['product_name'] }} ({{ $bi['quantity_per_bundle'] }} unit)</li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                                @if(!empty($line['serial_number_ids']))
                                                    <div class="mt-1">
                                                        <small class="text-primary">SN: {{ count($line['serial_number_ids']) }} item</small>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-center">{{ $line['original_quantity'] }}</td>
                                            <td class="text-center text-danger">{{ $line['returned_quantity'] }}</td>
                                            <td class="text-center font-weight-bold">{{ $line['returnable_quantity'] }}</td>
                                            <td class="text-right">{{ format_currency($line['unit_price']) }}</td>
                                            <td class="text-right">{{ format_currency($line['line_total']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i> US1 Selesai: Snapshot berhasil dimuat secara immutable. Fitur pemilihan retur akan diimplementasikan pada User Story 2.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
