<div class="position-relative"
     x-data
     x-on:clear-input.window="if ($event.detail[0].index == {{ $index }}) { $refs.serialInput.value = ''; $refs.serialInput.focus(); }"
     x-on:error-occurred.window="if ($event.detail[0].index == {{ $index }}) { $refs.serialInput.focus(); $refs.serialInput.select(); }">
    <div class="input-group mb-2">
        <input type="text"
               class="form-control"
               wire:model.live="query"
               x-ref="serialInput"
               wire:keydown.enter.prevent="addSerial"
               placeholder="Scan/Type Serial Number..."
               autofocus>
        <div class="input-group-append">
            <button class="btn btn-outline-primary" type="button" wire:click="addSerial" wire:loading.attr="disabled">
                <i class="bi bi-plus-lg"></i> Tambah
            </button>
        </div>
    </div>

    @if($error_message)
        <div class="text-danger small mb-2">
            <i class="bi bi-exclamation-circle"></i> {{ $error_message }}
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted">Tekan Enter atau klik Tambah untuk menambahkan.</small>
        <div wire:loading wire:target="addSerial" class="spinner-border spinner-border-sm text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
</div>
