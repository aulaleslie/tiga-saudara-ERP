## 1. Cross-Business Column Copy Controls

- [x] 1.1 Render a hidden apply-to-all button beside each editable sale, tier 1, tier 2, and last purchase price input, with stable per-column and per-field hooks; leave average purchase price read-only without a control.
- [x] 1.2 Add numeric normalization and dirty-state handling that shows each control only when its masked input differs from that field's loaded baseline, including initial, manual-edit, restored-value, and validation-old-input states.
- [x] 1.3 Implement same-column propagation, mask refresh, and affected-field dirty-state recalculation without submitting the form or changing other columns.
- [x] 1.4 Extend Cancel handling to restore every original value, refresh masks, and hide all apply-to-all controls while preserving the existing Save submission flow.

## 2. Receiving Last Purchase Price Synchronization

- [x] 2.1 Add a focused Product service that synchronizes only `last_purchase_price` for a product across all current settings using the existing idempotent ProductPrice seeding behavior.
- [x] 2.2 Invoke the synchronizer for each positively received detail inside ordinary receiving approval, using the existing `purchaseDetail->price` value and preserving the legacy product update and existing average-price calculation.
- [x] 2.3 Ensure missing business price rows are created while existing selling, tier, average, and tax fields remain unchanged except for the separate established average synchronization behavior.

## 3. Focused Verification

- [x] 3.1 Add or extend focused Product feature tests to verify every editable field renders the required column-copy hooks while average purchase price does not, and that the existing authorized save path remains intact.
- [x] 3.2 Add or extend focused Purchase receiving tests for synchronization across existing and missing setting rows, independent prices for multiple products, preservation of unrelated fields, and no mutation outside successful positive-quantity approval.
- [x] 3.3 Run only the affected Product cross-business price and Purchase approval price-sync test files with focused `php artisan test` filters.
- [x] 3.4 Review the client-side interaction against the dirty detection, masked numeric equality, same-column-only copy, no-immediate-save, Save, and Cancel scenarios if the current test infrastructure cannot execute the page JavaScript.
