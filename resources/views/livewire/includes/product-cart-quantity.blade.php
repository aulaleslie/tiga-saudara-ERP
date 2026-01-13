@php
    $cartOptions = $cart_item->options->toArray();
    $cartKey = $cartOptions['cart_key'] ?? $cart_item->id;
    $currentQty = $cart_item->qty;
@endphp
<div class="input-group d-flex justify-content-center">
    <input wire:key="cart-quantity-{{ $cartKey }}-{{ $currentQty }}"
           value="{{ $currentQty }}"
           wire:change="updateQuantityDirect('{{ $cart_item->rowId }}', '{{ $cartKey }}', $event.target.value)"
           type="number"
           inputmode="numeric"
           pattern="[0-9]*"
           class="form-control"
           style="min-width: 50px; max-width: 80px; text-align: center; font-weight: 600; border-radius: 6px; border: 1px solid #ced4da; padding: 0.375rem 0.5rem;"
           min="1"
           step="1"
           onkeydown="return (event.key >= '0' && event.key <= '9') || ['Backspace','Delete','Tab','ArrowLeft','ArrowRight','Home','End'].includes(event.key)"
           onpaste="return false">
</div>
