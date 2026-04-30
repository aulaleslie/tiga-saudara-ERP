## Context

The `ProductQuickAddModal` fails to visually reset `serial_number_required`, `product_stock_alert`, and `barcode` inputs after a successful product creation. The backend state resets correctly, but Livewire 3's DOM diffing algorithm does not replace the DOM nodes for these elements, leaving them in a "dirty" state visually. 

## Goals / Non-Goals

**Goals:**
- Force a DOM re-render of the `serial_number_required`, `product_stock_alert`, and `barcode` fields when the modal resets.

**Non-Goals:**
- Rewriting the underlying `resetForm()` logic.
- Upgrading Livewire versions.

## Decisions

- **Decision:** Use `wire:key` appending `$formResetVersion`.
  - **Rationale:** This is the established pattern in `product-quick-add-modal.blade.php` to bypass Livewire's diffing engine and force a complete DOM element replacement. This is highly effective and simple for fields where Livewire's internal tracking desyncs from the browser's form state.

## Risks / Trade-offs

- **Risk:** No major risks, this follows an existing and proven pattern in the codebase.
