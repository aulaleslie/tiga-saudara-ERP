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
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }} flex-shrink-0"></i>
        </button>

        @if($open)
            <div class="dropdown-menu shadow show p-2"
                 x-init="updatePosition()"
                 x-bind:style="`position: fixed; top: ${top}px; left: ${left}px; width: ${width}px; z-index: 1060; max-height: 300px; overflow-y: auto;`">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari produk..."
                    autocomplete="off"
                    autofocus
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-truncate"
                            wire:click="select('{{ $option['id'] }}')"
                            wire:key="product-option-{{ $option['id'] }}"
                            title="{{ $option['name'] }}"
                        >
                            {{ $option['name'] }}
                        </button>
                    @endforeach
                    
                    @if($query_count > count($options))
                        <div class="text-center mt-2">
                             <button type="button" class="btn btn-sm btn-link text-decoration-none" wire:click.prevent="loadMore">
                                 Muat lebih banyak...
                             </button>
                        </div>
                    @endif
                @else
                    <div class="dropdown-item disabled text-muted text-center">
                        {{ $supplier_id ? 'Produk tidak ditemukan' : 'Pilih pemasok terlebih dahulu' }}
                    </div>
                @endif
            </div>
        @endif
        
        @if($error)
            <div class="invalid-feedback d-block">{{ $error }}</div>
        @endif
    </div>
</div>
