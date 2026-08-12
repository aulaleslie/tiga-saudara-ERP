## Context

Purchases store the supplier's external document number in `purchases.supplier_purchase_number`. The same nullable value is validated in Livewire creation, ordinary edit, and the dedicated `SupplierPurchaseNumberEditor`, plus legacy Store/Update Purchase requests. Their string-form Laravel `unique:` rules scope by `setting_id` but query the table directly, so archived records still cause a uniqueness error.

Sales have an internal ERP reference (`sales.reference`) and one external customer invoice/sales number (`sales.imported_sales_reference_number`). The latter is populated from the `no_faktur` import value and already drives import deduplication. `Sale` and `Purchase` use the Archivable global scope, so their model-based import lookups already exclude archived documents. There is no `customer_sales_number` column or independent customer-number workflow.

## Goals / Non-Goals

**Goals:**

- Enforce external supplier-number uniqueness only among unarchived Purchases of the same setting in every supported validation path.
- Retain the existing external Sales number as the single canonical customer sales number and present it consistently when populated.
- Preserve active-document duplicate prevention, self-exclusion on edit, authorization, lifecycle rules, archive behavior, and import ownership scoping.
- Add regression coverage for create, ordinary edit, and standalone correction behavior against active and archived conflicting documents.

**Non-Goals:**

- Do not create, migrate, backfill, or synchronize a `customer_sales_number` column.
- Do not make the internally generated `reference` reusable after archival; it has different semantics and database-level uniqueness constraints.
- Do not alter document archive eligibility, edit permissions, approval/receipt/dispatch behavior, or import routing.
- Do not change tax-reference-number uniqueness as part of this change.

## Decisions

### Use explicit active-record query constraints for Purchase validation

Replace affected string `unique:` rules with `Rule::unique` constraints that retain the setting scope, ignore the edited Purchase where applicable, and add `whereNull('archived_at')`. This makes archive intent explicit and applies uniformly to validator queries that do not instantiate the `Purchase` model.

Alternative considered: rely on the `Archivable` model global scope. This does not work for Laravel's table-based `unique:` validation rule. A database partial unique index was also rejected: portable partial uniqueness differs between MySQL/MariaDB and SQLite, and current schema deliberately has no uniqueness constraint for this nullable business field.

### Treat `imported_sales_reference_number` as the canonical customer sales number

Keep a single stored field for the external customer invoice/sales identifier. UI language may call it “Nomor Penjualan Pelanggan” (and clarify its imported-invoice origin), but the data contract remains `sales.imported_sales_reference_number`.

Alternative considered: add a separate customer sales number column. It would create two values with identical business meaning, require resolution rules, and risk mismatched imports, returns, and product-cost lookups.

### Preserve Eloquent-scoped import duplicate lookups

Purchase and Sales import services will continue using default `Purchase`/`Sale` queries for external-number lookup. Their archive global scope means only active records cause imported rows to be skipped. Tests will make this behavior explicit rather than changing the import algorithm.

### Keep correction-flow authority separate from uniqueness scope

The document-level supplier-number editor remains responsible for its existing authorization and archive checks. This change only changes which competing records constitute a duplicate; it does not widen the states or permissions that can be edited.

## Risks / Trade-offs

- [A missed validator path continues to treat archives as conflicts] → Inventory all Livewire and HTTP Purchase rules, and cover each behavior class with regression tests.
- [A future raw query bypasses archive semantics] → Use explicit `whereNull('archived_at')` in validation rules and model queries for import deduplication; describe the canonical policy in the capability spec.
- [Users confuse internal Sale references with the external customer number] → Display the external number with a clear label only when present, while retaining the internal reference as the ERP document identifier.
- [Concurrent submits choose the same external number] → Preserve the current application-level policy; this scoped change does not introduce a database constraint or alter existing concurrency behavior.

## Migration Plan

1. Deploy application validation, presentation, and test changes with no schema migration.
2. Verify that an archived Purchase or Sale external number can be reused, while an active same-setting document still blocks reuse.
3. Roll back by reverting the application release; no data conversion is required.

## Open Questions

- None. “Customer sales number” is resolved as the existing `imported_sales_reference_number`, not a new field.
