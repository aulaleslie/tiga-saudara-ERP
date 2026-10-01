# Design

## Context

See `proposal.md` for motivation. Receiving approval currently lives in `PurchaseController`, posts stock and price effects directly, creates one `BUY` transaction per positive `ReceivedNoteDetail`, attaches serials, and derives Purchase status from `APPROVED` receivals. Newer details have durable transaction provenance; legacy details require conservative resolution. Full Purchase edits delete and recreate `purchase_details`, whose current cascade relationship can erase dependent receiving details.

The change crosses Purchase lifecycle, Product inventory, serial lineage, cost replay, notifications, reports, permissions, and historical persistence. Existing quantity and reporting logic already keys effective receipt evidence on exact `APPROVED` status, which permits `CANCELLED` to be terminal and ineffective without destructive deletion.

## Goals / Non-Goals

**Goals:**

- Make the Purchase the unit of cancellation: one action cancels every `APPROVED` and `PENDING` receival of the Purchase, all-or-nothing.
- Preserve an immutable, navigable chain from original receipt through reversal.
- Reject any reversal that cannot be proven exact at the original location, bucket, serial lineage, and transaction provenance.
- Let an `APPROVED` Purchase become fully editable after effective receipts and pending receivals are cancelled, without losing historical detail.
- Keep the mutation atomic and safe under concurrent approval, editing, completion, return, and duplicate cancellation requests.

**Non-Goals:**

- Cancelling an individual receival, or partial line or quantity cancellation.
- Cancellation of consignment receiving or supplier-shortfall-completed purchases.
- Changing Purchase monetary values or payments.
- Automatically relocating stock back to the original receipt location.
- Reactivating a cancelled receival; corrections use a new receival.
- Running the complete repository test suite as part of this change's planned verification.

## Decisions

### 1. Represent cancellation as a terminal status plus immutable reversal records

Add `CANCELLED` to `ReceivedNote`, with convenient cancellation metadata on the note and immutable cancellation records for provenance. Use a `received_note_cancellations` header for note, Purchase, setting, previous status, origin (`MANUAL_APPROVED` for previously approved receivals and `MANUAL_PENDING` for previously pending ones; `AUTO_PURCHASE_REOPEN` remains a reserved schema value and is not written by the purchase-level action), actor, reason, and timestamp. Use cancellation lines for source detail snapshot, original `BUY` transaction, compensating transaction, and reversed bucket quantities.

One header is written per cancelled receival (a purchase-level action writes several headers sharing actor, reason, and timestamp). The unique header per receival makes submission idempotent and gives pending cancellations an audit record even though they have no inventory lines. Original notes, details, transactions, pivots, and serial history remain unchanged.

Alternative considered: overwrite or delete original `BUY` transactions. Rejected because reports and audit trails would lose the historical event and legacy reconstruction would become harder.

### 2. Extract cancellation into a dedicated transactional service

Keep the controller thin and execute preview/eligibility and cancellation through dedicated services. The mutation service will begin from locked authoritative data and use the established ordering: Purchase, receival headers, receiving and Purchase details, dependency evidence, products and location stock, transactions, then serials and claims. It revalidates every preview assumption at submission.

The service owns status changes, pending-note cancellation, reversal transactions, serial changes/history, notification resolution, cost reconciliation, and Purchase transition audit in one database transaction. The route and service both enforce `purchases.receive.cancel` and active-setting ownership.

Alternative considered: mirror approval logic in another controller method. Rejected because approval is already large and cancellation needs reusable eligibility checks and focused unit/feature testing.

### 3. Reverse exact evidence, never generic available stock

For each positive detail, resolve its single source `BUY` transaction. Prefer the persisted one-to-one link; use `LegacyTransactionResolver` only for old rows and reject absent or ambiguous results. Aggregate required quantities by product, location, and tax identity before mutation and require sufficient quantities in every exact bucket. Create a negative transaction type such as `PURCHASE_RECEIVING_CANCELLED` with durable cancellation-line linkage and full before/after values.

Do not take quantity from another location, cross tax buckets, consume broken stock, or permit a negative bucket. This means a non-serialized item that has been sold, transferred, returned, or adjusted may block cancellation through insufficient stock even when its individual units cannot be traced.

Alternative considered: permit negative stock so documentary correction always succeeds. Rejected because it hides downstream consumption and breaks the repository's location/bucket inventory invariants.

### 4. Validate serials by provenance and current availability

Resolve serials through the receiving-detail pivot and its source history rather than the legacy single foreign key alone. Lock all implicated serials in ID order. Every serial must be `ACTIVE`, at the receipt location, match the receipt tax identity, have the selected receipt as its authoritative latest receiving provenance, and have no dispatch, transfer, return, or other active claim.

Successful cancellation assigns `RECEIVING_CANCELLED` and appends a `PURCHASE_RECEIVING_CANCELLED` history event referencing the cancellation line. It retains original pivots and `RECEIVED` history. Any unavailable serial, including `SOLD`, blocks the whole operation.

Alternative considered: only block `SOLD`. Rejected because moved, returned, broken, missing, or reused serials are equally unsafe to remove from original-location stock.

### 5. Cancel at Purchase level, all-or-nothing, and return the Purchase to `APPROVED`

The only entry point is the Purchase: preview and submission take a Purchase, never a receival ID. The action covers every receival currently `APPROVED` or `PENDING`; `REJECTED` and already `CANCELLED` receivals are untouched history. Eligibility is evaluated for all of them before any write, and stock requirements are aggregated across all approved receivals per product, location, and tax bucket, so two receivals that each pass alone cannot jointly overdraw a bucket. Any blocker on any receival rejects the whole action.

Because every effective receival is cancelled, the Purchase always becomes `APPROVED` (a transition audit is recorded when the status changes), and it is again eligible for full editing. Pending receivals are cancelled as part of the same explicit user action (origin `MANUAL_PENDING`) and their approval notifications are resolved.

Separately, add a full-edit boundary check that rejects any Purchase with pending notes.

Alternative considered: cancel individual receivals and re-derive `RECEIVED PARTIALLY`/`RECEIVED`. Rejected after review: operators correct a Purchase as a whole, and per-receival cancellation left pending receivals built on the pre-edit line structure and required a per-row decision the business does not want.

### 6. Preserve historical lines independently of mutable Purchase details

Add immutable snapshot fields for product ID/code/name, canonical quantity, entered quantity and UOM/conversion identity, tax identity, location, note, and serial identifiers. Backfill these from current relationships for existing notes. Change the historical detail-to-Purchase-detail relationship so later Purchase-line replacement cannot cascade-delete receiving history, using a nullable foreign key with `SET NULL` after snapshot backfill.

History views use live relations when present and snapshots as the durable fallback. Cancelled details never become candidates for new approval or receiving.

Alternative considered: prevent all later Purchase line replacement. Rejected because the agreed workflow explicitly reopens the Purchase for full editing after cancellation.

### 7. Reconcile costs inside the cancellation transaction

Use the existing historical cost replay facilities from the earliest cancelled receipt effect forward. Recompute current last and average purchase price from remaining effective evidence and replay dependent cost snapshots as supported by the existing engine. Treat failure as cancellation failure so stock and cost cannot diverge.

Purchase document values and payment rows are untouched. The cancellation event uses its actual cancellation timestamp; the original receipt and `BUY` event retain their dates.

Alternative considered: defer cost replay to a manual follow-up. Rejected because a successfully cancelled receipt would leave knowingly incorrect current cost state.

### 8. Keep reporting additive and status-aware

Inventory mutation reports retain the positive `BUY` and show the new negative reversal on its event date. Purchase delivery, completion, and quantity services continue to count exact `APPROVED` notes only. Receival history on the Purchase detail page adds status, cancellation metadata, and origin; the purchase-level action and aggregate preview live on that page, not in the global receivals list. Modal hooks use CoreUI 3 native events (`show.coreui.modal`), not Bootstrap `*.bs.modal` events. Preview and submission return actionable blockers without exposing cross-setting data.

### 9. Verification is focused by risk boundary

Verification will use focused Laravel feature/service tests covering permissions and tenancy, full-document enforcement, exact bucket reversal, serial blockers and success, dependency/legacy blockers, status derivation, automatic pending cancellation, edit-history survival, cost rollback, idempotency, and representative concurrency. Run those test files or focused filters only; do not add a task for `composer test:fresh-sqlite` or the full repository suite.

## Risks / Trade-offs

- **[Historical rows lack complete snapshots]** → Backfill conservatively before changing foreign-key behavior; reject cancellation when material provenance remains ambiguous.
- **[Synchronous cost replay can make cancellation slow]** → Preview scope, lock narrowly and deterministically, and retain atomic correctness over background eventual consistency.
- **[Current approval logic is controller-heavy]** → Isolate new cancellation behavior in services and share only stable quantity/provenance helpers rather than expanding the controller further.
- **[Stock may be sufficient numerically but not derived from the receipt]** → For non-serialized goods exact unit lineage is unavailable; exact location/bucket sufficiency is the conservative enforceable boundary and is disclosed in preview.
- **[Changing cascade behavior affects existing edit assumptions]** → Backfill snapshots, use nullable historical links, and add focused regression coverage for editing and history rendering.
- **[Deadlocks across broad mutation scope]** → Follow one lock order, sort IDs, keep external delivery after commit, and add a representative competing-operation test.

## Migration Plan

1. Add cancellation status/metadata, immutable detail snapshots, cancellation header/line tables, reversal provenance, serial status/history constants, and the new permission.
2. Backfill detail snapshots and source transaction references where uniquely resolvable.
3. Replace cascade deletion of receiving details with a nullable, history-preserving Purchase-detail link only after snapshot verification.
4. Deploy service, routes, UI, quantity/report filters, edit guard, and focused tests together so `CANCELLED` cannot be created without complete behavior.
5. Rollback code may stop new cancellations, but migrations containing committed audit evidence must not destructively remove cancellation records or restore reversed stock automatically. Operational rollback of a completed cancellation is a new receival, not database down-migration semantics.
