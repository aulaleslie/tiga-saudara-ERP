<div>
    {{-- Bootstrap modal --}}
    <div class="modal fade @if($show) show d-block @endif"
         tabindex="-1" role="dialog"
         aria-hidden="{{ $show ? 'false' : 'true' }}"
         @if($show) style="background: rgba(0,0,0,.4);" @endif>
        <div class="modal-dialog modal-md" role="document" wire:ignore.self>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Scan / Ketik Serial Number
{{--                        <small class="text-muted">#Produk {{ $productId }}</small>--}}
                    </h5>
                    <button type="button" class="close" aria-label="Close" wire:click="close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    {{-- Single input (auto-focus) --}}
                    <div class="form-group">
                        <label for="serial-scan-input" class="sr-only">Serial</label>
                        <input
                            id="serial-scan-input"
                            type="text"
                            class="form-control form-control-lg"
                            placeholder="Arahkan scanner lalu tekan Enter…"
                            wire:model.defer="scan"
                            wire:keydown.enter.prevent="scanSerial"
                            autocomplete="off"
                            autofocus
                        >
                        <small class="form-text text-muted">
                            Setiap scan yang valid langsung ditambahkan ke keranjang.
                        </small>
                    </div>

                    {{-- Optional feedback: recently scanned --}}
                    @if (!empty($recent))
                        <div class="mt-3">
                            <div class="small text-muted mb-1">Baru dipindai:</div>
                            <div class="d-flex flex-wrap">
                                @foreach ($recent as $sn)
                                    <span class="badge badge-primary mr-1 mb-1">{{ $sn }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" wire:click="close">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Focus helper --}}
    <script>
        document.addEventListener('livewire:init', () => {
            this.on('focusSerialScanInput', () => {
                const el = document.getElementById('serial-scan-input');
                if (el) { el.focus(); el.select(); }
            })
        });
    </script>
</div>
