## 1. Pricing provenance persistence

- [x] 1.1 Add a backward-compatible `pricing_source` field to `sale_details` and model support for automatic, manual-unit-price, and manual-line-total values.
- [x] 1.2 Safely mark pre-existing sale details as protected manual pricing without changing their monetary values.
- [x] 1.3 Carry pricing source through Sales create persistence, update persistence, and edit-cart hydration.

## 2. Sales cart pricing behavior

- [x] 2.1 Add per-line Total Baris state and an editable control to the Sales cart, using the established Purchase validation and reverse-calculation rules adapted to Sales tax/discount calculations.
- [x] 2.2 Mark a standard line manual when either its unit price or Total Baris edit is committed, including an edit that produces the same numeric price.
- [x] 2.3 Centralize automatic standard-line price resolution for effective business product prices, customer tiers, and existing eligible quantity cascade behavior.
- [x] 2.4 Change customer selection and customer quick-add repricing to affect automatic non-bundled lines only.
- [x] 2.5 Preserve manually priced and bundled unit prices through quantity, discount, tax, tax-inclusion, and cart reconciliation recalculations.
- [x] 2.6 Handle absent effective-business ProductPrice rows by assigning zero only to affected automatic standard lines and issuing one consolidated actionable notification without legacy-price fallback.

## 3. Cross-business Sales behavior

- [x] 3.1 Extend the Sales draft business-context change path to reprice automatic non-bundled cart lines from the target business's ProductPrice row and current customer tier.
- [x] 3.2 Recalculate target tax context after automatic repricing while retaining every manually priced or bundled Sales unit price.
- [x] 3.3 Keep Purchase business-switch behavior unchanged and verify no shared cart behavior regresses it.

## 4. Verification

- [x] 4.1 Add focused Livewire coverage for unit-price and Total Baris manual authority across customer changes, including equal-value edits and persistence through save/reopen.
- [x] 4.2 Add line-total calculation coverage for non-PKP, PKP tax-inclusive, PKP tax-exclusive, fixed discount, percentage discount, invalid input, and 100-percent discount cases. **Improved**: Replaced comment-only checks with actual session-flash assertions for missing ProductPrice notifications. Added PKP tax-inclusive line-total reversal test.
- [x] 4.3 Add Sales business-switch coverage for automatic target-business repricing, manual-price preservation, bundle-price preservation, PKP transitions, and missing target price notification/zero behavior. **Improved**: Enhanced session-flash assertions to verify notifications include product names and target business name.
- [x] 4.4 Update existing Sales tier-pricing expectations and run the focused Sales/Purchase Livewire test suite.
