# Design

## Context

See `proposal.md` for motivation. Stock Transfer V3 already stores immutable per-allocation source and destination location/business identities, product, condition, quantity, cross-business classification, and receipt lineage in `transfer_movement_allocations`. Its receipt executor completes all allocations atomically and intentionally creates no legacy return obligation.

The production-equivalent local database currently contains two same-business completed V2 transfers and one same-business completed V3 transfer with four matching dispatch and receipt allocations totaling thirteen good units. It contains no cross-business V3 receipts and no completed V3 transfer missing receipt evidence. Deployment therefore requires a safe rehydration path but is expected to start with a zero balance.

Existing V2 tax/return obligations are source-transfer-specific and use different semantics. They must remain isolated from this product-fungible V3 balance capability. Existing stock visibility rules also distinguish route configuration, detailed tax/bucket data, document history, and action authority.

## Goals / Non-Goals

**Goals:**

- Preserve an immutable, explainable ledger and fast directional balance projection.
- Rebalance debt by unordered business pair, product, and stock condition from completed V3 receipts.
- Make receipt inventory and debt posting atomic, idempotent, and concurrency safe.
- Show in-transit effects without treating them as settled debt.
- Fit the existing Laravel module, Eloquent, permission, menu, and focused-test patterns.

**Non-Goals:**

- A return order, dedicated return lifecycle, warehouse recount flow, or special return serial workflow.
- Matching repayment to a particular original transfer or original serial.
- Netting different products, good against broken stock, or balances across different business pairs.
- Changing or importing V1/V2 return obligations.
- Preventing an ordinary transfer from exceeding debt; excess instead creates the reverse balance.
- Running the full automated suite, automated browser tests, Chromium, Playwright, Selenium, or agent-driven UI reproduction. Browser verification belongs to a human developer.

## Decisions

### 1. Use immutable events plus a locked current-balance projection

Add `transfer_business_debt_events` with a unique `receipt_allocation_id` foreign key and `transfer_business_debt_balances` with a unique canonical key of `lower_setting_id`, `higher_setting_id`, `product_id`, and `stock_condition`. Each event snapshots `transfer_id`, `receipt_movement_id`, source/destination and lower/higher setting IDs, product, condition, unsigned-big-integer quantity, signed-big-integer delta, receipt actor, and receipt time. Each balance stores only the canonical key and signed-big-integer current balance. These types match V3's whole-base-unit contract without floating-point conversion.

Use one explicit sign convention everywhere: a positive balance means the higher-ID business owes the lower-ID business; a negative balance means the lower-ID business owes the higher-ID business. A receipt from lower to higher adds quantity, while a receipt from higher to lower subtracts quantity. Delete the projection row when the resulting balance is zero; immutable events remain as the audit record. UI projections always translate the sign into debtor, creditor, and absolute quantity so users never interpret raw signs.

Each event records source and destination business, signed effect, quantity, transfer, receipt movement, receipt allocation, and receipt timestamp. The current table avoids replaying all history for dashboard queries, while events allow reconciliation and complete drill-down.

Alternative considered: calculate balances on every dashboard request directly from receipt allocations. Rejected because it makes concurrency reasoning, idempotency, reconciliation, and high-volume dashboard queries unnecessarily difficult.

Alternative considered: store separate directional rows. Rejected because simultaneous opposite-direction receipt processing could temporarily retain two contradictory rows. A canonical pair with a signed value gives one row to lock and permits direction flips atomically.

### 2. Treat receipt allocations as the only authoritative source

Post debt only from immutable `RECEIPT` allocations belonging to successfully completed V3 receipt execution and marked cross-business. Never infer debt from the transfer header, approval plan, dispatch alone, product totals, or user input. Same-business receipt allocations are ignored.

The debt event is inserted and the canonical balance is locked/updated inside the existing receipt transaction. The event's unique source allocation constraint and the receipt action's existing idempotency ensure at-most-once effects. Process affected balance keys in deterministic order to reduce deadlocks when a multi-route document touches several keys.

Alternative considered: create debt at dispatch. Rejected because goods may be cancelled or never physically received.

### 3. Model automatic rebalancing as signed arithmetic

For canonical businesses `low` and `high`, a source `low` to destination `high` receipt adds a positive delta because `high` then owes `low`; source `high` to destination `low` subtracts quantity. Addition naturally handles accumulation, partial settlement, exact settlement, and excess direction reversal. Delete a zero current-balance row after exact settlement.

Product and stock condition are both part of the key. Serial identity and tax bucket are excluded: serials remain physical movement evidence, while tax classification remains inventory provenance. Decimal/integer storage must align with V3 allocation quantity semantics; do not coerce through floating point.

Alternative considered: FIFO consumption links from repayments to original transfers. Rejected because the agreed business rule does not require fulfillment of a particular transfer. Event order and running balance still provide an audit trail without artificial matching.

### 4. Calculate in-transit projection from immutable dispatch evidence

The dashboard's authoritative balance comes from the locked projection table. Separately aggregate V3 `DISPATCH` allocations whose transfer remains `DISPATCHED` and for which no receipt or cancellation allocation settles the dispatch allocation, applying the same signed arithmetic for display. Do not persist those amounts as debt events or reserve debt capacity. Cancellation removes them automatically from the query; receipt replaces projection with an authoritative event.

Build dashboard rows only from product IDs having at least one nonzero authoritative balance. For those products, projected direction is calculated from each pair-and-condition balance plus matching in-transit deltas because another receipt may alter or flip the balance while a document is in transit. An in-transit allocation for a product with no authoritative debt does not make that product appear in the dashboard.

Alternative considered: reserve or mutate balances at dispatch. Rejected because ordinary transfers are not capped by debt and can legitimately create a reverse balance.

### 5. Rehydrate through an explicit idempotent application command/service

Provide `stock-transfers:rehydrate-business-debt` backed by the same ledger service. It reads eligible completed V3 receipt allocations in stable receipt-movement timestamp and allocation-ID order and posts missing events. `--dry-run` performs a read-only reconciliation and reports eligible allocations, existing events, missing events, and canonical balance keys whose stored value differs from folding all events; it writes nothing. The command without `--dry-run` posts only missing events and then rebuilds only divergent balance keys from immutable events. Unique receipt-allocation identity makes retries safe. It must not reinterpret V1/V2 movements or create records for same-business allocations.

Run rehydration as a reviewed deployment step rather than embedding a potentially large data operation in schema migration DDL. The current production-equivalent snapshot should produce zero events, but the command protects against cross-business receipts created before deployment.

Alternative considered: assume the current zero state and omit rehydration. Rejected because production activity may occur between proposal and rollout.

### 6. Isolate permissions and projections

Register `stockTransfers.view-business-debt`. Following V3's existing active-business permission model, holding it in the current active business allows viewing all cross-business balance rows; membership in either participating business is not additionally required. Grant the new permission automatically only to the existing Admin role, matching the V3 permission-registration convention; every other role requires explicit assignment. Gate the menu, page, data endpoint, and drill-down server-side. Do not introduce an export in this delivery. Balance access does not imply any existing Stock Transfer action, history, route-allocation, or detailed tax/bucket permission.

The dashboard exposes business identities, product, condition, debt quantity, in-transit projection, and debt-event provenance. It does not need raw stock availability or tax buckets. If implementation adds links or embedded document history, those elements must continue to obey their existing permissions rather than inheriting balance access.

### 7. Keep verification focused and leave browser work to humans

Focused automated tests cover migrations/schema constraints, arithmetic direction flips, product/condition isolation, same-business/legacy exclusion, receipt atomicity and replay, concurrency-relevant locking behavior where practical, rehydration idempotency, in-transit projection, permission denial, and permitted response shape.

Use targeted `php artisan test --filter=...` or module-specific test paths. Do not plan or run the full suite. Do not use Chromium or any automated browser framework, and do not ask an implementation agent to reproduce visual/UI behavior. Provide a concise manual browser checklist for the human developer covering menu access, empty state, balance rows, projection, drill-down, and permission denial.

### 8. Use a product-by-debtor matrix and keep selection product-only

Follow the existing Stok Lintas Bisnis table interaction: one sticky row per product and one top-level column per business. The business is always the debtor represented by that column. When collapsed, its cell is the sum of every nonzero balance where that business is debtor for the row product, across all creditor businesses and both stock conditions. Display `0` when it owes none. Expanding the business replaces that total with one subcolumn per other business, where each cell is the amount the expanded debtor owes that creditor for the product, again summed across conditions. Expanded creditor cells must sum to the collapsed debtor total. Condition-specific balances remain separate in the ledger and are available in drill-down provenance, but the matrix intentionally presents combined product totals.

List a product only when at least one authoritative debtor-to-creditor balance is nonzero. Provide one checkbox in the product row, independent of every business cell. Users can select any listed products together; they do not select a debtor, creditor, balance cell, or condition. Require both the dashboard permission and `stockTransfers.create` for the launch action.

Submit selected product IDs to a permission-checked POST action. The server verifies each product is an active stock-managed product currently appearing in the authoritative debt matrix, stores only the deduplicated product IDs under a random one-time key in the authenticated user's session, and redirects to the existing V3 create route with that opaque key. The create form consumes the key once, reauthorizes `stockTransfers.create`, reloads the products, and creates rows at quantity zero. It does not preset or carry a business, route, debt amount, or debt condition; the ordinary V3 form retains its existing condition selection behavior. A missing, reused, foreign-session, malformed, or stale key opens no prefilled rows and returns clear feedback without creating a transfer.

The existing form remains responsible for manual quantities, scanning, serial selection, drafts, and submission. Generalize its V3 draft representation—not only dashboard-prefilled entry—so any selected zero-quantity row is legitimate persisted planning intent, including an all-zero draft, without stock or custody effects. Submission remains stricter than draft save: every retained row must be positive, whole, and—when serialized—equal to its distinct valid serial count. A failed submission preserves the draft and zero rows. The shortcut does not bind allocation routes or guarantee repayment. Only actual approved and received allocation direction changes debt.

Alternative considered: create a special return transfer with debtor/creditor routes fixed from the dashboard. Rejected because it invents a second transfer mode and conflicts with the requirement to reuse ordinary V3.

Alternative considered: group rows by debtor, creditor, or condition and restrict selection to one group. Rejected because the confirmed UI requires one row per product, no business selection, and product-only prefill into ordinary V3.

### 9. Separate approval workspace persistence from executable-plan validation

Treat Simpan Progres as persistence of an editor workspace, not as a pre-approval gate. It accepts structurally storable incomplete rows—including zero quantities, missing source or destination, mismatched totals, same-source-and-destination choices, and currently insufficient stock—and records no inventory reservation or guarantee. This applies to every V3 approval workspace, regardless of how the transfer was created.

Make `transfer_approval_allocations.source_location_id` nullable for saved V3 approval configurations, matching the already-nullable destination. Zero remains valid in the existing unsigned quantity column. Saved rows with neither source nor destination and quantity zero are still retained when they correspond to an explicit workspace row; row order is preserved. These nullable/incomplete records are configuration only and can never become movement allocations.

Retain only invariants needed to store data safely and prevent unauthorized corruption: permission, matching transfer and manifest revision, optimistic configuration revision, supported scalar types, and valid foreign-key identities when supplied. Do not run completeness, aggregate-total, distinct-route, serial fulfillment, or live-stock sufficiency checks on progress save.

Setujui dan Kirim remains the sole strict execution boundary. It reloads the manifest and saved configuration under locks, rejects zero/incomplete/invalid allocations, validates exact totals, routes, serials, and aggregate live stock, and dispatches atomically only when the entire plan passes.

Alternative considered: partially validate executable rules during progress save. Rejected because it prevents approvers from saving naturally incomplete work and duplicates checks that must run again against locked live state at approval.

## Risks / Trade-offs

- [Concurrent receipts could lose a balance update] → Create-or-lock one canonical balance row per key in deterministic order, rely on the canonical unique constraint to resolve simultaneous first creation, and retry only the recognized duplicate/deadlock transaction case.
- [Event and balance projection could diverge] → Commit both atomically, enforce unique source receipt allocation, and provide a read-only reconciliation mode in the rehydration/service tooling.
- [Signed direction could be interpreted inconsistently] → Centralize canonical-pair normalization and direction projection in one domain service with symmetric focused tests.
- [New cross-business receipts occur before rollout] → Run the idempotent rehydration operation immediately before/after activation as appropriate and compare eligible allocation/event counts.
- [Dashboard could expose unrelated protected transfer data] → Use a purpose-built projection and dedicated permission; do not serialize full movement, allocation-stock snapshot, tax, or history models.
- [In-transit totals may differ from eventual balances] → Label them as projected, derive them live from dispatched evidence, and preserve receipt as the sole authoritative boundary.
- [Condition may rarely be broken across businesses] → Retain condition in the key without adding a prohibition or implying an operational rule.
- [Users may assume dashboard launch guarantees repayment] → Label it as a product-only transfer shortcut and state that no displayed business, quantity, or condition is carried into V3; actual receipt allocations authoritatively determine rebalancing.
- [Forged prefill could expose or add arbitrary products] → Carry minimal server-owned selection state and reauthorize/reload every product when mounting the existing form.
- [Zero-quantity drafts could leak into approval or allocation] → Separate draft validation from submission validation and require every submitted row to be positive before freezing a request revision.
- [Unvalidated progress could be mistaken for approved allocation] → Keep the document pending, label saved state as incomplete workspace data, reserve nothing, and run all executable-plan validation only at final approval.

## Migration Plan

1. Add ledger-event and canonical current-balance tables with foreign keys, integer quantity types, source-allocation uniqueness, and dashboard indexes; also make V3 approval-allocation source nullable for incomplete progress. Keep migrations compatible with MySQL and focused SQLite tests.
2. Deploy ledger domain services, permission registration, dashboard projections, validated create-form prefill adapter, rehydration command, and receipt integration together; no runtime feature flag is required.
3. Integrate debt posting into the V3 receipt transaction and run focused service/feature tests only.
4. Run the idempotent rehydration command against the production-equivalent database and confirm that the known snapshot produces zero events/balances; at production deployment, capture and review the actual counts in case activity occurred since the snapshot.
5. Have a human developer perform the supplied browser checklist. Implementation agents must not perform browser automation or UI reproduction.
6. Activate the dashboard/menu and monitor receipt failures and ledger reconciliation.

Rollback deploys the previous application code while retaining the additive ledger and nullable-allocation schema. Do not drop populated ledger data automatically. Receipts completed while the previous code is active will have no event; after a forward redeploy, run the dry-run reconciliation and then the idempotent rehydration command to restore them.
