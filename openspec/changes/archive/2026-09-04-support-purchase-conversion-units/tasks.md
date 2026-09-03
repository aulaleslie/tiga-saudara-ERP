## 1. Schema and Domain Foundations

- [x] 1.1 Add nullable Purchase-detail snapshot columns for selected unit/conversion identity, entered quantity and unit price, factor, and durable unit labels, with MySQL/MariaDB and SQLite-compatible indexes and foreign-key behavior.
- [x] 1.2 Convert `received_note_details.quantity_received` to the agreed decimal quantity precision without changing existing integer values, and define a rollback guard against fractional-data loss.
- [x] 1.3 Extend Purchase detail and receiving-detail models with fillable fields, decimal casts, relationships, and legacy factor-one/base-unit fallback accessors.
- [x] 1.4 Implement a decimal-safe Purchase UOM conversion service/value object that validates server-loaded product/conversion ownership and eligibility, converts entered quantity and price, rejects unsupported precision, and emits persistence snapshots.
- [x] 1.5 Add focused migration, model fallback, conversion arithmetic, tampered conversion, and repeating base-price tests.

## 2. Product Conversion Invariants

- [x] 2.1 Centralize server-side validation requiring conversion factors greater than `1` and whole factors for serialized products.
- [x] 2.2 Apply the invariant to full product create/edit, unit-configuration Livewire flows, shared product quick-add, and every currently supported conversion-writing request/import path.
- [x] 2.3 Update conversion form constraints and messages to explain that the base unit is the smallest unit while retaining server validation as authoritative.
- [x] 2.4 Exclude inactive, mismatched, and legacy factor-`<=1` conversions from new Purchase choices without modifying existing conversion rows.
- [x] 2.5 Add focused tests for normal forms, quick-add, crafted submissions, serialized decimal factors, valid non-serialized decimal factors, and untouched legacy rows.

## 3. Conversion-Aware Purchase Cart

- [x] 3.1 Extend Purchase product search/selection payloads to load the product base unit and eligible conversions without introducing per-row N+1 queries.
- [x] 3.2 Add per-line unit selection to Purchase create/edit, default it to the base unit, and show entered and canonical quantity equivalents.
- [x] 3.3 Replace product-ID-only cart and Livewire state keys with stable row identity that supports separate lines for the same product in different units and increments only exact product/unit matches.
- [x] 3.4 Make Purchase quantity inputs and recalculation paths preserve valid three-decimal values by removing integer validation, casts, and browser parsing in affected paths.
- [x] 3.5 Normalize selected-unit quantities and prices from authoritative server data while retaining the existing entered-unit pricing, tax, discount, and manual override authority.
- [x] 3.6 Add focused Livewire/cart tests for base selection, conversion selection, unit switching, mixed-unit duplicate product lines, decimal entry, invalid precision, invalid legacy conversion selection, and serial whole-base validation.

## 4. Purchase Persistence and Editing

- [x] 4.1 Extend Purchase normalization output to carry canonical quantity/base price and validated supplier-facing snapshots without trusting client-derived factor or canonical values.
- [x] 4.2 Persist conversion snapshots on Purchase create and mutable edit while preserving existing manual unit-price and manual line-total fields and flags.
- [x] 4.3 Rehydrate edit carts from stored snapshots, fall back legacy details to factor-one base-unit values, and preserve stored totals when no price-affecting interaction occurs.
- [x] 4.4 Ensure existing received-document and monetary-only edit restrictions cannot change unit, quantity, conversion identity, or factor snapshots.
- [x] 4.5 Add focused controller/Livewire integration tests for create, edit, reload stability, legacy fallback, changed/deactivated/deleted conversion configuration, tampered payloads, and post-receipt edit guards.

## 5. Exact Purchase Totals Without Increment Rounding

- [x] 5.1 Remove Purchase cart reads and applications of `row_total_rounding_increment` while retaining ordinary currency precision and existing pricing-source behavior.
- [x] 5.2 Remove configured increment rounding from `PurchaseNormalizer` and client-built cart handling without altering tax, discount, shipping, manual price, or manual line-total semantics.
- [x] 5.3 Preserve persisted existing Purchase totals on load and ensure subsequent price-affecting interactions recalculate through existing authority rules without increment rounding.
- [x] 5.4 Replace Purchase increment-rounding expectations with focused exact-total tests for automatic base/conversion rows, taxes, discounts, quantity changes, manual unit price, manual line total, edit hydration, and business switching.
- [x] 5.5 Run focused existing Sales and POS row-rounding tests to prove their configured-increment behavior remains unchanged.

## 6. Conversion-Aware Receiving and Serials

- [x] 6.1 Update receiving presentation to default to the ordered unit, allow only the ordered or base unit, and show canonical equivalent and remaining canonical quantity.
- [x] 6.2 Normalize receiving submissions to base-unit decimals using Purchase snapshots, remove integer-only request/browser handling, and persist only canonical quantity on receiving details.
- [x] 6.3 Rework minimum, remaining, completion, and over-receiving checks to use decimal-safe canonical comparisons, including the existing locked approval recheck.
- [x] 6.4 Require whole canonical received quantities and exactly one unique valid serial per received base unit for serialized products.
- [x] 6.5 Verify approved receipt stock, tax/non-tax buckets, transaction history, rejection, reversal, and partial completion use the exact canonical quantity.
- [x] 6.6 Add focused receiving tests for ordered-unit and base-unit partial receipts, decimals, over-receiving and concurrency guards, rejection, completion, stock posting, and serialized conversion counts.

## 7. Downstream Display and Compatibility

- [x] 7.1 Update Purchase show/print/export supplier-facing quantities to prefer entered snapshots and show canonical equivalents where operationally useful, with legacy fallback.
- [x] 7.2 Audit Purchase return selection, eligibility, and valuation paths so quantities remain canonical while user-facing unit context is retained where applicable.
- [x] 7.3 Audit directly affected purchase costing, normalization/replay, inventory transaction, and Purchase report queries for base-quantity assumptions and prevent double conversion.
- [x] 7.4 Ensure Purchase duplication and import paths either carry validated unit intent or retain their existing base-unit fallback without trusting external conversion factors.
- [x] 7.5 Add focused regression tests for Purchase return eligibility/valuation, costing, supplier-facing render/export, affected reports, duplication/import fallback, and legacy rows.

## 8. Focused Verification and Documentation

- [x] 8.1 Run the new and modified Product/Purchase unit, persistence, receiving, serial, downstream, and Purchase exact-total test files with focused `php artisan test --filter` or file paths.
- [x] 8.2 Run only existing tests covering plausibly regressed Purchase tax/discount/manual override, receiving/serial, return/costing/report, cross-business, and Sales/POS rounding behavior; do not require the full test suite.
- [x] 8.3 Run relevant static analysis, formatting, and JavaScript unit tests for touched PHP, Blade/Livewire, and Purchase calculator code.
- [x] 8.4 Document the base-unit invariant, conversion entry/receiving behavior, legacy fallback, and Purchase-only rounding exclusion in affected operator/developer documentation.
