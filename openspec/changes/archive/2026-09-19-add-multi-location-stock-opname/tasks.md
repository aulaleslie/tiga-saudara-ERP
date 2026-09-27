# Tasks

## 1. Persistence and compatibility foundation

- [x] 1.1 Add the adjustment-location relation migration, model relationship, uniqueness/foreign-key indexes, and safe rollback guards; verify focused migration tests pass on SQLite and MySQL-compatible schema assumptions.
- [x] 1.2 Add one centralized selected-location-pool resolver that reads new relation rows and adapts historical `location_id` documents without writes; verify focused model/service tests cover multi-location, historical one-location, missing-location, duplicate, inactive, and consignment cases.
- [x] 1.3 Introduce count-draft schema version 2 with canonical location-set fingerprints and per-location baseline structures while preserving version-1 reads; verify focused draft serialization and compatibility tests pass.

## 2. Multi-location editor and draft validation

- [x] 2.1 Build a stock-opname-specific searchable multi-location selector using active standard cross-business options and business-aware labels without changing existing single-select callers; verify focused Livewire tests cover search, add/remove, duplicate rejection, consignment/inactive rejection, and selections outside the active setting.
- [x] 2.2 Replace the stock-opname component's singular location state with a locked canonical location-ID array and confirmed location-set changes that clear all dependent count state; verify focused Livewire tests cover confirmation, cancellation, tampered state, and scan blocking before a valid selection.
- [x] 2.3 Extend product baseline capture and opaque token/signature binding to every selected product/location pair and the complete location-set fingerprint; verify focused resolver tests reject expired, mismatched, or replayed tokens without disclosing stock.
- [x] 2.4 Update draft create/edit validation and persistence to atomically synchronize relation rows and schema-version-2 JSON, derive setting/PKP metadata authoritatively, and restore attempted input on failure; verify focused CountDraftService and controller tests pass.

## 3. Reconciliation and deterministic allocation

- [x] 3.1 Add a pure condition-specific allocation planner implementing shortage order `(is_pkp ASC, stock DESC, location_id ASC)` and surplus order `(is_pkp ASC, stock ASC, location_id ASC)` with the entire surplus assigned to the first target; verify focused tests cover mixed PKP, all-PKP, ties, waterfall exhaustion, zero stock, and nonnegative results.
- [x] 3.2 Rework reconciliation to bulk-load per-location good/damaged buckets, calculate selected-pool sums and drift, and produce independent good/damaged allocation previews; verify focused service tests cover surplus, shortage, reclassification, omitted products, and approval-time drift.
- [x] 3.3 Extend reconciliation DTOs and stock-visible show/review views with selected locations, per-location baseline/current values, allocation steps, and Indonesian warnings while retaining restricted counter visibility; verify focused controller/view tests cover both permission levels and historical documents.

## 4. Serialized pool reconciliation

- [x] 4.1 Update serial classification so selected-pool serials remain at their actual location, omitted serials are missing from their actual location, and outside/new serials use the deterministic condition-specific surplus destination; verify focused classifier tests cover retention, reclassification, movement, creation, omission, PKP-last destinations, and conflicts.
- [x] 4.2 Bind serial baseline/source evidence to the complete selected pool and reject unsafe claims, allocations, consignment sources, and discovery changes; verify focused serial reconciliation tests prove conflicts block approval without mutation.

## 5. Atomic approval and audit

- [x] 5.1 Replace single-destination approval discovery and locks with a stable lock hierarchy over the document, selected/source locations, settings, products, product stocks, and serials; verify focused approval tests cover deterministic ordering, concurrent discovery mismatch, and rollback.
- [x] 5.2 Apply ordinary-product good/damaged plans per affected location, preserve location tax classification, update global aggregates, and write location/setting-correct transactions and stock notifications; verify focused approval tests cover mixed-PKP shortages, surpluses, condition redistribution, and invariant failures.
- [x] 5.3 Apply serialized retention, movement, creation, reclassification, and omission plans from locked authoritative state; verify focused approval tests cover stock/serial bucket consistency and zero partial effects on failure.
- [x] 5.4 Persist immutable multi-location approval evidence containing selected locations, ordering, before/entered/applied values, allocation steps, serial provenance, tax classification, actor, and time; verify focused approved-detail tests remain stable after later stock changes.

## 6. Lifecycle, authorization, and notifications

- [x] 6.1 Replace active-setting ownership checks for version-2 Stock Opname with complete selected-pool eligibility plus existing action permissions, leaving breakage and legacy adjustment boundaries unchanged; verify focused lifecycle tests cover cross-setting access, insufficient permissions, and one ineligible member rejecting the whole action.
- [x] 6.2 Update submit, reject, delete, list/show, route binding, and notification behavior so no operation or message assumes one destination location; verify focused lifecycle and notification tests cover multi-setting documents and historical compatibility.

## 7. Focused verification and documentation

- [x] 7.1 Run the focused Stock Opname draft, Livewire, reconciliation, lifecycle, approval, serial, view, and migration test files with `php artisan test` filters, and record/fix any failures; do not require the full application test suite.
- [x] 7.2 Perform a focused manual browser check for cross-setting selection, count reset confirmation, combined totals, PKP-last preview, approval result, and historical single-location display; record the exercised cases in a change-local checklist.
