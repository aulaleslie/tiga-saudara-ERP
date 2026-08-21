{{--
    Corrections/2: Actionable bundle component controls, shared by both the
    serial-lines table's bundle block and the non-serial row's bundle block.
    Each entry in $components is a bundle_items[] snapshot entry (see
    PosReturnSnapshotService::buildComponentBundleItemEntry) shaped just like
    a top-level snapshot line: sale_detail_id, is_tracked, serial_number_ids,
    serial_numbers[], returnable_quantity.

    Components only ever offer 'none' / 'product_replacement' — never
    'cash_return' — because whole-bundle cash refund is synthesized
    automatically server-side from the parent line alone.
--}}
<div class="border-top p-3 bg-light">
    <div class="text-muted small font-weight-bold mb-2"><i class="fas fa-sitemap mr-1"></i> Komponen Bundle:</div>

    @foreach($components as $component)
        @php
            $componentKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($component);
            $componentSelection = $lineSelections[$componentKey] ?? ['resolution' => 'none'];
            $componentResolution = $componentSelection['resolution'] ?? 'none';
            $componentIsSerial = ($component['is_tracked'] ?? false) && !empty($component['serial_number_ids']);
        @endphp

        <div class="border rounded p-2 mb-2 bg-white" wire:key="component-{{ $componentKey }}">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                    <strong>{{ $component['product_name'] ?? '?' }}</strong>
                    <small class="text-muted ml-1">{{ $component['product_code'] ?? '' }}</small>
                    <span class="small text-muted ml-1">({{ $component['quantity'] ?? 0 }} unit/bundle)</span>
                </div>
            </div>

            @if($componentIsSerial)
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
                        @foreach(\Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::explodeComponentLines($component) as $serialLineShape)
                            @php
                                // Corrections/2: each originally sold component serial gets its
                                // own actionable row — explodeComponentLines() produces one
                                // line-shaped array per serial, keyed identically to how the
                                // form's PHP-side helpers derive component-serial keys.
                                $componentSerial = $serialLineShape['serial_numbers'][0] ?? ['id' => null, 'serial_number' => '?'];
                                $serialKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($serialLineShape);
                                $serialSelection = $lineSelections[$serialKey] ?? ['resolution' => 'none'];
                                $serialResolution = $serialSelection['resolution'] ?? 'none';
                                $componentSerialReturnable = ($componentSerial['returnable_quantity'] ?? 1) > 0;
                            @endphp
                            <tr wire:key="component-serial-row-{{ $serialKey }}">
                                <td><code class="font-weight-bold">{{ $componentSerial['serial_number'] ?? '?' }}</code></td>
                                <td class="text-center">
                                    @if($componentSerialReturnable)
                                        <span class="badge badge-success">Tersedia</span>
                                    @else
                                        <span class="badge badge-secondary">Sudah Diretur</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($componentSerialReturnable)
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button"
                                                    wire:click="updateResolution('{{ $serialKey }}', 'none')"
                                                    class="btn {{ $serialResolution === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                                                <i class="fas fa-ban"></i> Tidak
                                            </button>
                                            <button type="button"
                                                    wire:click="updateResolution('{{ $serialKey }}', 'product_replacement')"
                                                    class="btn {{ $serialResolution === 'product_replacement' ? 'btn-info' : 'btn-outline-info' }}">
                                                <i class="fas fa-exchange-alt"></i> Ganti
                                            </button>
                                        </div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($serialResolution === 'product_replacement')
                                        @if(!empty($serialSelection['replacement_serial_id']))
                                            <div class="d-flex align-items-center justify-content-center">
                                                <span class="badge badge-info p-2 mr-1">
                                                    <i class="fas fa-exchange-alt mr-1"></i>
                                                    {{ $serialSelection['replacement_serial_label'] ?? '?' }}
                                                </span>
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="clearReplacementSerial('{{ $serialKey }}')" title="Hapus serial pengganti">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        @else
                                            <div class="input-group input-group-sm">
                                                <input type="text"
                                                       wire:model.defer="lineSelections.{{ $serialKey }}.replacement_serial_input"
                                                       wire:keydown.enter.prevent="scanReplacementSerial('{{ $serialKey }}')"
                                                       class="form-control text-center @error('lineSelections.'.$serialKey.'.replacement_serial_input') is-invalid @enderror"
                                                       placeholder="Scan SN pengganti...">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-info" wire:click="scanReplacementSerial('{{ $serialKey }}')">
                                                        <i class="fas fa-barcode"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            @error("lineSelections.{$serialKey}.replacement_serial_input")
                                                <div class="text-danger small mt-1">{{ $message }}</div>
                                            @enderror
                                        @endif
                                        <input type="text"
                                               wire:model.defer="lineSelections.{{ $serialKey }}.replacement_reason"
                                               class="form-control form-control-sm mt-1"
                                               placeholder="Alasan penggantian (opsional)">
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                @php
                    $componentAvailable = (float) ($component['returnable_quantity'] ?? 0);
                    $componentIsReturnable = $componentAvailable > 0;
                    $componentQty = (float) ($componentSelection['quantity'] ?? 0);
                @endphp
                <div class="row align-items-center">
                    <div class="col-sm-3">
                        Tersedia: <strong>{{ $componentAvailable }}</strong>
                    </div>
                    <div class="col-sm-3 text-center">
                        @if($componentIsReturnable)
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button"
                                        wire:click="updateResolution('{{ $componentKey }}', 'none')"
                                        class="btn {{ $componentResolution === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                                    Tidak
                                </button>
                                <button type="button"
                                        wire:click="updateResolution('{{ $componentKey }}', 'product_replacement')"
                                        class="btn {{ $componentResolution === 'product_replacement' ? 'btn-info' : 'btn-outline-info' }}">
                                    Ganti
                                </button>
                            </div>
                        @else
                            <span class="badge badge-secondary">Habis</span>
                        @endif
                    </div>
                    <div class="col-sm-3 text-center">
                        @if($componentIsReturnable && $componentResolution !== 'none')
                            <input type="number"
                                   wire:model.live="lineSelections.{{ $componentKey }}.quantity"
                                   class="form-control form-control-sm text-center font-weight-bold"
                                   min="0"
                                   max="{{ $componentAvailable }}"
                                   step="1"
                                   placeholder="Jumlah">
                        @endif
                    </div>
                    <div class="col-sm-3">
                        @if($componentResolution === 'product_replacement')
                            <input type="text"
                                   wire:model.defer="lineSelections.{{ $componentKey }}.replacement_reason"
                                   class="form-control form-control-sm @error('lineSelections.'.$componentKey.'.replacement_reason') is-invalid @enderror"
                                   placeholder="Alasan penggantian">
                            @error("lineSelections.{$componentKey}.replacement_reason")
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
