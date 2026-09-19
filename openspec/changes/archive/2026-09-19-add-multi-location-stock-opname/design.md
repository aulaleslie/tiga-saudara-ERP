# Design

## Context

See `proposal.md` for motivation. The supported Stock Opname workflow currently stores one `adjustments.location_id`, embeds the same location in count-draft schema version 1, captures one baseline per product, and treats that location as the destination throughout reconciliation and approval. Active-setting ownership is enforced by the selector, draft service, ownership guard, controller, lifecycle service, reconciliation service, and approval service. Approval locks and replaces one location's absolute good/damaged counts; serialized reconciliation likewise assumes one destination.

The product-stock table already holds condition and tax buckets per product/location, while product aggregates and serial records are global. Selected locations may span settings with different PKP status, so allocation and transactions must remain location-aware.

## Goals / Non-Goals

**Goals:**

- Establish one authoritative, ordered, unique location set for every new stock-opname document.
- Compute physical differences against the selected pool while retaining enough per-location evidence to review and audit allocation.
- Make difference allocation deterministic, PKP-last, condition-specific, transactional, and safe under concurrent inventory activity.
- Preserve historical single-location documents without data rewriting.
- Keep stock visibility and lifecycle permissions intact while removing active-setting ownership as the location boundary.

**Non-Goals:**

- Balancing a surplus progressively until selected locations reach equal quantities; the complete surplus goes to the first ordered destination.
- Treating omitted products as zero-count products.
- Including consignment or inactive locations.
- Reworking unrelated adjustment/breakage workflows or the shared selector's existing callers.
- Running the complete application test suite; focused stock-opname verification is sufficient for this change.

## Decisions

### 1. Normalize document locations and retain the legacy column

Add an `adjustment_locations` relation with a unique `(adjustment_id, location_id)` pair and an optional stable selection-position column. New multi-location Stock Opname documents use this relation as the authoritative selected set. Keep `adjustments.location_id` nullable and unchanged for backward compatibility with legacy adjustment and breakage behavior; new Stock Opname code must not infer the complete pool from that column.

Historical versioned documents without relation rows adapt their existing `location_id` to an in-memory one-location pool. Reads do not backfill or rewrite historical JSON. This avoids an irreversible bulk migration and keeps historical approval evidence intact.

Alternative considered: store only an array in `count_draft`. Rejected because lifecycle authorization, joins, referential integrity, notifications, and deletion behavior need normalized location membership independent of mutable JSON.

### 2. Introduce count-draft schema version 2

Schema version 2 replaces the singular location fields with `locations`, each containing authoritative location ID, setting ID, PKP snapshot, and per-product baseline evidence. Product rows retain one combined entered good/damaged count and serial set because the operator is counting the pool, not entering a count for each warehouse.

On save, the server canonicalizes the location IDs (unique integers in stable ascending order), reloads every location, rejects the whole submission if any member is ineligible, derives setting/PKP metadata, and captures/verifies baselines keyed by product and location. Baseline cache tokens and signatures bind to the complete canonical location-set fingerprint so a token from one pool cannot be replayed for another.

Alternative considered: keep schema version 1 and add `location_ids`. Rejected because singular location, PKP, and baseline semantics would become ambiguous and invite mixed-version handling mistakes.

### 3. Use a dedicated multi-select interaction

Build a stock-opname multi-location control using the established searchable dropdown behavior, with cross-business labels and active-standard filtering, without changing the single-value contract used by transfer, breakage, and other callers. The parent stock-opname component owns a locked array of canonical selected IDs. Every add/remove transition is server-validated and follows the existing confirmation-and-clear behavior once count-dependent state exists.

Alternative considered: make `LocationSearchDropdown` model both scalars and arrays. Rejected because it would broaden a stable shared component contract and increase regression risk for unrelated workflows.

### 4. Separate pool totals from per-location allocation

Reconciliation bulk-loads current stocks for every entered product and selected location. For each product it derives per-location and summed good/damaged values, compares the combined physical counts with the sums, and builds an immutable allocation preview from current values.

The allocation algorithm runs independently for `good` and `damaged`:

- Shortage: sort locations by `(is_pkp ASC, relevant condition stock DESC, location_id ASC)` and deduct through the list until the difference is satisfied.
- Surplus: sort locations by `(is_pkp ASC, relevant condition stock ASC, location_id ASC)` and assign the entire surplus to the first eligible location.

PKP is therefore a grouping priority, not a stock tie-breaker: every eligible non-PKP location is considered before any PKP location. If all locations are PKP, ordinary stock ordering applies within that group. Allocation uses approval-time authoritative values, so preview drift causes a recalculated plan that is shown on review and recomputed under lock at approval.

### 5. Preserve tax buckets by applying effects at their locations

Unchanged quantities retain their existing tax/non-tax buckets. A deduction consumes the relevant good/damaged bucket at the source location according to that location's authoritative PKP classification. A surplus or incoming/new serial is classified from the chosen destination's current setting. Each affected location receives its own inventory transaction with its own setting ID and before/after evidence.

Because standard locations are expected to have stock aligned with their setting, approval rejects internally inconsistent bucket state rather than silently converting it. This keeps cross-setting pooling from erasing accounting provenance.

Alternative considered: derive all tax allocation from the first selected location. Rejected because selected locations can belong to different settings and it would misstate both stock and transaction ownership.

### 6. Treat serial identity as stronger than quantity priority

For a serial already located within the selected pool, retain its location and apply only its entered condition change. An omitted available serial is removed/marked missing from its actual selected location. An existing eligible serial outside the pool moves to the surplus-priority destination for its entered condition; a new serial is created at that same deterministic destination. PKP-last ordering therefore applies to destinations, while exact source provenance governs deductions.

The preview and locked approval share one classifier/allocation result shape. Unsafe claims, allocations, status conflicts, consignment sources, or discovery/lock disagreements block the whole approval.

Alternative considered: redistribute every entered serial by aggregate priority. Rejected because it would move serials already physically represented inside the selected pool without evidence that they changed warehouse.

### 7. Replace ownership guard with complete-set eligibility

Stock-opname permissions remain the action authorization boundary. Location eligibility for this workflow becomes: exists, active, standard/non-consignment, and included in the document's authoritative relation. It does not compare `setting_id` with the current session setting. Every lifecycle boundary validates the complete relation before loading protected stock evidence or mutating state.

Other adjustment and breakage workflows retain their current active-setting rules. Notifications that were previously scoped through one location must either represent the full document without a misleading location filter or create resolvable entries per selected setting/location using the existing notification service conventions.

### 8. Lock all affected records in one stable order

Approval performs unlocked discovery only to determine the complete candidate set, then starts/continues one database transaction and locks in ascending IDs: adjustment, selected and serial-source locations, settings, products, product stocks, and serials. It revalidates the selected relation and discovered serial locations after locking, computes every allocation plan before mutation, rejects all conflicts, then applies stocks, aggregates, serials, transactions, notifications, and the approval snapshot atomically.

The immutable result stores selected locations and settings, baseline/current/entered values, sort order, condition-specific allocation steps, per-location before/after buckets, serial movements, actor, and timestamp.

## Risks / Trade-offs

- [Cross-setting documents weaken the old tenant boundary] → Keep action permissions mandatory, validate the complete active-standard location set at every boundary, and avoid using the active setting as implicit authorization or transaction ownership.
- [Concurrent stock changes can alter which location has most or least stock] → Recompute under locks and persist the applied ordering/result; surface drift in the review preview.
- [Independent good/damaged allocation can affect different locations] → Show both plans explicitly and persist condition-specific audit evidence.
- [Mixed PKP pools can expose inconsistent historical bucket data] → Validate bucket invariants and reject approval with an actionable conflict instead of guessing a conversion.
- [Multi-location serial discovery expands the lock set] → Bulk-query candidates, use one global lock hierarchy, and retain bounded discovery/revalidation.
- [Existing code may still read `adjustments.location_id`] → Centralize selected-pool resolution, add focused compatibility tests, and audit stock-opname callers before switching new documents to the relation.

## Migration Plan

1. Add the adjustment-location relation and indexes without modifying existing rows or dropping the legacy foreign key.
2. Deploy relation-aware reads that adapt a historical `location_id` to a one-location pool.
3. Enable schema-version-2 creation/editing and multi-location UI only after read compatibility exists.
4. Switch reconciliation, lifecycle checks, approval, notifications, and show views to the authoritative pool resolver.
5. Verify focused draft, authorization, allocation, serial, concurrency/rollback, presentation, and historical-compatibility tests.

Rollback removes schema-version-2 creation first. The relation table can remain harmlessly during application rollback; dropping it is safe only after confirming no schema-version-2 documents exist. Historical version-1 documents require no rollback transformation.
