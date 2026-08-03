## 1. Stabilize cart editor initialization

- [x] 1.1 Update the Purchase cart row and Total Baris editor identities so Livewire cannot reuse a stale editor DOM value after product selection or cart hydration.
- [x] 1.2 Update the Purchase Total Baris opening behaviour to expose the row's canonical current subtotal as the raw editable value.
- [x] 1.3 Update the Sales Total Baris opening behaviour to expose the row's canonical current subtotal as the raw editable value without changing bundle restrictions or manual-pricing semantics.

## 2. Add regression coverage

- [x] 2.1 Add focused Purchase cart coverage for stable wire:keys and canonical 46500 appearing in rendered HTML.
- [x] 2.2 Add focused Purchase cart-hydration structural test with explicit 46500 line total and stable wire:keys (manual cart seeding, not full edit-form integration).
- [x] 2.3 Add focused Sales cart coverage for stable wire:keys and canonical sub_total in rendered HTML (non-bundled rows only).
- [x] 2.4 Verify that committing a replacement Total Baris such as `50000` still follows the existing reverse-calculation, validation, and manual-price authority rules.
- [ ] 2.5 Add real Purchase and Sales edit-form-to-ProductCart integration coverage for Total Baris initialization when suitable Livewire component testing support is available.

## 3. Verify the change

- [x] 3.1 Run the revised Purchase and Sales ProductCart focus tests (cart-hydration structural coverage).
- [x] 3.2 Run the relevant existing line-total regression tests to ensure no breakage.

## Summary

Production fix and structural cart-hydration coverage are complete (9 tasks done, 1 task pending).

Real parent edit-form → child ProductCart integration coverage is intentionally deferred in task 2.5, pending improved Livewire component testing support or a clearer parent-child component inspection pattern.
