<div class="d-flex" 
     x-data="{
        open: @entangle('open'),
        toggleDropdown() {
             this.$wire.toggleDropdown();
        }
     }"
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
                 style="position: absolute; top: 100%; left: 0; right: 0; z-index: 1060; max-height: 300px; overflow-y: auto;">

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
