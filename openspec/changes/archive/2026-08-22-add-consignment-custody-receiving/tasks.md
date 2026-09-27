## 1. Schema and module foundation

- [x] 1.1 Scaffold the Consignment module/domain using the repository's nwidart conventions and register its provider, routes, and navigation integration.
- [x] 1.2 Add the default-false indexed `locations.is_consignment` migration, model cast/scope, factories, and conservative classification-change guard foundation.
- [x] 1.3 Add migrations for Consignment Receival headers/lines with setting, supplier, lifecycle audit, product/UOM, fixed unit cost/DPP, and setting-driven tax snapshots.
- [x] 1.4 Add migrations for Consignment Receiving headers/details with location, full-quantity, pending serial payload, approval/rejection, stock/cost before-after snapshots, transaction provenance, and reversal audit fields/tables.
- [x] 1.5 Add durable consignment source support to inventory transactions and serial history/current-source lookup without breaking legacy Purchase receiving provenance.
- [x] 1.6 Add database indexes and idempotency constraints for references, lifecycle lookups, active receiving uniqueness, supplier/product/location receipt queries, serial provenance, and reversals; perform a read-only duplicate preflight before proposing any product/location stock uniqueness change.
- [x] 1.7 Implement Consignment Eloquent models, relationships, casts, status constants, setting scopes, factories, and restrictive deletion behavior.

## 2. Permissions, settings, and location governance

- [x] 2.1 Register least-privilege permissions for consignment access, create/update, submit, document approve/reject, receive, receiving approve/reject, and full reversal, following existing permission configuration and seeding patterns.
- [x] 2.2 Extend Location create/edit/index UI and validation with the consignment flag, clear labels, active-dependency checks, cache behavior, and tenant-safe route-model authorization.
- [x] 2.3 Exclude consignment locations from ordinary Purchase receiving selectors and enforce the same prohibition in receiving creation and approval domain/controller validation.
- [x] 2.4 Add service-level setting-boundary guards so foreign-setting or unauthorized mutations fail even through direct requests.
- [x] 2.5 Add navigation entries and notification labels behind permission checks.

## 3. Consignment Receival lifecycle

- [x] 3.1 Implement setting-scoped reference allocation with transactional concurrency protection and no collision with ordinary Purchase references.
- [x] 3.2 Implement receival draft creation/editing for existing active stock-managed non-bundle products, supplier, quantities, fixed costs, UOM snapshots, setting-driven taxes, references, and notes.
- [x] 3.3 Implement server-side line normalization and validation for positive decimal base quantities, whole serialized quantities, fixed unit DPP, PKP-required tax, and non-PKP null/zero tax persistence.
- [x] 3.4 Implement submit and resubmit transitions with immutable lifecycle audit evidence and approval notifications.
- [x] 3.5 Implement document approval/rejection services using locked authoritative state, required rejection reasons, immutable approved snapshots, notification resolution, and no stock/payable mutation.
- [x] 3.6 Build receival index, create, edit, show, approval/rejection, status/audit, and validation-feedback surfaces following existing Laravel/Livewire/Blade conventions.

## 4. Full receiving capture

- [x] 4.1 Implement receiving eligibility and creation for approved receivals, enforcing one active full receiving note and setting-owned `is_consignment` location selection.
- [x] 4.2 Implement exact detail-quantity validation and require another receival rather than partial or multiple receiving.
- [x] 4.3 Implement pending serialized input validation for exact count, normalization, within-request uniqueness, product identity, and active serial conflicts without committing serial state.
- [x] 4.4 Implement pending receiving rejection with permission, tenant, state-lock, required reason, audit, notifications, and zero inventory/cost effect.
- [x] 4.5 Build receiving creation, detail, pending approval/rejection, serial entry, source snapshot, and audit UI.

## 5. Atomic custody approval

- [x] 5.1 Implement a dedicated Consignment Receiving approval service that locks and revalidates header, note, location, products, ProductPrice rows, stocks, and serial candidates inside one database transaction.
- [x] 5.2 Apply aggregate product quantity and location stock changes using approved base quantities and immutable tax/non-tax bucket snapshots, including safe first stock-row creation under a stable lock.
- [x] 5.3 Create `CONSIGNMENT_RECEIPT` inventory transactions with correct setting-level and location-level before/after balances and durable consignment detail provenance.
- [x] 5.4 Establish immutable non-serialized supplier receipt lots through approved receiving details without maintaining a drift-prone available-balance counter.
- [x] 5.5 Create or safely reactivate serialized inventory and record immutable `RECEIVED` history/current-source evidence referencing the consignment receiving detail.
- [x] 5.6 Calculate and persist the receiving setting's weighted operational average cost from authoritative pre-receipt setting quantity and approved unit DPP, seed missing averages, retain before/after snapshots, and leave other settings and every last-purchase-price field unchanged.
- [x] 5.7 Finalize receiving status, approver evidence, and notifications only after every stock, transaction, provenance, serial, and cost mutation succeeds.
- [x] 5.8 Add explicit guards/tests proving consignment approval never creates Purchase, PurchaseDetail, ReceivedNote, payable, PurchasePayment, or payment eligibility records.

## 6. Controlled full reversal

- [x] 6.1 Implement reversal preview/eligibility that identifies later movements, changed stock/cost snapshots, serial changes, active dependencies, or other evidence preventing exact reversal.
- [x] 6.2 Implement authorized full-only reversal using locked authoritative state and stored snapshots to restore stock buckets, product/setting quantities, setting average cost, serial state/history, and supplier receipt provenance atomically.
- [x] 6.3 Create `CONSIGNMENT_RECEIPT_REVERSAL` transactions and immutable actor, time, reason, source, and before/after evidence without deleting original approvals.
- [x] 6.4 Build the reversal review/confirmation UI with explicit full-scope acknowledgement and actionable blocking evidence; reject all partial or direct-edit attempts.

## 7. Valuation and audit visibility

- [x] 7.1 Update inventory transaction labels, detail presentation, and stock history to identify consignment receipt and reversal sources without interpreting them as ordinary BUY activity.
- [x] 7.2 Update Inventory Valuation query/replay, summaries, detail rows, and exports to separate supplier-owned consignment custody from company-owned quantity/value while preserving standard-only parity.
- [x] 7.3 Update Warehouse Stock Valuation filters, grouping, totals, CSV, and XLSX to label consignment locations, show physical custody separately, and exclude consignment value from company-owned grand totals.
- [x] 7.4 Add a read-only consignment custody reconciliation view for approved received and reversed quantities by setting, supplier, product, location, and serial/source detail.

## 8. Verification and release controls

- [x] 8.1 Add migration and model tests for MySQL/MariaDB intent and SQLite compatibility, defaults, constraints, relationships, casts, indexes, deletion restrictions, and no historical backfill.
- [x] 8.2 Add feature tests for receival draft/submit/resubmit/approve/reject transitions, permissions, tenant isolation, immutable snapshots, validation, and notifications.
- [x] 8.3 Add receiving tests for location admission, full-only quantity, one-active-note constraint, serialized validation, rejection safety, and forged/direct-request guards.
- [x] 8.4 Add atomic approval tests covering multi-line success, failure rollback, duplicate/concurrent approval, first stock-row creation, setting/location transaction balances, tax buckets, provenance, serial history, and no payable creation.
- [x] 8.5 Add operational average-cost tests for new-product seeding, weighted receipts, decimal quantities, PKP DPP, non-PKP behavior, other-setting isolation, unchanged last purchase price, and rollback.
- [x] 8.6 Add reversal tests for exact eligible restoration, immutable history, partial-reversal denial, later-movement blockers, concurrent reversal/approval, and rollback on failure.
- [x] 8.7 Add ordinary Purchase receiving regression tests proving standard locations retain current stock, serial, cost, notification, and per-setting transaction behavior while consignment locations are rejected.
- [x] 8.8 Add Inventory Valuation and Warehouse Stock Valuation screen/export parity tests for owned versus consignment quantity/value and unchanged standard-only totals.
- [x] 8.9 Run focused module/report tests, `php artisan test` filters, and `composer test:fresh-sqlite`; document any unrelated pre-existing failures without modifying unrelated working-tree changes.
- [x] 8.10 Complete controlled UAT with PKP and non-PKP settings, a new zero-average product, existing-average product, non-serialized and serialized lines, rejection/replacement receiving, reversal, and cross-setting isolation with controlled permission assignment.
