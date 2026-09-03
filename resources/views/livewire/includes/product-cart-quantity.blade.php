@php
    $cartKey = $cart_item->rowId;
    $currentQty = $cart_item->qty;
    $isPurchaseCart = ($cart_instance ?? 'purchase') === 'purchase';
@endphp
<div class="input-group d-flex justify-content-center">
    <input wire:key="cart-quantity-{{ $cartKey }}-{{ $currentQty }}"
           value="{{ $currentQty }}"
           wire:change="updateQuantityDirect('{{ $cart_item->rowId }}', '{{ $cart_item->id }}', $event.target.value)"
           type="number"
           inputmode="{{ $isPurchaseCart ? 'decimal' : 'numeric' }}"
           class="form-control"
           style="min-width: 50px; max-width: 80px; text-align: center; font-weight: 600; border-radius: 6px; border: 1px solid #ced4da; padding: 0.375rem 0.5rem;"
           min="{{ $isPurchaseCart ? '0.001' : '1' }}"
           step="{{ $isPurchaseCart ? 'any' : '1' }}"
           onkeydown="return {{ $isPurchaseCart ? "['0','1','2','3','4','5','6','7','8','9','.','-'].includes(event.key) || ['Backspace','Delete','Tab','ArrowLeft','ArrowRight','Home','End'].includes(event.key)" : "(event.key >= '0' && event.key <= '9') || ['Backspace','Delete','Tab','ArrowLeft','ArrowRight','Home','End'].includes(event.key)" }}">
</div>
