<div class="d-flex">
    <div class="flex-grow-1 position-relative"
         x-data="{
            open: @entangle('open').live,
            position: { top: 0, left: 0, width: 0 },
            updatePosition() {
                if (!this.$refs.trigger) return;
                const rect = this.$refs.trigger.getBoundingClientRect();
                this.position = {
                    top: rect.bottom,
                    left: rect.left,
                    width: rect.width
                };
            }
         }"
         x-init="
            const handler = () => { if (open) updatePosition() };
            $watch('open', value => {
                if (value) {
                    updatePosition();
                    $nextTick(() => updatePosition());
                }
            });
            window.addEventListener('scroll', handler, true);
            window.addEventListener('resize', handler);
            return () => {
                window.removeEventListener('scroll', handler, true);
                window.removeEventListener('resize', handler);
            };
         "
         @click.away="if (open) open = false">
        <button type="button"
                x-ref="trigger"
                class="form-control d-flex justify-content-between align-items-center text-start {{ $error ? 'is-invalid' : '' }}"
                @click="open = !open">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? 'Pilih lokasi...' }}">
                {{ $selectedLabel ?? 'Pilih lokasi...' }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <template x-teleport="body">
            <div class="dropdown-menu shadow show p-2"
                 x-cloak
                 x-show="open"
                 :style="`position: fixed; z-index: 1060; max-height: 200px; overflow-y: auto; top: ${position.top}px; left: ${position.left}px; width: ${position.width}px; margin: 0; display: block;`"
                 @click.away="open = false"
                 @scroll.stop>
                <input type="text" 
                       class="form-control form-control-sm mb-2" 
                       placeholder="Cari lokasi..." 
                       wire:model.live.debounce.300ms="search"
                       autofocus
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
        </template>
        
        @if($error)
            <div class="invalid-feedback d-block">{{ $error }}</div>
        @endif
    </div>
</div>
