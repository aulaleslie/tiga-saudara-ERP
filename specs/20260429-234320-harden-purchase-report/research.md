# Research: Harden Purchase Report Validity

## Decision 1: Centralize report filtering and validation in a shared builder/service used by screen and exports
- Decision: Introduce a shared purchase-report query/validation layer (for Livewire render + export actions) so both paths use one validated filter contract and one canonical query pipeline.
- Rationale: Current implementation duplicates filtering between `app/Livewire/Reports/PurchaseReport.php` and `app/Exports/PurchaseReportExport.php`, creating drift risk and violating FR-004/FR-007/FR-009.
- Alternatives considered:
  - Keep duplicated query logic and add tests only: rejected, still fragile to future changes.
  - Move all logic into export class and reuse from Livewire: rejected, wrong ownership and poor readability.

## Decision 2: Enforce snapshot-based export precondition from the latest successful "Tampilkan Laporan" run
- Decision: Persist an in-memory/session snapshot token and validated filter hash after successful `applyFilters`, and require exports to reference that exact state.
- Rationale: Spec clarification requires export to use the exact validated result set snapshot and to block export before a successful run.
- Alternatives considered:
  - Recompute from current form state at export time: rejected, can diverge from validated run.
  - Store exported IDs permanently in DB: rejected for this scope (extra persistence and cleanup complexity).

## Decision 3: Validate filter inputs with explicit allowed options and date rule before query execution
- Decision: Add strict validation rules for start/end date, tax flag, status, payment status, and supplier/tag existence before generating report or export.
- Rationale: FR-001/FR-002/FR-003/FR-010 require blocking invalid and contradictory inputs with clear messages.
- Alternatives considered:
  - Loose type coercion with silent ignore: rejected, hides invalid requests.
  - Frontend-only validation: rejected, server-side guard still required.

## Decision 4: Use active purchase lifecycle signals and active payment transactions as source of truth
- Decision: Derive payment completion from active `purchase_payments` and treat lifecycle-relevant fields as canonical; do not use legacy unused fields in filtering validity checks.
- Rationale: FR-011 through FR-015 explicitly define this behavior.
- Alternatives considered:
  - Trust `purchases.payment_status` only: rejected, may be stale vs active payments.
  - Include legacy fields for backward compatibility: rejected by FR-012.

## Decision 5: Use server-side searchable typeahead only for high-cardinality Supplier and Tag filters
- Decision: Upgrade only Supplier and Tag controls to typeahead using server-side lookup with `minChars=2` and `debounce=300ms`; keep low-cardinality filters (tax/status/payment status) as normal selects.
- Rationale: FR-016/FR-017 and SC-005 require scale-safe UX without loading full option lists while minimizing UI complexity and query volume.
- Alternatives considered:
  - Make all filters searchable: rejected, unnecessary complexity for low-cardinality fields.
  - Search from first character with no debounce: rejected due to higher server load risk.
  - Keep current static dropdowns: rejected because supplier/tag volume may grow to thousands.

## Decision 6: Preserve existing Laravel/Livewire/module patterns and risk-proportional verification
- Decision: Keep implementation in existing files/modules (`app/Livewire/Reports`, `app/Exports`, `Modules/Reports`) and verify with focused feature tests plus targeted manual export/typeahead checks.
- Rationale: Aligns with constitution principles on pattern fidelity and proportional verification.
- Alternatives considered:
  - Introduce a new standalone reporting module: rejected, unnecessary abstraction for this feature.

## Decision 7: Multi-select pill-based UI for Supplier and Tag typeahead (FR-016, FR-019)
- Decision: Upgrade Supplier and Tag filters from single-select to multi-select with pill-based display. Use Livewire array properties (`$supplierIds`, `$selectedTags`) instead of scalar properties. Each selected item renders as a dismissible pill showing the item name. Selection uses dedicated Livewire action methods (`selectSupplier`, `removeSupplier`, `selectTag`, `removeTag`) instead of inline `$set` chaining. Query layer uses `whereIn` / `whereHas` with arrays.
- Rationale: FR-016 clarification (Session 2026-04-30) requires multi-select; FR-019 requires dismiss-on-select + clear input + add pill interaction. Using dedicated methods follows Livewire 3 best practices and avoids race conditions from chained `$set` calls.
- Alternatives considered:
  - Keep single-select with pill display: rejected per clarification answer.
  - Keep dropdown open after selection for rapid multi-add: rejected per clarification answer.
  - Inline `$set` chaining in Blade: rejected, unreliable in Livewire 3 and violates Laravel Pattern Fidelity.

## Decision 8: CoreUI `form-control` styling for standard select dropdowns (FR-018)
- Decision: Replace `form-select` class with `form-control` on Pajak, Status, and Status Pembayaran `<select>` elements to match the existing CoreUI theme conventions used across the ERP.
- Rationale: FR-018 clarification (Session 2026-04-30). The app uses CoreUI (`@import '@coreui/coreui/scss/coreui'`) and existing styled selects in other modules use `form-control`. Using `form-select` alone renders as unstyled browser-default on some CoreUI builds.
- Alternatives considered:
  - Dual class `form-select form-control`: rejected, simpler to use the one convention already working.
  - Custom CSS for `form-select`: rejected, adds maintenance burden when `form-control` already works.
