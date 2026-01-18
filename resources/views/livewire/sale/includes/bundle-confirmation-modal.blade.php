<div wire:ignore.self class="modal fade" id="bundleSelectionModal" tabindex="-1" aria-labelledby="bundleSelectionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bundleSelectionModalLabel">Pilih Bundle</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                @if(!empty($bundleOptions) && $bundleOptions->isNotEmpty())
                    <div class="mb-3">
                        <label for="bundleDropdown" class="form-label">Pilih bundle:</label>
                        <select id="bundleDropdown" class="form-control">
                            @foreach($bundleOptions as $bundle)
                                @php
                                    // Assuming each bundle has a relation "bundleItems" with a "product" relation:
                                    $itemNames = $bundle->items->pluck('product.product_name')->implode(', ');
                                @endphp
                                <option value="{{ $bundle->id }}">
                                    {{ $bundle->name }} - ({{ $itemNames }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <p>Tidak ada bundle tersedia.</p>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <!-- New button to process without bundle -->
                <button type="button" class="btn btn-warning" onclick="proceedWithoutBundle()">Proses Tanpa Bundle</button>
                <button type="button" class="btn btn-primary" onclick="confirmBundleSelection()">Konfirmasi</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.addEventListener('showBundleSelectionModal', event => {
        $('#bundleSelectionModal').modal('show');
    });

    function confirmBundleSelection() {
        var dropdown = document.getElementById('bundleDropdown');
        var selected = dropdown.value;
        if (selected) {
            @this.call('confirmBundleSelection', selected)
            $('#bundleSelectionModal').modal('hide');
        } else {
            alert('Silakan pilih bundle.');
        }
    }

    function proceedWithoutBundle() {
        @this.call('proceedWithoutBundle')
        $('#bundleSelectionModal').modal('hide');
    }
</script>
