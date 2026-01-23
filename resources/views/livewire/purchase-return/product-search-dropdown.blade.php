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
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <template x-teleport="body">
            <div class="dropdown-menu shadow show p-2"
                 x-cloak
                 x-show="open"
                 :style="`position: fixed; z-index: 1060; max-height: 300px; overflow-y: auto; top: ${position.top}px; left: ${position.left}px; width: ${position.width}px; margin: 0; display: block;`"
                 @click.away="open = false"
                 @scroll.stop>
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari produk..."
                    autocomplete="off"
                    autofocus
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
        </template>
        
        @if($error)
            <div class="invalid-feedback d-block">{{ $error }}</div>
        @endif
    </div>
</div>
