<div class="d-flex"
     x-data="{
        open: @entangle('open').live,
        top: -9999,
        left: -9999,
        width: 0,
        handleScroll: null,
        init() {
            this.handleScroll = () => {
                if (this.open) {
                    this.updatePosition();
                }
            };
            window.addEventListener('scroll', this.handleScroll, true);
            window.addEventListener('resize', this.handleScroll);

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
        destroy() {
            window.removeEventListener('scroll', this.handleScroll, true);
            window.removeEventListener('resize', this.handleScroll);
        },
        updatePosition() {
            let rect = this.$refs.trigger.getBoundingClientRect();
            let padding = 8;
            let viewportWidth = window.innerWidth || document.documentElement.clientWidth;
            let maxWidth = Math.max(0, viewportWidth - (padding * 2));
            let width = Math.min(rect.width, maxWidth);
            let left = rect.left;

            if (left + width > viewportWidth - padding) {
                left = viewportWidth - padding - width;
            }
            if (left < padding) {
                left = padding;
            }

            this.top = rect.bottom;
            this.left = left;
            this.width = width;
        },
        toggleDropdown() {
            this.open = !this.open;
        }
     }">
    <div class="flex-grow-1 position-relative"
         @click.away="if (open) open = false">
        <button type="button"
                x-ref="trigger"
                class="form-control d-flex justify-content-between align-items-center text-start {{ $error ? 'is-invalid' : '' }}"
                @click="toggleDropdown">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div class="dropdown-menu shadow show p-2"
             x-cloak
             x-show="open"
             x-bind:style="`position: fixed; top: ${top}px; left: ${left}px; width: ${width}px; z-index: 1060; max-height: 300px; overflow-y: auto;`">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari produk..."
                    autocomplete="off"
                    @click.stop
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-truncate"
                            wire:click="select('{{ $option['id'] }}')"
                            @click="open = false"
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
        
        @if($error)
            <div class="invalid-feedback d-block">{{ $error }}</div>
        @endif
    </div>
</div>
