<div class="d-flex">
    <div class="flex-grow-1 position-relative"
         x-data="{ open: @entangle('open').live }"
         @click.away="if (open) open = false">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start {{ $error ? 'is-invalid' : '' }}"
                @click="open = !open">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? 'Pilih lokasi...' }}">
                {{ $selectedLabel ?? 'Pilih lokasi...' }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div class="dropdown-menu w-100 shadow show p-2"
             x-cloak
             x-show="open"
             style="position: absolute; z-index: 1050; max-height: 200px; overflow-y: auto; top: 100%; left: 0; right: 0;">
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
