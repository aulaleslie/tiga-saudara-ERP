## 1. Authorization and correction history foundation

- [ ] 1.1 Inspect and document the existing Super Admin authorization convention, then add `purchases.received.correct` to the canonical permission catalog, role UI, and safe permission seeding/migration path.
- [ ] 1.2 Add non-destructive migrations and Eloquent models for immutable received-purchase correction audit records, including setting, purchase, actor, reason, before/after payload, and recalculation result/linkage fields.
- [ ] 1.3 Add tenant-scoped authorization/policy helpers that allow only Super Admin or `purchases.received.correct` users to access eligible `RECEIVED` and `RECEIVED PARTIALLY` purchases.
- [ ] 1.4 Add feature tests covering permission catalog visibility, role assignment, Super Admin access, unauthorized denial, and cross-setting denial.

## 2. Safe monetary correction workflow

- [ ] 2.1 Create a dedicated received-purchase correction route, controller/service, and UI entry point that is separate from the normal purchase edit flow.
- [ ] 2.2 Build the correction form from existing purchase data with editable supported line monetary fields, global discount, shipping, mandatory reason, and read-only protected document/receipt fields.
- [ ] 2.3 Implement in-place, transactionally locked update of allowed purchase and purchase-detail monetary fields without deleting/recreating detail rows or modifying receipt, serial, product, quantity, supplier, date, or location data.
- [ ] 2.4 Reuse/extend purchase normalization so corrected line tax, line subtotal, header discount, shipping, total, and due figures are calculated consistently with standard purchase behavior.
- [ ] 2.5 Persist immutable field-level before/after audit data and display correction history on the received purchase detail screen.
- [ ] 2.6 Add feature and Livewire/controller tests for valid line-price/header corrections, protected-field rejection, stable purchase-detail IDs, receipt/serial link preservation, audit data, transaction rollback, and concurrent-state revalidation.

## 3. Payment reconciliation and review

- [ ] 3.1 Implement active-payment locking and a single source of truth for recalculating `paid_amount`, `due_amount`, and payment status after a correction.
- [ ] 3.2 Implement the exactly-one-active-payment path that previews and applies the replacement amount equal to the corrected document total, with payment before/after audit data.
- [ ] 3.3 Implement the multiple-active-payment selection/review interaction, calculate selected-payment delta deterministically, and reject negative selected-payment or overpayment results.
- [ ] 3.4 Preserve invalidated payments and existing payment metadata, attachments, and audit history during corrections.
- [ ] 3.5 Add feature tests for zero, one, and multiple active payments; selected-payment review; overpayment/negative blocking; invalidated-payment exclusion; and atomic rollback.

## 4. Explicit purchase-cost recalculation

- [ ] 4.1 Define a reusable corrected-purchase cost allocation component that computes tax-exclusive DPP plus deterministic proportional global-discount and shipping allocations with final-line rounding reconciliation.
- [ ] 4.2 Build an authorized cost-recalculation preview from the earliest affected approved receipt, reporting affected products, product-price rows, downstream sale snapshots, protected imported-HPP snapshots, warnings, and expected scope.
- [ ] 4.3 Implement the explicit purchase-average replay for affected products using established historical buckets, approved receipt effective dates, purchase returns, negative-stock handling, and deterministic event ordering.
- [ ] 4.4 Update affected current `product_prices` only after the user confirms recalculation, while preserving sales/tier/tax metadata and recording correction-linked result data.
- [ ] 4.5 Add tests for tax exclusion, allocated discount/shipping cost, rounding determinism, partial receipts, bucket isolation/fallback, unchanged save behavior, and idempotent replay results.

## 5. Optional downstream sale HPP replay

- [ ] 5.1 Extend the cost-recalculation confirmation flow with a separately explicit downstream-sale-HPP option and warning about historical profit/report changes.
- [ ] 5.2 Reuse or extract the sales cost snapshot replay engine to recompute only eligible later sale details for affected products from the corrected receipt forward.
- [ ] 5.3 Preserve authoritative imported-HPP snapshots, report skipped records, and record correction linkage and replay metadata for rewritten sale snapshots.
- [ ] 5.4 Add feature tests for POS and standard-sale downstream HPP changes, effective-date boundaries, same-date ordering, imported-HPP protection, preview/result counts, and repeat-run determinism.

## 6. Verification and operational readiness

- [ ] 6.1 Update relevant purchase, permission, normalization, sales-cost, payable, and profit/report regression tests to reflect the new explicit correction and replay semantics.
- [ ] 6.2 Run focused module tests and `composer test:fresh-sqlite`; resolve failures without changing unrelated behavior.
- [ ] 6.3 Perform a UAT walkthrough for a fully received and paid purchase with one payment, multiple payments, global discount/shipping changes, audit-history review, average-only recalculation, and opted-in downstream HPP replay.
