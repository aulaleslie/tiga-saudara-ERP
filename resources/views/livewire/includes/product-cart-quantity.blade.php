@php
    $cartOptions = $cart_item->options->toArray();
    $cartKey = $cartOptions['cart_key'] ?? $cart_item->id;
@endphp
<div class="input-group d-flex justify-content-center">
    <input wire:model="quantity.{{ $cartKey }}"
           style="min-width: 40px; max-width: 90px;"
           type="number"
           class="form-control text-right"
           value="{{ $cart_item->qty }}"
           min="1"
           wire:blur="updateQuantity('{{ $cart_item->rowId }}', '{{ $cartKey }}')">
</div>
