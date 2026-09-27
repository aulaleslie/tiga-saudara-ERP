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
                    <livewire:modules.product.barcode-product-search
                        :selectedSettingId="$selectedSettingId"
                        wire:key="barcode-product-search"
                    />
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
                <table class="table table-bordered mb-0 align-middle">
                    <thead>
                    <tr>
                        <th data-testid="barcode-product-header">Produk</th>
                        <th data-testid="barcode-sku-header" style="width: 160px;">SKU</th>
                        <th data-testid="barcode-quantity-header" style="width: 130px;">Jumlah Label</th>
                        <th data-testid="barcode-remove-header" style="width: 60px;"></th>
                        <th data-testid="barcode-preview-header" style="width: 220px;">Pratinjau Label</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $index => $row)
                        @php
                            $preview = $productPreviews[$row['product_id']] ?? null;
                        @endphp
                        <tr wire:key="barcode-row-{{ $row['product_id'] }}" data-testid="barcode-row-{{ $row['product_id'] }}">
                            <td class="align-middle" data-testid="barcode-product-cell-{{ $row['product_id'] }}">
                                <strong>{{ $row['product_name'] }}</strong>
                            </td>
                            <td class="align-middle" data-testid="barcode-sku-cell-{{ $row['product_id'] }}">{{ $row['product_code'] }}</td>
                            <td class="align-middle" data-testid="barcode-quantity-cell-{{ $row['product_id'] }}">
                                <input type="number"
                                       min="1"
                                       max="{{ \Modules\Product\Services\BarcodeBatchService::MAX_PER_PRODUCT }}"
                                       class="form-control"
                                       wire:model.live.debounce.500ms="rows.{{ $index }}.quantity">
                            </td>
                            <td class="text-center align-middle" data-testid="barcode-remove-cell-{{ $row['product_id'] }}">
                                <button type="button"
                                        class="btn btn-sm btn-danger"
                                        wire:click="removeRow({{ $index }})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                            <td class="align-middle bg-light p-2" data-testid="barcode-preview-cell-{{ $row['product_id'] }}">
                                @if($preview && $preview['valid'])
                                    <div class="border rounded bg-white p-2 text-center shadow-sm" style="max-width: 200px; margin: 0 auto; font-size: 11px; line-height: 1.2;">
                                        <div class="font-weight-bold text-truncate mb-1" title="{{ $preview['product_name'] }}">{{ $preview['product_name'] }}</div>
                                        <div class="text-muted small text-break mb-1">{{ $preview['display_sku'] }}</div>
                                        <div class="barcode-svg-container my-1" style="max-width: 100%; overflow: hidden;">
                                            {!! $preview['svg'] !!}
                                        </div>
                                        <div class="font-monospace small mb-1">{{ $preview['barcode'] }}</div>
                                        <div class="font-weight-bold text-dark">{{ format_currency($preview['sale_price']) }}</div>
                                    </div>
                                @elseif($preview && !$preview['valid'])
                                    <div class="alert alert-warning py-1 px-2 mb-0 small text-left" role="alert">
                                        <i class="bi bi-exclamation-triangle-fill mr-1 text-danger"></i>
                                        <span>{{ $preview['error'] }}</span>
                                    </div>
                                @else
                                    <div class="text-muted small text-center italic">Memuat pratinjau...</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">Belum ada produk dipilih.</td>
                        </tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="2" class="text-right">Total Label</th>
                        <th colspan="3" data-testid="total-labels">{{ $totalLabels }}</th>
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
