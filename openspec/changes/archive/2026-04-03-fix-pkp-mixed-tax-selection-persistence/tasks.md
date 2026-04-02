## 1. Reproduce And Lock The Regression

- [x] 1.1 Add purchase Livewire regression tests for mixed PKP cart rows where one row selects the default tax and another selects a non-default tax.
- [x] 1.2 Add sale Livewire regression tests for mixed PKP cart rows where one row selects the default tax and another selects a non-default tax.
- [x] 1.3 Add submit-sequence tests covering recalculation or tax-included toggles after mixed tax selections to confirm persisted per-line tax state survives validation.

## 2. Unify Per-Line Tax Persistence

- [x] 2.1 Refactor purchase cart tax selection handling so every tax update path reads and writes the same persisted cart-row `product_tax` source of truth.
- [x] 2.2 Refactor sale cart tax selection handling so every tax update path reads and writes the same persisted cart-row `product_tax` source of truth.
- [x] 2.3 Ensure quick-add tax creation, tax updates, and recalculation handlers target the intended cart line deterministically in both modules.

## 3. Preserve Mixed Selections Through Recalculation And Submit

- [x] 3.1 Update purchase cart recalculation and tax-included flows to preserve each line's explicit tax selection without default-option bleed-through.
- [x] 3.2 Update sale cart recalculation and tax-included flows to preserve each line's explicit tax selection without default-option bleed-through.
- [x] 3.3 Verify purchase and sale create/edit submit paths continue to validate against the persisted cart-row tax state rather than transient UI ordering.

## 4. Validate And Close Out

- [x] 4.1 Run the focused purchase PKP tax regression tests and confirm mixed-row scenarios pass.
- [x] 4.2 Run the focused sale PKP tax regression tests and confirm mixed-row scenarios pass.
- [x] 4.3 Perform a final review for consistency with the updated `tax-assignment` spec and remove any obsolete assumptions about default tax visual priority implying selection.
