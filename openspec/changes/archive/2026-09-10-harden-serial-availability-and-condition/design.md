## Context

`product_serial_numbers` currently carries two overlapping dimensions: `status` describes lifecycle/custody (`ACTIVE`, `SOLD`, `RETURN_IN_PROCESS`, `RETURNED`, and the Stock Opname `MISSING` state), while `is_broken` describes physical condition. Most newer flows already represent physically present broken inventory as `ACTIVE + is_broken=true`, but older conversion/return code can persist `status=BROKEN`. Downstream consumers do not share one availability predicate: Stok Lintas Bisnis and Product Detail exclude only `RETURNED`, while several POS paths require `ACTIVE` but omit the broken/return checks.

The concrete production symptom is a serialized product with five active-good serials, one active-broken serial, and three missing serials: `ProductStock` correctly reports five good plus one broken, but Stok Lintas Bisnis reports eight `Siap Jual` because `MISSING != RETURNED`. Product Detail also presents the active-broken row ambiguously, and POS can select an active-broken serial.

This is cross-cutting across Product, Reports, POS, and Adjustment transfer consumers. User-facing text must be Bahasa Indonesia. Automated verification is focused; browser execution remains a human task.

## Goals / Non-Goals

**Goals:**

- Establish one reusable, queryable definition of operationally available, sellable, and available-broken serials.
- Preserve lifecycle and physical condition as separate concepts and present their combination clearly.
- Exclude `MISSING` and every other unavailable lifecycle state from counts, selectors, scans, cart assignment, and final sale posting.
- Make report/detail serial counts reconcile with the authoritative good/broken ProductStock buckets.
- Harden every POS boundary, including bundle components and final locked posting, against stale or crafted broken/missing assignments.
- Preserve explicit visibility of missing and historical serials in audit-oriented views.

**Non-Goals:**

- Change Stock Opname approval, its `MISSING` disposition, or previously persisted approval results.
- Delete missing, sold, returned, or historical serial records.
- Redesign product stock bucket accounting or repair unrelated quantity mismatches.
- Redesign purchase-return or sales-return workflows that intentionally locate unavailable serials.
- Run the full application test suite or automated browser tests.
- Bulk-migrate existing serial statuses as part of this change.

## Decisions

### 1. Lifecycle status and physical condition remain separate

`status=ACTIVE` means the physical serial remains operational inventory; it does not by itself mean sellable. `is_broken=true` means the serial is physically broken. Therefore `ACTIVE + is_broken=true` is valid available-broken inventory and must be labeled `Tersedia — Rusak`, not simply `Aktif` or `Siap Jual`.

`MISSING` means unavailable, while its retained `location_id` is provenance only. `SOLD`, `RETURNED`, and `RETURN_IN_PROCESS` are also unavailable for ordinary sale/transfer selection.

Alternative considered: set every broken serial to `status=BROKEN`. Rejected because it continues the existing lifecycle and condition dimensions, conflicts with Stock Opname's exact good/bad reclassification, and would require wider transition changes.

### 2. Central Eloquent scopes are the authoritative predicates

`ProductSerialNumber` will expose composable scopes with typed builders:

- operationally available: undispatched, not in return process, and lifecycle-compatible;
- sellable: operationally available, good condition, and active-compatible status;
- available broken: operationally available and broken condition.

Legacy `NULL` status is treated as active-compatible because the existing accessor already reads it as `ACTIVE`. Legacy `status=BROKEN` is treated as available-broken compatibility when it is undispatched and not returning, but never sellable. New code continues to persist physical broken inventory as `ACTIVE + is_broken=true`.

Alternative considered: duplicate explicit `where` clauses in each consumer. Rejected because the current defect was caused by predicate drift and future lifecycle states would repeat it.

### 3. Selectors use strict scopes; history uses explicit state filters

Any interface that can initiate an inventory operation uses the shared scopes. Product Detail gains separate sellable, broken, missing, returning, and historical/unavailable views using explicit status filters. Audit/history screens are not globally changed to `available()` because their purpose is to retain unavailable evidence.

Generic autocomplete call sites must declare their intent. Dispatch/sale callers use sellable or available-broken based on an explicit mode; return/recovery callers retain their own lifecycle-specific eligibility rather than inheriting a sale predicate accidentally.

### 4. POS validates at discovery, assignment, preflight, and locked posting

POS search and exact scan exclude broken/missing serials early for good feedback, but correctness does not rely on UI filtering. Cart assignment, bundle component assignment, preflight/allocation, and final locked posting independently revalidate sellability. A serial that becomes missing, broken, dispatched, sold, or returning after assignment fails atomically before posting stock or sale effects.

Alternative considered: filter only suggestions. Rejected because crafted requests and state changes between selection and checkout would bypass it.

### 5. Counts remain bucket-backed, serial dialogs are reconciliation evidence

Stok Lintas Bisnis quantity cells remain sourced from ProductStock good/broken buckets. The serial dialogs use canonical scopes and therefore should reconcile with those cells for serialized products. If counts disagree, the UI must not silently relabel unavailable serials as inventory; focused diagnostics/tests should expose the inconsistency for follow-up.

### 6. Bahasa Indonesia labels are derived centrally from combined state

A small presenter/value mapping will derive labels such as `Tersedia — Siap Jual`, `Tersedia — Rusak`, `Hilang — Tidak Tersedia`, `Terjual`, `Dalam Proses Retur`, and `Dikembalikan`. Blade templates render the mapped state and do not infer availability from raw status strings.

## Risks / Trade-offs

- [Existing `status=BROKEN` rows use an older representation] → Support them as available-broken compatibility without making them sellable; document new-write canonical behavior and add tests for both representations.
- [Nullable status compatibility can preserve ambiguous old records] → Limit compatibility to otherwise undispatched, non-returning rows and cover it explicitly; no silent compatibility for known unavailable statuses.
- [Central scopes could be applied to return/audit searches that need broader visibility] → Inventory call sites must be classified as selector versus history/recovery before replacement; do not perform a blind mechanical rewrite.
- [POS has multiple serial entry and finalization paths] → Test ordinary lines, exact scans, modal suggestions, direct assignment, bundle components, preflight, and final locked posting independently.
- [ProductStock and serial counts may already disagree for unrelated historical reasons] → Do not mutate balances in this change; report the mismatch and keep the serial state classification correct.
- [Stricter filtering may reveal workflows relying on unavailable serials] → Use focused module tests and human browser verification; preserve explicit return/recovery predicates where business rules require them.

## Migration Plan

1. Add scopes and combined-state presentation helpers without changing stored rows.
2. Update read-only Product Detail and Stok Lintas Bisnis consumers.
3. Update operational autocomplete/transfer consumers according to their explicit mode.
4. Harden POS from discovery through final locked posting.
5. Run focused Product, Reports, Adjustment transfer, and POS tests plus a targeted database check reproducing five sellable, one broken, and three missing serials.
6. Deploy without data migration. Rollback consists of reverting code; persisted lifecycle and condition data remain compatible.

## Open Questions

- Whether a future maintenance change should normalize legacy `status=BROKEN` rows to `ACTIVE + is_broken=true`; this change deliberately supports them without rewriting production data.
- Whether Product Detail should place every terminal lifecycle state in one `Riwayat/Tidak Tersedia` tab or provide a dedicated `Hilang` tab plus a combined history tab; the specification requires explicit missing visibility but permits either compact layout.
