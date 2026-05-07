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
                            <div class="col-sm-4">
                                <h6 class="mb-3">Informasi Tambahan:</h6>
                                <div class="alert alert-info py-2 mb-0">
                                    <small><i class="fas fa-info-circle mr-1"></i> Pilih resolusi untuk setiap item: Tidak Ada Aksi, Uang Kembali, atau Ganti Produk.</small>
                                </div>
                            </div>
                        </div>

                        @error('lineSelections')
                            <div class="alert alert-danger">{{ $message }}</div>
                        @enderror

                        {{-- Render grouped source lines --}}
                        @foreach($this->groupedLines as $groupIndex => $group)
                            <div class="card mb-3 border" wire:key="group-{{ $group['sale_detail_id'] }}">
                                <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>{{ $group['product_name'] }}</strong>
                                        <small class="text-muted ml-2">{{ $group['product_code'] }}</small>
                                        @if($group['is_bundle'])
                                            <span class="badge badge-info ml-2">Bundle</span>
                                        @endif
                                        @if($group['is_tracked'])
                                            <span class="badge badge-primary ml-2">Tracked Serial</span>
                                        @endif
                                    </div>
                                    <div class="text-muted small">
                                        Harga Satuan: <strong>{{ format_currency($group['unit_price']) }}</strong>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    @if(!empty($group['serial_lines']))
                                        {{-- Serialized product: one row per serial (tasks 6.2, 6.3, 6.4) --}}
                                        <table class="table table-sm mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th style="width: 200px;">Serial Number</th>
                                                    <th class="text-center" style="width: 100px;">Status</th>
                                                    <th class="text-center">Resolusi</th>
                                                    <th class="text-center" style="width: 250px;">Detail</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($group['serial_lines'] as $serialLine)
                                                    @php
                                                        $lineKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($serialLine);
                                                        $selection = $lineSelections[$lineKey] ?? ['resolution' => 'none'];
                                                        $serialNumber = $serialLine['serial_numbers'][0]['serial_number'] ?? '?';
                                                        $isReturnable = $serialLine['returnable_quantity'] > 0;
                                                        $resolution = $selection['resolution'] ?? 'none';
                                                    @endphp
                                                    <tr wire:key="serial-row-{{ $lineKey }}">
                                                        <td>
                                                            <code class="font-weight-bold">{{ $serialNumber }}</code>
                                                        </td>
                                                        <td class="text-center">
                                                            @if($isReturnable)
                                                                <span class="badge badge-success">Tersedia</span>
                                                            @else
                                                                <span class="badge badge-secondary">Sudah Diretur</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            @if($isReturnable)
                                                                {{-- Resolution selector (task 6.4) --}}
                                                                <div class="btn-group btn-group-sm" role="group">
                                                                    <button type="button"
                                                                            wire:click="updateResolution('{{ $lineKey }}', 'none')"
                                                                            class="btn {{ $resolution === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                                                                        <i class="fas fa-ban"></i> Tidak
                                                                    </button>
                                                                    <button type="button"
                                                                            wire:click="updateResolution('{{ $lineKey }}', 'cash_return')"
                                                                            class="btn {{ $resolution === 'cash_return' ? 'btn-success' : 'btn-outline-success' }}">
                                                                        <i class="fas fa-money-bill-wave"></i> Tunai
                                                                    </button>
                                                                    <button type="button"
                                                                            wire:click="updateResolution('{{ $lineKey }}', 'product_replacement')"
                                                                            class="btn {{ $resolution === 'product_replacement' ? 'btn-info' : 'btn-outline-info' }}">
                                                                        <i class="fas fa-exchange-alt"></i> Ganti
                                                                    </button>
                                                                </div>
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            {{-- Show expected cash amount for cash_return (task 6.6) --}}
                                                            @if($resolution === 'cash_return')
                                                                <div class="text-success font-weight-bold">
                                                                    <i class="fas fa-money-bill-wave mr-1"></i>
                                                                    {{ format_currency($serialLine['unit_price']) }}
                                                                </div>
                                                            @endif

                                                            {{-- Show replacement serial scanner for product_replacement (task 6.5) --}}
                                                            @if($resolution === 'product_replacement')
                                                                @if(!empty($selection['replacement_serial_id']))
                                                                    <div class="d-flex align-items-center justify-content-center">
                                                                        <span class="badge badge-info p-2 mr-1">
                                                                            <i class="fas fa-exchange-alt mr-1"></i>
                                                                            {{ $selection['replacement_serial_label'] ?? '?' }}
                                                                        </span>
                                                                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="clearReplacementSerial('{{ $lineKey }}')" title="Hapus serial pengganti">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </div>
                                                                @else
                                                                    <div class="input-group input-group-sm">
                                                                        <input type="text"
                                                                               wire:model.defer="lineSelections.{{ $lineKey }}.replacement_serial_input"
                                                                               wire:keydown.enter.prevent="scanReplacementSerial('{{ $lineKey }}')"
                                                                               class="form-control text-center @error('lineSelections.'.$lineKey.'.replacement_serial_input') is-invalid @enderror"
                                                                               placeholder="Scan SN pengganti...">
                                                                        <div class="input-group-append">
                                                                            <button type="button" class="btn btn-info" wire:click="scanReplacementSerial('{{ $lineKey }}')">
                                                                                <i class="fas fa-barcode"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                    @error("lineSelections.{$lineKey}.replacement_serial_input")
                                                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                                                    @enderror
                                                                @endif
                                                            @endif

                                                            @if($resolution === 'none')
                                                                <span class="text-muted small">Tidak ada aksi</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>

                                        {{-- Bundled component trace rows: only for actionable serialized bundled parents (task 6.7) --}}
                                        @if($group['is_bundle'] && !empty($group['bundle_items']))
                                            @php
                                                $hasActionableSerial = false;
                                                foreach ($group['serial_lines'] as $sl) {
                                                    $slKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($sl);
                                                    $slResolution = $lineSelections[$slKey]['resolution'] ?? 'none';
                                                    if ($slResolution !== 'none') {
                                                        $hasActionableSerial = true;
                                                        break;
                                                    }
                                                }
                                            @endphp
                                            @if($hasActionableSerial)
                                                <div class="border-top px-3 py-2 bg-white">
                                                    <small class="text-muted font-weight-bold"><i class="fas fa-sitemap mr-1"></i> Komponen Bundle (trace):</small>
                                                    <table class="table table-sm table-borderless mb-0 mt-1">
                                                        @foreach($group['bundle_items'] as $bi)
                                                            <tr>
                                                                <td class="py-1 pl-4">
                                                                    <small>{{ $bi['product_name'] ?? $bi['product_code'] ?? '?' }}</small>
                                                                </td>
                                                                <td class="py-1 text-right">
                                                                    <small class="text-muted">{{ $bi['quantity'] ?? $bi['quantity_per_bundle'] ?? '-' }} per unit</small>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </table>
                                                </div>
                                            @endif
                                        @endif
                                    @elseif($group['non_serial_line'])
                                        {{-- Non-serial product line --}}
                                        @php
                                            $nsLine = $group['non_serial_line'];
                                            $nsKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($nsLine);
                                            $nsSelection = $lineSelections[$nsKey] ?? ['resolution' => 'none', 'quantity' => 0];
                                            $nsResolution = $nsSelection['resolution'] ?? 'none';
                                            $nsQuantity = (float) ($nsSelection['quantity'] ?? 0);
                                            $nsIsReturnable = $nsLine['returnable_quantity'] > 0;
                                        @endphp
                                        <div class="p-3">
                                            <div class="row align-items-center">
                                                <div class="col-sm-3">
                                                    <div>
                                                        Tersedia: <strong>{{ $nsLine['returnable_quantity'] }}</strong>
                                                        @if($nsLine['returned_quantity'] > 0)
                                                            <span class="small text-danger ml-2">Ter-retur: {{ $nsLine['returned_quantity'] }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="col-sm-3 text-center">
                                                    @if($nsIsReturnable)
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button"
                                                                    wire:click="updateResolution('{{ $nsKey }}', 'none')"
                                                                    class="btn {{ $nsResolution === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                                                                Tidak
                                                            </button>
                                                            <button type="button"
                                                                    wire:click="updateResolution('{{ $nsKey }}', 'cash_return')"
                                                                    class="btn {{ $nsResolution === 'cash_return' ? 'btn-success' : 'btn-outline-success' }}">
                                                                Tunai
                                                            </button>
                                                        </div>
                                                    @else
                                                        <span class="badge badge-secondary">Habis</span>
                                                    @endif
                                                </div>
                                                <div class="col-sm-3 text-center">
                                                    @if($nsIsReturnable && $nsResolution !== 'none')
                                                        <input type="number"
                                                               wire:model.live="lineSelections.{{ $nsKey }}.quantity"
                                                               class="form-control form-control-sm text-center font-weight-bold"
                                                               min="0"
                                                               max="{{ $nsLine['returnable_quantity'] }}"
                                                               step="1"
                                                               placeholder="Jumlah">
                                                    @endif
                                                </div>
                                                <div class="col-sm-3 text-right">
                                                    @if($nsResolution === 'cash_return' && $nsQuantity > 0)
                                                        <div class="text-success font-weight-bold">
                                                            <i class="fas fa-money-bill-wave mr-1"></i>
                                                            {{ format_currency($nsQuantity * $nsLine['unit_price']) }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Non-serial bundle component trace (task 6.7) --}}
                                            @if($group['is_bundle'] && !empty($group['bundle_items']) && $nsResolution !== 'none' && $nsQuantity > 0)
                                                <div class="mt-2 pl-3 border-left border-info">
                                                    <small class="text-muted font-weight-bold"><i class="fas fa-sitemap mr-1"></i> Komponen Bundle:</small>
                                                    @foreach($group['bundle_items'] as $bi)
                                                        <div class="small text-muted pl-2">
                                                            {{ $bi['product_name'] ?? $bi['product_code'] ?? '?' }}
                                                            — {{ ($bi['quantity'] ?? $bi['quantity_per_bundle'] ?? 0) * $nsQuantity }} unit
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        {{-- Summary --}}
                        @php
                            $totalCashReturn = 0;
                            $totalActionable = 0;
                            foreach ($snapshot['lines'] as $line) {
                                $key = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($line);
                                $sel = $lineSelections[$key] ?? ['resolution' => 'none'];
                                $res = $sel['resolution'] ?? 'none';
                                if ($res === 'cash_return') {
                                    if ($line['is_tracked']) {
                                        $totalCashReturn += $line['unit_price'];
                                        $totalActionable++;
                                    } else {
                                        $qty = (float) ($sel['quantity'] ?? 0);
                                        $totalCashReturn += $qty * $line['unit_price'];
                                        if ($qty > 0) $totalActionable++;
                                    }
                                } elseif ($res === 'product_replacement') {
                                    $totalActionable++;
                                }
                            }
                        @endphp
                        <div class="card bg-light">
                            <div class="card-body py-3">
                                <div class="row">
                                    <div class="col-sm-6">
                                        <div class="text-muted">Item aktif: <strong>{{ $totalActionable }}</strong></div>
                                    </div>
                                    <div class="col-sm-6 text-right">
                                        <div>Total Retur Tunai: <strong class="text-success">{{ format_currency($totalCashReturn) }}</strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if($error)
                            <div class="alert alert-danger mt-3">
                                {{ $error }}
                            </div>
                        @endif

                        <div class="mt-4 d-flex justify-content-end gap-2">
                            <button wire:click="submit" class="btn btn-success btn-lg" wire:loading.attr="disabled">
                                <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" role="status"></span>
                                Simpan Draft Retur POS
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
