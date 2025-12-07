<div class="form-group">
    <label for="{{ $name }}">{{ $label }} @if($required)<span class="text-danger">*</span>@endif</label>
    <div class="input-group">
        <input
            type="text"
            class="form-control @error($name) is-invalid @enderror"
            wire:model.live.debounce.300ms="query"
            wire:focus="isFocused = true"
            wire:blur="isFocused = false"
            placeholder="{{ $placeholder }}"
            @if($required) required @endif
            @if($disabled) disabled @endif
            autocomplete="off"
        >

        @if($selectedValue)
            <input type="hidden" name="{{ $name }}" value="{{ $selectedValue }}">
            <button type="button" class="btn btn-outline-secondary" wire:click="clearSelection" title="Clear selection">
                <i class="bi bi-x"></i>
            </button>
        @endif

        @if($quickAddEntity && $quickAddPermission && $quickAddModalEvent)
            <x-quick-add-button
                :entity="$quickAddEntity"
                :permission="$quickAddPermission"
                :modal-event="$quickAddModalEvent"
                :tooltip="$quickAddTooltip"
            />
        @endif
    </div>

    @if($isFocused && !empty($searchResults))
        <div class="position-absolute bg-white border rounded shadow-sm mt-1" style="z-index: 1050; width: 100%; max-height: 200px; overflow-y: auto;">
            @if($isLoading)
                <div class="p-2 text-center">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <small class="text-muted ms-2">Mencari...</small>
                </div>
            @else
                @foreach($searchResults as $result)
                    <div
                        class="p-2 border-bottom cursor-pointer hover-bg-light"
                        wire:click="selectItem({{ $result['id'] }}, '{{ addslashes($result['label']) }}')"
                        style="cursor: pointer;"
                        onmouseover="this.style.backgroundColor='#f8f9fa'"
                        onmouseout="this.style.backgroundColor='transparent'"
                    >
                        {{ $result['label'] }}
                    </div>
                @endforeach
            @endif
        </div>
    @endif

    @error($name)
        <span class="invalid-feedback d-block" role="alert"><strong>{{ $message }}</strong></span>
    @enderror
</div>

<style>
.cursor-pointer {
    cursor: pointer;
}
.hover-bg-light:hover {
    background-color: #f8f9fa !important;
}
</style>