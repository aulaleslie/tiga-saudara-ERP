# Tasks

## 1. Override quantity behavior

- [x] 1.1 Update POS row-override refresh so a quantity change on `LINE_TOTAL_OVERRIDE` carries forward the stored displayed two-decimal unit price, changes authority to unit price, and recomputes canonical minor-unit fields; verify a focused test preserves Rp10.000,00 at quantity three and yields Rp13.333,32 at quantity four.
- [x] 1.2 Ensure all quantity mutation paths, including serial-driven increments, preserve explicit and derived unit-price overrides without reapplying packed or tier pricing; verify focused feature cases for each applicable path.

## 2. Pricing regression coverage

- [x] 2.1 Add a focused POS feature case for conversion-unit selection, tier-customer selection, row-total override, then quantity increase; verify the unit price stays fixed, the new total is recalculated, and canonical source/metadata match.
- [x] 2.2 Update the existing row-total invalidation test expectations for quantity changes while retaining discount/tax-context restoration and customer repricing checks; verify only the affected focused test class passes.
- [x] 2.3 Cover ordinary, packed, and bundle-parent row totals where supported, plus explicit unit-price override and automatic packed pricing controls; verify the relevant focused POS pricing tests pass.

## 3. Final verification

- [x] 3.1 Run `openspec validate preserve-pos-overridden-unit-price-on-quantity-change --strict` and focused `php artisan test --filter` commands for the affected POS override and conversion-pricing tests; record any remaining limitation without running the full suite.
