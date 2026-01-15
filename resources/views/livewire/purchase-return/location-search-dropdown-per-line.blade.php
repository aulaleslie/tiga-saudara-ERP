<div class="d-flex" 
     x-data="{
        open: @entangle('open'),
        top: -9999,
        left: -9999,
        width: 0,
        init() {
            if (this.open) {
                this.$nextTick(() => this.updatePosition());
            }
            this.$watch('open', value => {
                if (value) {
                    this.$nextTick(() => {
                        this.updatePosition();
                    });
                }
            });
        },
        updatePosition() {
            let rect = this.$refs.trigger.getBoundingClientRect();
            this.top = rect.bottom;
            this.left = rect.left;
            this.width = rect.width;
        },
        toggleDropdown() {
             this.$wire.toggleDropdown();
        }
     }"
     @scroll.window="open = false" 
     @resize.window="open = false"
     >
    <div class="flex-grow-1 position-relative" wire:click.away="closeDropdown">
        <button type="button"
                x-ref="trigger"
                class="form-control d-flex justify-content-between align-items-center text-start {{ $error ? 'is-invalid' : '' }}"
                @click="toggleDropdown">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? 'Pilih lokasi...' }}">
                {{ $selectedLabel ?? 'Pilih lokasi...' }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }} flex-shrink-0"></i>
        </button>

        @if($open)
            <div class="dropdown-menu shadow show p-2"
                 x-init="updatePosition()"
                 x-bind:style="`position: fixed; top: ${top}px; left: ${left}px; width: ${width}px; z-index: 1060; max-height: 200px; overflow-y: auto;`">
                <input type="text" 
                       class="form-control form-control-sm mb-2" 
                       placeholder="Cari lokasi..." 
                       wire:model.live.debounce.300ms="search"
                       autofocus>
                
                <div class="list-group list-group-flush">
                    @if(!$product_id)
                        <div class="list-group-item disabled py-1 px-2 small">Pilih produk terlebih dahulu.</div>
                    @else
                        @forelse($locations as $location)
                            <button type="button" 
                                    class="list-group-item list-group-item-action py-1 px-2 small {{ $selected == $location['id'] ? 'active' : '' }}"
                                    wire:click="select({{ $location['id'] }})">
                                {{ $location['label'] }}
                            </button>
                        @empty
                            <div class="list-group-item disabled py-1 px-2 small text-center">Tidak ada lokasi dengan stok tersedia.</div>
                        @endforelse
                    @endif
                </div>
            </div>
        @endif
        
        @if($error)
            <div class="invalid-feedback d-block">{{ $error }}</div>
        @endif
    </div>
</div>
