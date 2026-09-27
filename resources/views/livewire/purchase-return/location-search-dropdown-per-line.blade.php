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
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? 'Pilih lokasi...' }}">
                {{ $selectedLabel ?? 'Pilih lokasi...' }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div class="dropdown-menu shadow show p-2"
             x-cloak
             x-show="open"
             x-bind:style="`position: fixed; top: ${top}px; left: ${left}px; width: ${width}px; z-index: 1060; max-height: 200px; overflow-y: auto;`">
                <input type="text" 
                       class="form-control form-control-sm mb-2" 
                       placeholder="Cari lokasi..." 
                       wire:model.live.debounce.300ms="search"
                       autocomplete="off"
                       @click.stop>
                
                <div class="list-group list-group-flush">
                    @if(!$product_id)
                        <div class="list-group-item disabled py-1 px-2 small">Pilih produk terlebih dahulu.</div>
                    @else
                        @forelse($locations as $location)
                            <button type="button" 
                                    class="list-group-item list-group-item-action py-1 px-2 small {{ $selected == $location['id'] ? 'active' : '' }}"
                                    wire:click="select({{ $location['id'] }})"
                                    @click="open = false">
                                {{ $location['label'] }}
                            </button>
                        @empty
                            <div class="list-group-item disabled py-1 px-2 small text-center">Tidak ada lokasi dengan stok tersedia.</div>
                        @endforelse
                    @endif
                </div>
        </div>
        
        @if($error)
            <div class="invalid-feedback d-block">{{ $error }}</div>
        @endif
    </div>
</div>
