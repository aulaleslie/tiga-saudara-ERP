# Design

## Context

See proposal.md for the business outcome. This is Laravel 10 with Livewire 3, nwidart modules, Eloquent and Spatie permissions. The implementation belongs in Modules/Adjustment and the existing app/Livewire/Transfer entry surfaces.

Reviewed integration points:
- TransferDraftService and TransferStockForm currently require an origin before goods entry; TransferScanResolverService also accepts one authorized origin.
- Transfer::nextDocumentNumber requires origin_location_id. MySQL currently makes that column non-null, and document uniqueness includes origin.
- ForwardDispatchApprovalExecutor and ForwardReceiptApprovalExecutor require workflow version 2 and equality between movement route and transfer header. They cannot simply be called with multiple source locations.
- TransferMovementDocumentService.cancel accepts DRAFT only. It is not a stock reversal.
- transfer_active_serial_claims provides database-enforced exclusive live serial claims used by competing inventory flows.
- TransferActionHistory and TransferMovementHistory already record actors, revisions, reasons, metadata and idempotency identities.
- CheckUserRoleForSetting selects the current business role; Gate supplies the existing Super Admin bypass.
- TransferRoutePolicyResolver currently couples cross-business classification with mandatory return. Receipt executors create obligations from that flag.
- phpunit.xml targets isolated in-memory SQLite and includes the Adjustment test suite.

Read-only replica inspection on 2026-09-23 found two completed v2 transfers, four movements, no active serial claims and no return obligations/reservations. This is evidence about that snapshot only; rollout must preserve other legacy states too. No production-replica mutation is part of implementation verification.

## Goals / Non-Goals

Goals:
- Separate immutable goods intent, editable approval routing, and immutable executed allocations.
- Execute approval/dispatch, receipt, and cancellation atomically across every allocation.
- Keep permission and version checks at all entry points, including client-state projections.
- Preserve existing documents and shared serial-availability behavior.

Non-goals:
- Receiving scans, blind counts, recount evidence, discrepancies, partial receipts, or separate receipt approval.
- Automatic v3 return obligations, return dispatch, sophisticated fraud investigation, or reversal after completed receipt.
- Automatic progress saves, separate dispatch preparation, allocation changes after dispatch, or historical data repair.
- Full-suite test execution or agent-driven browser testing.

## Decisions

### 1. Persist v3 on the existing transfer aggregate and isolate executors

Keep transfers as the list/detail identity, with workflow_version=3 assigned at creation behind a new creation activation setting. Existing records keep their versions. New services implement v3 orchestration; legacy executors retain their v1/v2 guards. Do not broadly change equality checks from version 2 to version >=2.

Use DRAFT -> PENDING -> DISPATCHED -> COMPLETED or CANCELLED. Rejection uses REJECTED with explicit acknowledgement/revision back to DRAFT. Allocation progress is a pending-document configuration revision, not a stock-moving status. Editing pending goods invalidates the approval configuration and returns to DRAFT. Submitted goods evidence remains available in immutable revision snapshots.

Alternative rejected: redefining v2 or upgrading old drafts silently would change historical authorization, receiving, and return semantics.

### 2. Add explicit allocation and execution data

Proposed schema changes, owned by new Adjustment migrations:
- transfers: nullable origin_location_id for v3 only; add nullable created_in_setting_id, approval_configuration_revision, cancellation actor/time/reason and any stable operation keys needed for v3. Existing origin/destination fields remain intact for legacy rows; new v3 headers never store an arbitrary representative route.
- transfer_request_revisions: immutable submitted goods snapshot per transfer/revision, including condition, canonical quantities and selected serial identities. Unique transfer/revision identity.
- transfer_approval_allocations: product/request-line reference, request revision, source location, nullable destination, quantity, and plan revision. Incomplete destinations are valid only while saving progress.
- transfer_approval_allocation_serials: selected serial assignments; enforce one assignment per serial per active plan and validate product/condition/source.
- transfer_movement_allocations: immutable applied route evidence linked to the movement and aggregate product line; includes source and destination business/location IDs, cross-business flag, source bucket deltas, destination classification and tax snapshot, stock snapshots and transaction references. Receipt and cancellation entries reference exact dispatch allocations.
- Retain existing transfer_movements, aggregate product movement lines, transfer_movement_serials and active claims. A v3 aggregate movement has nullable header locations and per-allocation operational routes. Relax required movement locations only as necessary; enforce non-null operational routes for legacy movements in their existing services.
- Add the allocation identity to movement serial rows where needed so each serial has one exact source/destination route; existing serial route fields and claims remain usable by other modules.

Keep one forward-dispatch aggregate and one confirmed forward-receipt aggregate per new document. Add a dispatch-cancellation movement/action type referencing the dispatch. Do not overload return_batch_id or create fake blind-count observations. Frozen manifests and histories must describe approval-generated dispatch and confirmation-generated receipt truthfully.

The aggregate movement approach preserves current serial-claim foreign keys and singleton movement lineage, while per-allocation rows handle repeated products across locations. Alternative rejected: multiple independent transfer headers would fragment one user document and make one receipt/cancellation harder to enforce.

### 3. Number documents without a source

Allocate a globally unique new-workflow reference with a distinct prefix such as TSM-YYYY-MM-NNNN using a transactionally locked sequence in the existing sequence infrastructure, extending it if needed. Persist a nullable unique v3 reference key (equal to the displayed reference for v3, null for legacy) so uniqueness never depends on MySQL's nullable origin composite key. Keep historic TS references unchanged. Record the creating active business for audit, not as a route or stock owner.

### 4. Separate intent validation from stock allocation

For v3, reuse canonical barcode resolution, ambiguity handling, conversion normalization, FIFO scan coordination and serial availability predicates, but provide cross-business discovery without the legacy origin prerequisite. Expose product identity and operator-entered intent only. No implicit first match when cross-business discovery creates identifier collisions.

Non-serialized creation records positive whole quantity intent; source sufficiency is an approval concern. Serialized draft saves may retain a count mismatch; submission validates exact distinct serial count and current canonical eligibility. Recheck selected serial identity, product, condition, reservation, return state and custody at final approval. A changed source location requires review, not silently rerouting an approved summary.

### 5. Manual allocation saves and immutable reviewed summaries

Serialized rows group the submitted serials by product and live source; one destination applies to each source group. Non-serialized rows allow several positive source allocations, including repeated sources for different destinations, with aggregate stock validation per product/source/condition.

Simpan Progres persists incomplete configuration with optimistic configuration-version checks and event metadata. It makes no reservation. Approval is bound to the request revision and configuration revision displayed in the modal. The server rereads stored intent; client values never supply authority for stock, tax or comparison.

Show source/destination, quantities, serial groups, condition and cross-business marking in the modal. A changed plan or serial source requires refreshed review. Current bucket allocation is authoritative at commit and follows non-tax-first rules; bucket provenance is not editable user routing.

### 6. Use a complete permission matrix

| Surface/action | Required existing or new permission |
| --- | --- |
| Discover v3 documents | stockTransfers.access |
| Detail | stockTransfers.show |
| Create / atomic create-submit | stockTransfers.create |
| Edit / submit existing draft | stockTransfers.edit |
| Allocation workspace / save / approve / reject | stockTransfers.approval |
| Confirm receipt | stockTransfers.receive |
| Cancel dispatched document | stockTransfers.cancel-dispatch (new) |
| View event timeline | stockTransfers.show + stockTransfers.view-history (new) |

Self-approval/receiving is permitted. Evaluate action permissions using the active business; do not require memberships in route businesses. Do not change application-wide business switching or role rules. Required detail redirects use the normal show boundary; deployment role configuration must explicitly include show for operators who create/edit/submit. Do not silently grant show, allocation or history access through a successful mutation. Verify ordinary redirects with representative configured roles and direct denial without show.

Proposed discovery default: v3 documents are globally discoverable with access in the active business, while legacy list scope remains unchanged. This follows cross-business operation intent and is explicitly reviewable in the proposal. Route configuration is visible only with approval permission, in the approval workspace/summary. Approval grants source totals needed to allocate; detailed tax/bucket diagnostics additionally require view-system-stock. Stock visibility alone must never disclose v3 routes.

History permission grants sanitized events, not allocation configuration. Show actors/times/action/reason; remove protected structured metadata before serialization. Free-text reasons should not automatically include generated route/stock diagnostics. Apply projection rules to HTML, Livewire state, JSON, exports, validation and direct endpoints.

### 7. Approval dispatches atomically with shared inventory rules

Within one transaction: lock transfer and plan; check reviewed revisions; lock products, stock rows and serials in a deterministic order; aggregate demands by product/source/condition; resolve immutable per-allocation policy; validate all serials and claim uniqueness; deduct exact good/broken buckets; adjust product totals; create dispatch allocations, serial claims, inventory transactions and histories; record approval/dispatch events; set DISPATCHED.

Reuse or extract pure inventory allocation/snapshot helpers from existing services without changing legacy behavior. Do not call the v2 executor through fabricated header locations or temporary session business changes. Query route owners explicitly and preserve product catalogue independence.

Idempotency is scoped to transfer/revision/action and committed atomically. A repeated committed action returns its recorded outcome; a conflicting action reports current state without effects.

### 8. Confirmation receives the full immutable plan

The detail shows goods and quantities, no routes. Terima Barang opens the agreed Bahasa Indonesia confirmation. On confirmation, receiving permission and locked DISPATCHED status authorize full posting without receive.approval.

Read only immutable dispatch allocations and policy snapshots, apply destination quantities, adjust product totals, move exact live serials to their assigned destinations, apply snapshotted tax classification, close custody/remove claims, persist receipt evidence, append receipt/completion events, then set COMPLETED in one transaction. Client quantity/location overrides are rejected. No receipt-count drafts or mismatch machinery is invoked.

One document can target several internal locations while representing one physical confirmation. Physical completeness is the receiver's declaration; the system does not claim to have independently checked a count.

### 9. Cancellation adds compensating deltas

Require cancel-dispatch, nonempty reason and explicit confirmation:
"Pastikan seluruh barang belum diserahkan atau telah dikembalikan ke lokasi asal sebelum membatalkan pengiriman. Stok akan dikembalikan ke lokasi asal sesuai pengiriman."

Lock the same transfer row used by receiving, require DISPATCHED, verify immutable source transactions and serial claims, and add exact recorded source bucket deltas to current source balances. Restore global quantities correspondingly. Preserve original serial source/tax/condition, close custody as cancelled and release matching claims. Append compensating transactions and cancellation evidence; never delete dispatch or overwrite current balances with historical snapshots.

No physical-return workflow is implied by this action. Cancelled documents cannot be received or reused. An unexpected serial claim or live-state conflict blocks the entire reversal for investigation.

### 10. Keep tax classification and future-return provenance separate

Preserve same-business source bucket classification. For cross-business destinations retain the current PKP TAX / non-PKP NON_TAX rules and deterministic configured/default/fallback tax resolution, verified against actual tax ownership conventions in the code. Snapshot per route; do not let later settings changes reinterpret it.

Record cross_business independently from mandatory_return. Version 3 does not create obligations and completes on receipt. Legacy routes and returns remain intact. Cancellation restores the original source allocation regardless of later tax changes.

### 11. Evidence and verification

Extend existing transfer and movement histories rather than a separate audit product. Store action actor, active business, revision, timestamp, operation identity and links to frozen request/plan/execution records. Emit successful events only within the transaction that succeeds. Hiding history must not disable recording.

Focused tests use isolated test databases: schema compatibility, location-free entry, cross-business permission matrix and payload omission, allocation totals/staleness, mixed good/broken and serial/non-serial operations, idempotency/rollback, receipt/cancel mutual exclusion, exact reversal after intervening stock changes and representative legacy compatibility. SQLite cannot prove MySQL row-lock races; use a targeted race check on a disposable MySQL test database if available, otherwise report that verification limit explicitly.

Deliver a manual browser checklist for the human developer. Do not run or plan the full application suite or automated browser tooling.

## Risks / Trade-offs

- [Multi-route posting increases locking complexity] -> Lock aggregate and affected inventory in a stable order, aggregate shared source demand and roll back the whole document on failure.
- [Existing consumers assume every transfer/movement has header locations] -> Audit serializers, relationships, list queries, numbering, history and reports for version branches; test v3 with strict lazy loading and legacy fixtures.
- [Cancellation races with receipt] -> Shared aggregate row lock, terminal guards and action idempotency; no separate asynchronous posting.
- [Broad product discovery creates more barcode collisions] -> Canonical server ambiguity handling without exposing route locations during entry.
- [Stock changes while progress is saved] -> No reservation guarantee; refresh affected allocations and revalidate at commit.
- [Global discovery broadens visibility] -> Clearly documented proposed default, action permissions, sanitized goods/detail projection and separate history/route controls.
- [Old binary cannot safely process v3] -> Rollback disables new creation while retaining v3-capable readers/executors; no downgrade of populated schema.
- [Confirmation records no physical count evidence] -> Use explicit user confirmation and actor/time history; no mismatch/fraud claims.

## Migration Plan

1. Add nullable/additive schema and isolated migration compatibility checks; never run destructive test commands against the replica.
2. Introduce v3 readers, policy/projection boundaries, and services while creation activation is off; keep legacy actions guarded.
3. Register the two new permissions through existing permission synchronization, without auto-granting them to unrelated roles.
4. Run focused automated verification and provide the human browser checklist. Human developer verifies scanning, searchable allocation selectors, saves/modals, receipt, cancellation, history and legacy rendering.
5. Before production activation, take a normal backup and record a read-only lifecycle distribution; this is a deployment instruction, not authorization for this planning turn to modify production.
6. Enable v3 for new creation after review. Monitor action failures through existing logs.
7. Roll back by disabling v3 creation while keeping deployed support for already-created v3 documents. Do not drop populated allocation/evidence tables or reclassify existing documents.
