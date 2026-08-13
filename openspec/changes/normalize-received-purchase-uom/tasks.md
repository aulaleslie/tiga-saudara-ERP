## 1. Replan and data model

- [x] 1.1 Reconcile the prior normalization implementation with this base-UOM-correction specification; remove its assumption that a conversion to the existing base UOM must already exist.
- [x] 1.2 Extend immutable correction audit data for old/new primary and base UOM, factor, conversion/barcode changes, per-location snapshots, rounding, and both operator acknowledgements.
- [x] 1.3 Define and migrate any missing uniqueness/provenance constraints needed for one-time base-UOM corrections and safe conversion/barcode migration.
- [x] 1.4 Retain and test receipt-detail-to-`BUY` provenance and conservative legacy matching.

## 2. Eligibility and scope

- [x] 2.1 Implement searchable product discovery scoped to the entry-point Purchase's own products, with preliminary eligibility feedback, and searchable `Unit`-catalog target selection (catalog-wide, not scoped to the Purchase).
- [x] 2.2 Validate target differs from current base, factor is positive/representable, product is stock-managed/non-serial, and other-setting physical/history footprint is blocked while price-only `ProductPrice` footprint is allowed.
- [x] 2.3 Discover all old-base purchase/receipt lines for the product; require every line to be selected and fully received, or void/cancelled without stock effect.
- [x] 2.4 Validate complete global and per-location stock lineage; block opening/import, transfer, adjustment, return, breakage, bundle, or other unexplained stock sources.
- [x] 2.5 Preserve outbound safety guards for dispatched Sales and completed POS/direct-or-bundle sales; allow but warn for non-dispatched Sale/POS drafts and loaded carts without mutating them.
- [x] 2.6 Report exact blockers, affected locations, transaction match state, conversion/barcode conflicts, and rounding in preview.

## 3. Atomic base-UOM correction

- [x] 3.1 Lock the complete product-wide active scope — product, target Unit, every active purchase/receipt row (not only submitted IDs), related Purchase/ReceivedNote headers, candidate `BUY`/stock-affecting transactions, stock rows, existing conversions, barcode identities, and prices — before running a single authoritative revalidation of every eligibility check inside one transaction.
- [x] 3.2 Change product primary/display and base UOM; create/retain former-base-to-target conversion and safely rebase compatible existing conversion factors, conversion prices, and barcodes.
- [x] 3.3 Update selected purchase and approved receiving quantities and purchase-side per-unit cost facts in place while preserving all supplier/document money and receipt locations.
- [x] 3.4 Update matched original `BUY` rows and reconstruct chronological global/location transaction snapshots, stock quantity/tax/broken buckets, and product aggregate quantity without a compensation transaction.
- [x] 3.5 Recalculate active-setting last-purchase and average purchase price in the new base UOM, and atomically rebase those two purchase-cost fields for every other price-only setting using sufficient precision and visible rounding rules.
- [x] 3.6 Do not modify sale/tier/conversion selling prices, historical Sale/POS values, or sale HPP snapshots; persist the required sales-price-review acknowledgement.

## 4. Purchase-native UI and audit

- [x] 4.1 Replace plain product/conversion selects with project-standard searchable product and Unit selectors; show source UOM read-only and target Unit from the catalog.
- [x] 4.2 Load only the selected product's related lines across the allowed scope and show selected-line plus per-location before/after tables.
- [x] 4.3 Add explicit preview confirmation acknowledgements for derived purchase/inventory effects and untouched-sales-price review; keep execution disabled until eligible and acknowledged.
- [x] 4.4 Keep dedicated permission and action entry points distinct from monetary correction; show actionable warnings without browser dialogs.
- [x] 4.5 Render immutable correction audit history on every affected Purchase.

## 5. Verification and operational readiness

- [x] 5.1 Add end-to-end tests for BOX-to-PCS correction without an existing conversion, several purchases, several stock locations, original-BUY in-place updates, and invariant supplier money.
- [x] 5.2 Add tests for searchable product/Unit selection, incomplete/unselected related receipts, serial, cross-setting physical/history blockers, price-only cross-setting purchase-cost rebase, lineage, barcode, conversion-collision, precision, and atomic rollback blockers.
- [x] 5.3 Add tests that purchase cost/HPP/last-purchase price rebase while every sales-price and historical sale/POS value remains untouched; cover required acknowledgements and draft warnings.
- [x] 5.4 Run focused Purchase, Product, Sales, POS, and report tests, then the highest-confidence SQLite suite; document operational remediation and mandatory pre-sale price review.
