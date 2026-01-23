<div class="d-flex">
    <div class="flex-grow-1 position-relative"
         x-data="{ open: @entangle('open').live }"
         @click.away="if (open) open = false">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start {{ $error ? 'is-invalid' : '' }}"
                @click="open = !open">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div class="dropdown-menu w-100 shadow show p-2"
             x-cloak
             x-show="open"
             style="position: absolute; z-index: 1050; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
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
