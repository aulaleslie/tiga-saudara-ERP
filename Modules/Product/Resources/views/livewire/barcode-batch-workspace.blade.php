<div>
    <div class="card">
        <div class="card-body">
            <div class="form-row">
                @if($canOverrideBusiness)
                    <div class="col-lg-6 mb-3">
                        <livewire:business-selector
                            :selectedSettingId="$selectedSettingId"
                            :isRequired="true"
                            selectId="barcode-business-selector"
                            wire:key="barcode-business-selector"
                        />
                    </div>
                @endif

                <div class="col-lg-6 mb-3">
                    <livewire:modules.product.barcode-product-search wire:key="barcode-product-search" />
                </div>
            </div>

            @if($batchErrors)
                <div class="alert alert-danger">
                    <ul class="mb-0 pl-3">
                        @foreach($batchErrors as $batchError)
                            <li>{{ $batchError }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                    <tr>
                        <th>Produk</th>
                        <th style="width: 160px;">SKU</th>
                        <th style="width: 140px;">Jumlah Label</th>
                        <th style="width: 60px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $index => $row)
                        <tr wire:key="barcode-row-{{ $row['product_id'] }}">
                            <td>{{ $row['product_name'] }}</td>
                            <td>{{ $row['product_code'] }}</td>
                            <td>
                                <input type="number"
                                       min="1"
                                       max="{{ \Modules\Product\Services\BarcodeBatchService::MAX_PER_PRODUCT }}"
                                       class="form-control"
                                       wire:model.live.debounce.500ms="rows.{{ $index }}.quantity">
                            </td>
                            <td class="text-center">
                                <button type="button"
                                        class="btn btn-sm btn-danger"
                                        wire:click="removeRow({{ $index }})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">Belum ada produk dipilih.</td>
                        </tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="2" class="text-right">Total Label</th>
                        <th colspan="2" data-testid="total-labels">{{ $totalLabels }}</th>
                    </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-3 d-flex align-items-center">
                <button type="button" class="btn btn-secondary mr-2" wire:click="preview" @disabled($rows === [])>
                    Pratinjau Batch
                </button>

                {{-- Validation runs in the component first; this form is only submitted
                     once the server confirms the batch is printable, so failures stay
                     visible here instead of disappearing into a new tab. --}}
                <form method="POST"
                      action="{{ route('barcode.batch-print') }}"
                      target="_blank"
                      class="mb-0"
                      id="barcode-batch-print-form">
                    @csrf
                    <input type="hidden" name="setting_id" value="{{ $selectedSettingId }}">
                    @foreach($rows as $index => $row)
                        <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $row['product_id'] }}">
                        <input type="hidden" name="items[{{ $index }}][quantity]" value="{{ $row['quantity'] }}">
                    @endforeach
                    <button type="button" class="btn btn-primary" wire:click="print" @disabled($rows === [])>
                        Cetak Batch
                    </button>
                </form>
            </div>
        </div>
    </div>

    @if($previewed)
        <div class="card mt-3">
            <div class="card-header">Pratinjau ({{ count($previewLabels) }} label)</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th>Produk</th>
                            <th>SKU</th>
                            <th>Barcode</th>
                            <th class="text-right">Harga</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($previewLabels as $labelIndex => $label)
                            <tr wire:key="preview-{{ $labelIndex }}">
                                <td>{{ $labelIndex + 1 }}</td>
                                <td>{{ $label['product_name'] }}</td>
                                <td>{{ $label['product_code'] }}</td>
                                <td>{{ $label['barcode'] }}</td>
                                <td class="text-right">{{ format_currency($label['sale_price']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @include('product::barcode.partials.printer-guidance')

    <script>
        document.addEventListener('livewire:initialized', () => {
            @this.on('barcode-batch-ready', () => {
                document.getElementById('barcode-batch-print-form')?.submit();
            });
        });
    </script>
</div>
