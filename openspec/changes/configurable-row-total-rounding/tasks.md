## 1. Business Configuration and Shared Arithmetic

- [ ] 1.1 Add the per-setting decimal row-total rounding increment migration with a `100.00` default, zero-disable semantics, and safe rollback.
- [ ] 1.2 Update the Setting model/factories, Business Configuration request validation, controller persistence/session synchronization, and form field for the non-negative increment.
- [ ] 1.3 Implement and unit-test a shared minor-unit half-up increment calculator covering disabled, up, down, midpoint, decimal-boundary, and small-positive cases.
- [ ] 1.4 Add focused Setting feature tests for default persistence, authorized updates, zero disablement, invalid values, and business isolation.

## 2. Sales Row Integration

- [ ] 2.1 Integrate effective-setting automatic rounding into Sales add, quantity, row-discount, tax, tier/customer, and bundle-parent recalculation paths after tax calculation.
- [ ] 2.2 Reallocate Sales pre-tax subtotal and tax from each rounded tax-inclusive automatic total while preserving manual unit-price and manual-line-total sources exactly.
- [ ] 2.3 Update Sales backend normalization/create/edit persistence and hydration so eligible automatic results are authoritative, legacy/manual rows remain stable on load, and grand totals are not rounded again.
- [ ] 2.4 Add focused Sales tests for the requested `78999.96`, `78899.96`, `78950`, and `78949` boundaries, PKP included/excluded reconciliation, per-row summation, manual bypass, bundle component stability, and draft reload behavior.

## 3. Purchase Row Integration

- [ ] 3.1 Add durable Purchase detail pricing-source metadata with automatic/manual values and a conservative legacy-manual migration/default strategy.
- [ ] 3.2 Integrate effective-setting rounding into Purchase automatic add, quantity, row-discount, tax, and automatic price recalculation paths after tax calculation.
- [ ] 3.3 Update Purchase normalization/create/edit persistence and hydration to preserve exact manual unit prices/totals, reconcile rounded tax allocation, avoid load-time mutation, and leave grand totals unrounded.
- [ ] 3.4 Add focused Purchase tests for rounding boundaries, PKP included/excluded tax reconciliation, configuration disablement, manual unit/total bypass, pricing-source persistence, and edit reload stability.

## 4. POS Canonical Calculation and Checkout

- [ ] 4.1 Extend POS cart context/snapshots to carry the effective rounding increment and automatic-versus-override pricing authority without breaking draft or idempotency behavior.
- [ ] 4.2 Apply automatic rounding in the canonical POS minor-unit calculator after the complete tax-inclusive visible row calculation, including automatic packed rows, while bypassing approved unit-price and line-total overrides.
- [ ] 4.3 Ensure POS cart display, payment validation, change, draft snapshots, receipts, transaction details, final checkout totals, and generated Sale details consume the same authoritative rounded rows without grand-total rerounding.
- [ ] 4.4 Update POS bundle settlement so captured component allocations remain exact, the parent residual absorbs the rounding difference, negative residuals remain rejected, and owner fragments are not independently rounded.
- [ ] 4.5 Add focused POS tests for ordinary and packed automatic rows, manual/approved override bypass, payment and receipt parity, draft round trip, the `79000 - 8999 = 70001` bundle allocation, negative residual protection, and split-owner reconciliation.

## 5. Focused Regression Verification

- [ ] 5.1 Run the affected shared rounding and Setting test files and resolve only regressions introduced by this change.
- [ ] 5.2 Run focused Sales and Purchase cart, normalizer, manual-price authority, tax, bundle, and persistence tests touching the changed paths.
- [ ] 5.3 Run focused POS cart totals, packed pricing, overrides, bundles, snapshots, checkout posting, payments, receipts, and split-owner tests touching the changed paths.
- [ ] 5.4 Confirm through targeted assertions that returns, internal bundle components, global discounts, shipping, historical loads, and document grand totals do not receive independent rounding.
