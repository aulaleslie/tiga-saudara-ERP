## 1. Additive HPP Persistence

- [x] 1.1 Add SQLite/MySQL-compatible nullable HPP snapshot, source-setting, PKP-state, source-label, and timestamp columns to `sale_bundle_items`, with rollback-safe indexes or foreign-key handling.
- [x] 1.2 Add immutable return-cost quantity, total, source metadata, and parent-versus-component origin identity to the execution-aligned `sale_return_details` persistence.
- [x] 1.3 Extend `SaleBundleItem` and `SaleReturnDetail` casts/relationships and add focused migration/model persistence tests for decimal precision, null legacy rows, and source metadata.

## 2. Owner-Aware Cost Resolution

- [x] 2.1 Implement a shared average-cost resolver that prefers the physical stock owner's positive average purchase price and excludes zero, null, negative, blank, and non-finite candidates.
- [x] 2.2 Implement deterministic same-PKP then opposite-PKP fallback using enabled sales-location position, earliest position per setting, setting-ID tie breaks, and deterministic remaining-setting fallback.
- [x] 2.3 Return auditable selected setting, PKP state, source label, unit cost, and missing status without using informational sale price or `last_purchase_price`.
- [x] 2.4 Add focused resolver tests for owner wins, non-PKP-to-non-PKP fallback, PKP-to-PKP fallback, opposite-class fallback, duplicate locations, ties, remaining settings, and complete absence.

## 3. Normal Sales Bundle Snapshots

- [x] 3.1 Extend the Sale cost snapshot service to snapshot physical parent and component rows independently while preserving verified non-stock zero and explicit missing-average zero semantics.
- [x] 3.2 Snapshot Normal Sales bundle components at create/update using the Sale/dispatch owner and already-expanded component quantity without changing commercial bundle pricing.
- [x] 3.3 Surface a non-blocking post-persistence warning for stock-managed parent or component rows whose fallback chain has no positive average.
- [x] 3.4 Add focused Normal Sales tests for non-stock parent with stock components, stock parent alone, stock parent with stock/non-stock add-ons, multi-quantity expansion, missing cost, and immutable snapshots after average-price changes.

## 4. POS Split-Owner Bundle Snapshots

- [x] 4.1 Pass explicit physical owner, fulfilled quantity, and parent-fulfillment classification from POS split planning/posting into cost snapshotting.
- [x] 4.2 Persist `NOT_FULFILLED_BY_GROUP` zero parent cost for component-only owner groups and prevent the parent product's model classification from overriding that posting fact.
- [x] 4.3 Persist each owner group's component HPP from its stock-owner average/fallback while retaining POS-owner informational prices solely for revenue allocation.
- [x] 4.4 Return structured non-blocking missing-HPP warnings from successful POS finalization and preserve retry/idempotency behavior.
- [x] 4.5 Add focused POS tests proving split owner selection, component-only zero parent HPP, parent-plus-component exact-once costs, missing fallback warnings, and retry without duplicate snapshots.

## 5. Shared Report Aggregation

- [x] 5.1 Build a shared per-Sale/per-period net HPP query that independently aggregates parent snapshots, component snapshots, and effective return reversals before combining them.
- [x] 5.2 Integrate the shared aggregate into operational profit/loss and opening/period movement events without adding component revenue a second time.
- [x] 5.3 Verify general ledger, trial balance, and balance-sheet earnings inherit the same net HPP result through existing service composition.
- [x] 5.4 Add focused report tests for the canonical `4,530,000` HPP and `1,020,000` gross profit fixture, multiple components on one parent, component-only POS groups, setting/date scope, and no SQL row multiplication.

## 6. Return Reversal and Replacement HPP

- [x] 6.1 Extend POS Return approval planning/persistence to copy original parent and component unit snapshots and physical quantities into immutable return-cost effects.
- [x] 6.2 Make only effective received/final-approved return details reduce recognized HPP; exclude draft, warning-blocked, rejected, cancelled, and rolled-back work.
- [x] 6.3 Stop report HPP from relying on mutable Sale quantities as its sole return mechanism while preserving current commercial correction and eligibility behavior.
- [x] 6.4 Snapshot replacement-dispatch outgoing HPP independently from the replacement owner/current average resolver and leave components unchanged for parent-only bundle replacement.
- [x] 6.5 Add focused tests for partial bundle cash reversal, changed current averages, rejection/rollback, parent-only replacement, standard-versus-POS return parity, and idempotent approval/replacement retries.

## 7. Historical Backfill and Replay Compatibility

- [x] 7.1 Extend dry-run-first Sales cost backfill/replay to identify and cost deterministic historical bundle-component rows separately from parent Sale details.
- [x] 7.2 Preserve authoritative `HPP_SNAPSHOT_IMPORT` parent snapshots, existing force/non-force semantics, and correction-replay protection without aggregating component cost into parents.
- [x] 7.3 Report ambiguous legacy component owner/dispatch lineage as skipped warnings with actionable Sale/detail/bundle-item/product identifiers instead of guessing.
- [x] 7.4 Add focused backfill tests for deterministic component replay, ambiguous skips, imported parent preservation, bounded writes, and repeat-run determinism.

## 8. Focused Verification

- [x] 8.1 Run the touched migration/model, resolver, Normal Sales, POS split bundle, report aggregation, POS Return lifecycle, and cost-backfill test files only.
- [x] 8.2 Reconcile the canonical split-owner fixture across persisted snapshots, profit/loss, operational movements, partial return, and replacement outcomes, recording any intentionally unsupported legacy rows.
