<div class="position-relative" v-on-clickaway="closeDropdown">
    <div class="input-group input-group-sm">
        <button type="button" 
                class="form-select form-select-sm text-start {{ $error ? 'is-invalid' : '' }}" 
                wire:click="toggleDropdown">
            @if($selectedLabel)
                {{ $selectedLabel }}
            @else
                <span class="text-muted">Pilih lokasi...</span>
            @endif
        </button>
    </div>

    @if($open)
        <div class="position-absolute w-100 bg-white border rounded shadow-sm mt-1" style="z-index: 1000; max-height: 200px; overflow-y: auto;">
            <div class="p-2">
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
                            <div class="list-group-item disabled py-1 px-2 small">Tidak ada lokasi dengan stok tersedia.</div>
                        @endforelse
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
