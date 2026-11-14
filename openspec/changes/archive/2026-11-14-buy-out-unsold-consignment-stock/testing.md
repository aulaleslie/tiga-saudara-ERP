# Buyout testing, deployment and rollback notes

## Schema (group 1)

Migrations (Consignment module):

1. `2026_11_10_000001_create_consignment_buyout_tables` – buyout header/lines/serials, append-only audit events, sold-receipt attributions, reservations.
2. `2026_11_10_000002_create_consignment_buyout_posting_evidence_tables` – Purchase lineage, signed movements, cost events, ownership events, reversals, shared serial-claim owner columns, `(setting_id, product_id)` coordination rows.

Both are additive. Legacy claim rows get `owner_type = 'LEGACY'`; the legacy confirmation/allocation columns on `consignment_active_serial_claims` become nullable (their FKs are untouched) so buyout-owned claims can share the `uniq_casc_serial` exclusivity key.

## Deployment order

1. Run migrations; ship permission definitions without granting unsold acquisition.
2. Deploy attribution/capacity/lock services, then reservation-aware consumers (Sales dispatch, POS, transfer, adjustment, returns, purchase receiving).
3. Only then expose buyout lifecycle and finalization. Never expose finalization while any consumer ignores buyout claims.

## Rollback

`down()` of both migrations refuses (before any DDL) when any buyout, line, serial, audit, attribution, reservation, lineage, movement, cost, ownership, reversal, or buyout-owned claim row exists. With evidence present: disable buyout actions and forward-fix or perform an audited reversal. Rollback on an empty schema must be done migration 2 then migration 1 (framework default). On SQLite the empty-schema `down()` cannot drop foreign keys; use MySQL for that path.

## Verification

- `php artisan test Modules/Consignment/Tests/Unit/ConsignmentBuyoutSchemaTest.php`
- Concurrent coordination-row creation requires the isolated MySQL runner (not yet verified; SQLite cannot prove it).

## Schema invariants (review fixes)

- `consignment_active_serial_claims` must have exactly one complete owner: `LEGACY` (confirmation + allocation, no buyout ids) or `BUYOUT` (buyout + buyout serial, no legacy ids). Enforced by a CHECK constraint on MySQL and equivalent triggers on SQLite; parent/child consistency (allocation/buyout serial matches the claim's serial and parent) is enforced in the model.
- Nullable-key uniqueness uses stored non-null mirror columns (`sold_source_key`, `serial_key`), so unsold slices and non-serialized ownership events cannot be duplicated.
- `ConsignmentInventoryCoordination::lockFor/lockMany` throw outside an active transaction.

## Capacity equations (group 2)

Implemented in `ConsignmentBuyoutCapacityService`; quantities compared as integer thousandths, inconsistent ledgers reported as `conservation_errors` (never clamped).

- financial_available(r) = received − legacy approved − legacy waiting − buyout finalized − buyout active receipt reservations
- physical_residual(r) = received − net attributed sold − finalized unsold acquisitions
- unsold_available(r) = physical_residual − active unsold reservations; pool-level `physically_available` is further capped by sellable stock minus active PHYSICAL_BUCKET reservations and is 0 when sellable stock < supplier-owned residual (ambiguous moved/damaged stock).
- sold_available(s) = original − effective return − legacy approved/waiting − buyout finalized − buyout active reservations
- Example: A received 10, B received 8, 6 sold → attribute 4→A, 2→B: A residual 6, B residual 6, B's financial availability stays 8 (attribution creates no bill). Covered by `ConsignmentBuyoutCapacityTest`.
- Effective returns: serialized sources use exact serials; a single-receipt non-serialized source auto-apportions; multi-receipt sources need explicit `returned_base_quantity` or the pool is blocked.

## Lifecycle and reservations (group 4, domain level)

`ConsignmentBuyoutLifecycleService` implements draft/revise/submit/reject/approve/cancel with canonical revision hashes (compare via `approvedHashIsCurrent`; BaseModel upper-cases stored strings), approval-time reservations (RECEIPT, SOLD_SOURCE, PHYSICAL_BUCKET, SERIAL + shared serial claims), append-only audit, and composed permissions (`consignments.buyouts.*`, unsold acquisition authority, plus `consignments.allocations.approve` for approval). **No route, controller or UI exposes these actions yet**; finalization/reversal are not implemented. Notifications (4.5) and HTTP-level 403 handling (4.3) are pending with group 9.

## Participant / lock-order matrix (status)

Protocol: `ConsignmentInventoryCoordination` rows (sorted by setting, product) -> document ancestry -> locations -> products -> stock -> sold sources/claims -> serials. Authoritative availability: `ConsignmentBuyoutReservationGuard`.

| Participant | Coordination lock | Reservation-aware | Real-operation test |
| --- | --- | --- | --- |
| Buyout approve / cancel | yes | n/a (owner) | ConsignmentBuyoutLifecycleServiceTest |
| Legacy confirmation submit / approve | yes | yes (pools, sold eligibility, cross-line capacity) | ConsignmentBuyoutCapacityTest |
| Sales dispatch approval / rejection | yes / n.a. | yes (bucket quantity + serial claims) | ConsignmentBuyoutDispatchReservationTest (quantity, claimed serial, cancel release) |
| POS checkout | yes (incl. bundle components, cross-owner sources) | yes | ConsignmentBuyoutPosReservationTest, ConsignmentBuyoutPosBundleReservationTest (bundle + split-owner: full rollback on contention, partial use, release, coordination rows for owner business + every consumed product) |
| Transfer v3 approve-and-dispatch | yes | yes (plans against reservation-excluded stock; claimed serials refused) | TransferV3BuyoutReservationTest |
| Stock opname approval | yes (missing products rejected before mutation) | existing consignment-location refusal + shared serial claims | StockOpnameBuyoutClaimTest (claimed serial refused, nothing changed; free serial moves) |
| Breakage approval (serial-state good -> broken) | yes | yes (bucket + claimed serials; existing consignment-location refusal remains primary) | ConsignmentBuyoutBreakageReservationTest |
| Legacy "normal" adjustment approval | yes | yes (no bucket below reserved; no deletion of claimed serials) | ConsignmentBuyoutBreakageReservationTest (route-level, with negative controls) |
| Sales-return receive (held custody) | yes | n/a (stock untouched; claims preserved) | ConsignmentBuyoutReturnReceiveTest (success keeps claims/reservations; injected failure rolls back custody + serial state) |
| Sales-return replacement dispatch (held-custody + legacy paths), POS replacement dispatch | yes | yes | **guard integrated; no dedicated real-operation test yet** |
| Purchase-return dispatch / settlement stock moves | yes | yes (reserved good buckets excluded) | **guard integrated; no dedicated real-operation test yet** |
| Purchase receiving approval / receival cancellation | yes (before the Purchase lock; products resolved via received note OR purchase detail) | n/a (adds stock / cost) | ConsignmentBuyoutPurchaseReceivingTest (receiving approval only) |
| Standard return release (`StandardReturnCustodyService::release`) | yes | n/a | **lock only; no dedicated test (ownership routing is group 7)** |

Coordination lock policy: `ConsignmentInventoryCoordination::lockMany` is strict (unknown business/product -> DomainException before any write); `lockManyReport` lets the owning operation reject missing pairs itself (opname, breakage, POS reject before mutation). Nothing proceeds without the lock or a rejection.

Approval must not be exposed to users until every row above is integrated. All results so far are deterministic SQLite integration tests, not concurrency proof; isolated MySQL races (3.6, 8.5, 10.4) and 1.4 remain unverified.

## Pricing and inventory costing (group 5)

`ConsignmentBuyoutPricingService` (per-slice invoice terms) and `ConsignmentBuyoutCostingService` (carrying-value replacement). Both are calculation-only: they move no stock and write no cost. Posting (group 6) consumes their output inside the finalization transaction.

Three separate amounts are kept per slice (a slice = one buyout line: receipt + sold/unsold type + sold source + location + destination):

| Amount | Meaning | Stored |
| --- | --- | --- |
| original | the receipt's own evidence: qty x unit DPP (or cost) + proportional tax (`detail.tax_amount x qty / received`) | `accepted_snapshot.original` |
| accepted | the invoice after the shared `ConsignmentBillingPricingCalculator` row rules (price, % / fixed discount, row total override, tax) | `accepted_snapshot.accepted`, `.payable` (after the document discount) |
| inventory cost | unsold slices only: accepted sub-total minus the net-of-tax share of the document discount (recoverable PKP tax and non-PKP tax excluded; discount counted once) | `accepted_snapshot.inventory_cost` |

- Document discount: allocated by `ImportDocumentAdjustmentAllocator` (positive pre-global tax-inclusive totals, stable line-id order, rounding residual to the largest slice); slice payables sum to the header total or the calculation is invalid. Negative payable or negative inventory cost is rejected, never clamped; zero cost is allowed.
- Non-PKP: submitted tax inputs are neutralized (ignored, flagged `neutralized_tax_inputs`); PKP unchanged terms reproduce the original amounts exactly. Invoice tax never relabels physical buckets or serials (reservations and stock buckets are unchanged by `accept()`).
- Carrying value: `A_after = round((Q*A - q*A + C) / Q, 2)` with Q locked physical quantity of the business, q the aggregated acquired unsold quantity per product, C the aggregated accepted cost. Missing/negative average, Q<=0, q<=0, q>Q are blockers (the average is never seeded). Sold-only documents plan nothing. Other businesses' stock is ignored. `last_purchase_price` and historical Sales/POS HPP are never written (asserted).

Schema note: the slice unique key now includes the destination, so one receipt can be split across several destinations while an exact duplicate slice is still rejected by the database.

## Finalization (group 6, internal)

`ConsignmentBuyoutFinalizationService::finalize()` is **disabled unless `config('consignment.buyouts.finalization_enabled')` is true** (default false, no route/UI calls it). One transaction: coordination locks -> header lock -> locks on receipts, locations, products, source+destination stock rows (destination rows created here, under those locks), sold sources, serials, ProductPrice -> revalidation -> writes.

Revalidated under locks, never trusted from the caller: approved revision hash (BaseModel upper-cases it; compare case-insensitively), active reservations must equal exactly what the approved lines require (and serial claims must match the selection), shared capacity/availability/bucket/serial eligibility via the lifecycle validator (own reservations excluded), pricing recalculated from the approved intent and compared with the terms accepted at approval (receipt-evidence drift is rejected), and the per-business/product carrying-value plan (missing average => blocker before any write).

Writes (stage hooks `purchase`, `movement`, `serial`, `cost`, `audit` exist so tests can inject a failure after each): CONSIGNMENT_BUYOUT Purchase (RECEIVED, no Received Notes) + one detail and one lineage row per slice; paired signed `CONSIGNMENT_BUYOUT` transactions and movement evidence for unsold non-serialized goods (exact good tax/non-tax buckets, decimal(15,3)); serial location moves with preserved receipt lineage, history events and ownership events; ONE ProductPrice average replacement per business/product (aggregated across all slices, receipts and destinations; `last_purchase_price` untouched); reservations consumed; status FINALIZED; audit event. Business and global physical quantity are asserted unchanged before commit. A finalized document replays to its committed Purchase after the posting set is proven complete (an incomplete set is reported, never re-posted); database uniqueness (one Purchase per buyout, one lineage/leg per slice) backs this.

Source protections: `Purchase::isConsignmentFinancial()` (billing or buyout) drives `PurchaseSourceGuard`, header/detail immutability and edit mode; payments, administrative fields and reporting dates keep working. Status endpoint: unauthorized => HTTP 403 with no change; authorized business-rule rejections keep the ordinary redirect/toast handling.

Not yet verified / still required before ANY exposure: isolated MySQL competing-finalization race (6.3), remaining consumer tests (replacement dispatch, purchase-return dispatch, return release), the complete-resource lock-retry protocol (3.1), ownership-aware returns (group 7) and reversal safety (group 8). Approval and finalization stay unavailable until all of these pass together.

## Ownership-aware customer returns (group 7)

Service: `ConsignmentBuyoutReturnOwnershipService`, called from `StandardReturnCustodyService::receive()` (so POS-linked and standard returns share it). Table: `consignment_buyout_return_apportionments` (migration `2026_11_10_000003`, rollback refused once evidence exists).

Flow (each step is a real-operation test in `ConsignmentBuyoutReturnRoutingTest`, plus the real Sales/POS tests noted):
1. A sale from a consignment location is attributed; a buyout acquires part of it as SOLD_UNBILLED. The line stores a standard, same-business **future-return destination** (validated at preparation; nothing moves at finalization).
2. A customer return is received. **Serials**: exact ownership evidence decides (acquired serials are held for the destination, others for the original route). **Non-serialized**: the acquired/unacquired split is explicit - the operator calls `declare()` (acquired quantity between 0 and the returned quantity and the remaining acquired quantity) - unless unambiguous (nothing acquired; every still-unreturned unit is acquired; or the acquired units are already fully returned). Otherwise receiving is **blocked** with nothing written (ownership is never guessed).
3. Goods stay **HELD** (no stock, no sellable quantity) until an authorized inspection release; release posts the acquired part to the owned destination and the unacquired part to the original location. Settlement alone never makes goods sellable.
4. Acquired returns never reopen supplier capacity: `ConsignmentReturnEligibilityService` deducts only the unacquired part from pre-billing eligibility, so sold-source and receipt availability are unchanged (asserted before/after receive and release). The cumulative acquired quantity returned can never exceed the finalized SOLD_UNBILLED quantity (each apportionment consumes buyout-line capacity in id order; replays write nothing).
5. Returned units that are only held are excluded from unsold buyout selection (pool reports `ambiguous` until released).
6. Sales without a finalized buyout are untouched (no apportionment row, original route); POS retains its own return semantics (Pos module failure list unchanged vs pre-change).
7. Acquired units sold again from the owned destination through real Sales dispatch **and** real POS checkout are not consignment sold sources (discovery creates none) and their acquisition is already consumed supplier capacity - no second supplier bill.

Operator example: sold 6, buyout acquired 3 -> return 2 -> declare "1 acquired" -> receive: 1 unit held for the owned destination, 1 for the consignment location -> inspection releases each to its own location.

Replacement dispatch (held-custody path) refuses stock reserved by another buyout both at request and at approval, and succeeds after the reservation is cancelled (`replacement_dispatch_for_an_unacquired_return_cannot_consume_stock_reserved_by_another_buyout`); standard return release is exercised by the routing tests.

## Reversal (group 8, internal)

`ConsignmentBuyoutReversalService` (same disabled-by-default flag; permission `consignments.buyouts.reverse`). `preview()` lists typed blockers; `reverse()` re-runs it under locks (Purchase first, then coordination rows and the buyout, then resources, then payment evidence).

Blockers: any payment record ever (including invalidated), any later inventory transaction at either leg's product/location (so consumption-then-replenishment still blocks), later serial history / foreign claims / serials no longer active at the destination, customer-return apportionment against the acquired sold units, an unapproved source receipt, an incomplete posting set, and a carrying average that no longer equals what the acquisition left or a later cost event. Matching current balances are never enough.

Effects (all-or-nothing, stage hooks `liability`,`stock`,`serial`,`cost`,`audit` for failure injection): Purchase moves to the new `CANCELLED` status only through `Purchase::withBuyoutReversalScope` (original totals, details and lineage preserved; no other field becomes mutable and only CANCELLED is accepted); compensating stock legs and transactions; serials return to the consignment location with history; ownership REVERSAL events; carrying average restored once per product; claims released; reversal record + audit. Capacity is restored because only FINALIZED buyouts consume it. A repeat call returns the same result.

Cancelled projections: canonical live-due formula, live-due attribute, `reconcileFromActivePayments`, global-payment eligibility and `AgedPayablesReportQueryService` all treat CANCELLED as zero collectible; payments are denied by the existing cancelled-Purchase rule. Custody receiving reversal (`ConsignmentBuyoutDependencyChecker`) now also blocks on attribution, active reservations and finalized non-reversed buyouts; cancelling a buyout removes only its own (reservation) blocker.

Still unverified: MySQL payment-vs-reversal and receiving-reversal-vs-buyout races (8.5).

## Lock-retry protocol and cost-event ledger (closing items)

- `ConsignmentLockPlanner::run(discover, work, beforeLocks?)`: read-only discovery -> transaction -> sorted coordination locks -> work; the work calls `$cover($actualPairs)` after its own document locks and, if the real (business, product) set grew, throws `ConsignmentResourceSetChanged` so the WHOLE transaction rolls back (no partial write survives) and the attempt repeats with the complete sorted union (max 4 attempts, then a clean failure). Used by buyout approve/cancel, finalize, reverse (Purchase locked first via `beforeLocks`) and legacy confirmation submit/approve. Tests: `ConsignmentLockPlannerTest` (partial write before detection is rolled back; retry locks the full set ascending; never-settling set fails cleanly; business exceptions are not retried; real approve retries; finalize/reverse use it).
- Reversal now detects **intervening cost events** through an append-only database-trigger ledger (`product_price_cost_events`, migration `2026_11_10_000004`): every change of `product_prices.average_purchase_price` (Eloquent save, mass update or raw SQL) is logged; the buyout cost event stores the ledger position right after its own write (`price_event_id`). Any later row blocks the reversal even if the average returned to the same value; writes that do not change the average create no noise.
- Real purchase-return dispatch test: `ConsignmentBuyoutPurchaseReturnDispatchTest` (reserved quantity refused with ordinary error handling and no change; unreserved negative control posts; refused again until the buyout is cancelled; a multi-line return aborts entirely when a later line hits reserved goods, with proof the earlier line really was written then rolled back).
- Ownership-specific return-release test: coordination lock precedes stock/custody row access; an injected failure inside release leaves custody, destination stock, inspections and apportionment untouched; the retry posts exactly once to the owned destination without touching supplier capacity.
- Baseline captured after these: **895 passed (4312 assertions)**.

## UI, projections and replay (group 9)

- Routes/controller `ConsignmentBuyoutController` (`consignments.buyouts.*`): read screens (list, preparation, detail) need `consignments.buyouts.access`; **every mutation route is a hidden 404 with no side effect unless `consignment.buyouts.ui_enabled` is true** (default false), then it still needs the dedicated permissions (authorization failures are real HTTP 403s, never toasts; business-rule rejections keep the ordinary redirect/toast). Preparation shows receipts, shared capacity, unattributed sales, pool blockers and valid destinations and never selects or substitutes anything; draft rows left blank are not part of the document. Detail shows lines, accepted/original/inventory-cost amounts, blockers, reservations, payments, audit history, reversal blockers and only status-appropriate, permitted, explicitly confirmed actions. Tests: `ConsignmentBuyoutScreensTest`.
- Notifications follow the existing `DocumentNotificationService` conventions (approval needed on submit, revision needed on reject, resolved on approve/cancel/revise; advisory - a notification failure never blocks a transition): `ConsignmentBuyoutNotificationTest`.
- Reconciliation (`ConsignmentBuyoutReconciliationProjection`) and the Purchase detail provenance block separate non-financial attribution, financial vs physical reservations, billed-sold vs acquired-unsold, movements, reversal legs, paid and due.
- Inventory projections (`InventoryReplaySupport`, valuation and summary reports): buyout transactions use their signed leg quantity; the consignment-side leg is ignored for OWNED stock, the destination leg adds owned stock and sets the recorded carrying average; timestamps stay the transaction time (no back-dating to the invoice date); sold-only documents add nothing. Tests: `ConsignmentBuyoutInventoryProjectionTest`.
- Historical cost replay (`HistoricalReplayEngine`): buyout Purchases never enter the receipt fallback; carrying-value events are zero-quantity `cost_replace` events (acquisition `V-q*A+C`, reversal restores from the pre-acquisition average) applied once; legacy results are unchanged when no buyout exists. Tests: `ConsignmentBuyoutCostReplayTest`.
- Operator/UAT/deployment/rollback guide and the exposure checklist: `uat-and-deployment.md`.

## Group 10: sign-off evidence (2026-11-10)

### Isolated MySQL 8.0.44 execution (REAL, not SQLite)
Run: `docker compose -f docker-compose.mysql-test.yml up -d mysql_test`, `php artisan migrate:fresh --database=mysql_test --force`, then `MYSQL_TEST_PORT=33066 ./vendor/bin/phpunit -c phpunit.mysql.xml --filter "ConsignmentBuyoutMysql"` (or `bash scripts/run-mysql-sequence-tests.sh --filter ...`). Real OS processes call the production services/controllers with a start barrier and an optional stagger (so both winner orders occur). Suite: `Modules/Consignment/Tests/Feature/Concurrency/` (`ConsignmentBuyoutMysqlRaceTest`, `ConsignmentBuyoutMysqlCostLedgerTest`). Result of the last run: 10 tests, all passing (race file 6 tests / 200 assertions; ledger file 4 tests / 52 assertions).

| Race / check | Executed | Outcome |
|---|---|---|
| Concurrent first creation of one coordination row (6 workers) | yes | one row, no errors (after fix below) |
| Buyout vs buyout approval over one receipt | yes (1 round) | exactly one approves; reservations == capacity |
| 3 competing finalizations of one buyout | yes | 1 Purchase, 1 lineage set, 2 movements/txns, 1 audit, 1 cost event, 1 ledger event; all callers get the same Purchase |
| Payment vs reversal (6 rounds, both orders) | yes | never both commit; histogram recorded in the run output |
| Reversal vs reversal | yes | one reversal record |
| Purchase-return dispatch (HTTP) vs buyout approval (6 rounds, both orders) | yes | never both take the goods; loser changes nothing |
| Cost-event triggers: create, insert/update/mass-update/no-op, rollback, failed-posting at every stage, down() refusal with evidence, re-up | yes | pass |
| Buyout vs POS checkout / transfer / return receive / receiving-cost races | **no** | not executed: still unverified on MySQL |
| Receiving-reversal vs buyout race | **no** | not executed |

### Defects found only by running on MySQL (all fixed, each with a regression test)
1. **Coordination row creation deadlocked** (`INSERT IGNORE` takes a shared lock that `SELECT ... FOR UPDATE` must upgrade; concurrent creators deadlock). Now `INSERT ... ON DUPLICATE KEY UPDATE id = id` on MySQL.
2. **REPEATABLE READ stale validation reads**: a purchase-return dispatch and a buyout approval both succeeded over the same goods (3 of 3 runs before the fix) because the loser's snapshot predated the winner's commit. Validation reads under the coordination lock (reservation sums, stock bucket, serial claims) are now shared-locking reads (`ConsignmentBuyoutReservationGuard::latest`). **Every future consumer integration must read reservations through the guard.** Consumers not yet raced on MySQL (POS checkout, transfer, return receive, opname, breakage, sales dispatch) use the same guard but remain unverified.
3. **Cost-event trigger creation fails when the migration user lacks SUPER and binary logging is on** (MySQL error 1419). Migration is now re-runnable and raises an actionable message; the deployment guide lists the requirement.
4. **Accepted-pricing re-write tripped the line-immutability guard on MySQL** (JSON numeric normalization makes byte-identical terms look dirty). Re-acceptance of equivalent terms is now a no-op.
5. Fixture sale creation outside a transaction (test-only).

### Retry protocol additions
- Notifications are delivered only after the outermost commit (`DB::afterCommit`): a retried or rolled-back attempt delivers none, a retried approval delivers exactly one set (`ConsignmentLockPlannerTest`).
- A retried reversal locks the Purchase before any coordination row on every attempt and the retry locks the full ascending set (`a_retried_reversal_locks_the_purchase_before_the_coordination_rows_on_every_attempt`). SQLite cannot show `FOR UPDATE`; the order is shown by statement order, and the MySQL duplicate-reversal race shows the real lock behavior.

### "Buy all eligible"
`ConsignmentBuyoutProposalService` + preparation screen (`propose=all` with an explicit destination): read-only; each supplier receipt/sold source appears once as included or excluded with every reason; clipping shows requested vs proposed; shared physical pools are split in receipt order with the remainder visible; un-ticking a row in the prefilled draft form is an explicit exclusion. Tests: `ConsignmentBuyoutAllEligibleTest`.

### Upgrade matrix (10.1)
`ConsignmentBuyoutUpgradeMatrixTest`: legacy data written on the pre-upgrade schema (migrations rolled back): versioned/unversioned sold sources, waiting/approved/billed confirmations with receipt allocations and old tax snapshots, a partially-returned source and a legacy serial; after replay every legacy row is byte-identical, no buyout/movement/cost/coordination data appears, legacy arithmetic is unchanged. Limitation: the legacy-claim owner assertion and the capacity number use permissive fallbacks; the claim upgrade itself is asserted strictly in `ConsignmentBuyoutSchemaTest::legacy_claims_survive_the_claims_upgrade`.

### Unresolved: intermittent failure
`livewire component renders business selector` (`GlobalPurchasePaymentFiltersTest`, also present in Sale and Reports twins) failed once in a combined Purchase+SalesReturn run and passed on rerun and in 6 isolated runs. **Not explained.** Hypothesis (unconfirmed): `SettingFactory` uses `faker->company`, whose names can contain an apostrophe that HTML-escapes and breaks the `>name</option>` assertion. It is not part of `phpunit.consignment.xml`, so it does not affect consignment coverage; it remains an open flake in the unrelated default suite. A 40-iteration reproduction attempt was aborted because it collided with the shared SQLite file (see below).

### Process incident
Two `php artisan test` processes ran concurrently against the shared `database/testing.sqlite` and produced "database disk image is malformed" failures. The runs were discarded and repeated through `scripts/test-with-fresh-sqlite.sh`'s lock; no result in this document comes from a colliding run.

### Final consignment baseline (10.3)
`bash scripts/test-consignment-baseline.sh` (shared SQLite lock, after the last edit): **924 passed, 0 failed, 0 skipped reported (4509 assertions)**, 118.7 s. `@group mysql` files are excluded from this run by design. Tasks ticked from MySQL evidence: 1.4, 6.3. Still unticked: 3.5 and 3.6 and 8.5 (only part of their race lists executed), 10.4 (matrix incomplete), 10.5 (UAT not executed), 10.6 (diff reviewed and `openspec validate --strict` passes; see handoff).

## Spec-review follow-up (post group 10)

A read-only spec review found four gaps despite the green baseline. All four are fixed with regression tests in
`ConsignmentBuyoutReviewFindingsTest` (each with a negative control).

| # | Finding | Fix |
|---|---------|-----|
| P1 | Sold slice not tied to its receipt: an unrelated product's or another receipt's source could pair with an available receipt | `ConsignmentBuyoutCapacityService::validateSoldPairs` + `soldReceiptResidual`: source product/location must match the receipt, the receipt must hold an attribution of that source, and summed requests per source/receipt pair may not exceed the pair's remaining attributed quantity (attribution minus finalized lines and other active reservations of that exact pair) |
| P1 | Live sold evidence not recomputed | `validateSoldEvidence`: the version-correct live dispatch hash (`computeCanonicalLiveHash`) is recomputed on every validation (submit, approve, finalize) and compared to the stored source hash and the hash captured on the line |
| P1 | Inactive destination accepted | destination validation now requires `is_active`; a destination deactivated after approval blocks finalization with nothing posted |
| P2 | "All eligible" proposed wrong sold selections | receipt-specific residuals, a running per-source remainder across receipts (a source is never proposed more than once in total), and serial selection limited to serials of that receipt that are not already claimed |

Fixture consequence: sold-source fixtures previously carried fake hashes (`h5`); they now store the genuine live hash
(`BuildsBuyoutFixtures`, `ConsignmentBuyoutReturnRoutingTest`). That is the intended effect of the live-hash check.

### Extra defect found while writing the receiving race

`PurchaseReceivalCancellationService` checked that stock was sufficient but not that it stayed above buyout reservations:
cancelling a 10-unit ordinary receiving took a bucket from 12 to 2 while an approved buyout reserved 10. Fixed with an
eligibility blocker plus a hard `assertBucketsRetainReserved` in the executor (regression test in
`ConsignmentBuyoutPurchaseReceivingTest`). Reachability note: ordinary receiving refuses consignment locations, so this
can only occur if a location is reclassified as consignment after receiving; the guard is defence in depth.

### MySQL race matrix, extended (isolated MySQL 8.0.44, real OS processes, both winner orders)

17 tests / 553 assertions pass in `phpunit.mysql.xml`. Rounds of 6, staggered both ways, outcomes split 3 / 3 each.

| Race | Result |
|------|--------|
| coordination row creation, buyout vs buyout, 3 finalizations, duplicate reversal | pass (earlier) |
| payment vs reversal | pass, 3 / 3 |
| purchase-return dispatch vs approval | pass, 3 / 3 |
| sale dispatch approval vs approval (+ control: dispatch alone moves stock) | pass, 3 / 3 |
| transfer approve+dispatch vs approval (+ control) | pass, 3 / 3 |
| purchase receiving cancellation vs approval | pass, 3 / 3 |
| consignment receiving reversal vs approval (+ control) | pass, 3 / 3 |

A first version of the sale-dispatch race "passed" 6 / 6 for the buyout side only, because the fixture product was not
stock-managed and the dispatch never touched stock. The negative control caught it; every dispatch-type race now has a
control proving the worker really moves the stock.

### Remaining races, now executed (isolated MySQL 8.0.44; 6 staggered rounds each, both winner orders, negative control per race)

| Race | Result | What is asserted |
|------|--------|------------------|
| POS checkout finalization vs approval | pass, 3 / 3 | each worker builds its OWN session + cart before the barrier, only `pos.sell.checkout.finalize` is raced; reserved consignment goods never sold; refused checkout leaves no dispatch and original stock; refused approval reserves nothing |
| Customer return receive vs finalization | pass, 3 / 3 | finalize first: return held for the owned destination, effective supplier return 0, one purchase, release posts to the owned location and capacity is unchanged; return first: finalization refused, nothing posted, returned units out of the residual (4 left, never 6) |
| Customer return receive vs approval | pass, 3 / 3 | returned first: approval refused, nothing reserved; reserved first: the over-consumed document cannot be finalized and posts nothing |
| Receiving approval (average cost) vs finalization | pass, 3 / 3 | exactly two ledger rows; ledger is a gap-free chain (no lost update); live average equals the last ledger value; the buyout cost event points at its own ledger row and carries the average it truly replaced; reversal blocker present exactly when a cost event follows the acquisition |
| Receiving approval vs reversal | pass, 3 / 3 | reversal wins: restores the pre-acquisition average before the receiving row; receiving wins: reversal refused, no cost event written, buyout stays FINALIZED |
| Legacy billing-confirmation submit vs approval | pass, 3 / 3 | the same sold units are never claimed by both; sold source never over-consumed |

Total in `phpunit.mysql.xml`: 51 tests / 1425 assertions pass. Baseline: 932 passed (4534 assertions).

Harness notes: `RunsBuyoutRaceWorkers` holds the shared plumbing; the worker gained `transfer`, `legacy` and `pos` modes. The POS
test replaces the parent's RefreshDatabase transaction with truncation so workers see committed rows. Every race has a control
proving its worker really performs the operation (two earlier "passes" were vacuous until a control exposed a non-managed product).

### Not covered by any race

- Receiving approval racing a buyout *approval* (no shared state beyond the coordination lock; cost effects are only written at finalization).

`@group mysql` is excluded from the baseline by design.

### Outstanding items recorded for the handoff

- Maintainability: resource discovery/locking is duplicated across lifecycle, finalization and reversal; divergent lock
  sets are a risk. Not refactored here (a refactor of the locking core would invalidate the race evidence above).
- ~~`ConsignmentBuyoutCapacityService` dependency `exists()` read (~line 302) is still a plain read.~~ Confirmed defect and fixed during main integration (see below).
- UAT (10.5) must be executed by a person; the flake `livewire component renders business selector` stays unresolved.

### Final-review follow-up (two P1 + one deployment gap)

- Legacy consumption in `soldReceiptResidual`: pending (WAITING_APPROVAL) and approved legacy receipt allocations of the exact source/receipt pair are now subtracted (A=5/B=5 with A's five billed leaves A=0, B=5). Test: `legacy_pending_and_approved_allocations_consume_the_exact_source_receipt_pair`.
- Return ownership lower bound: `assertUnacquiredRoom` enforces that units declared NOT acquired fit in the sale's unacquired, unreturned room (sold - acquired - other unacquired returns) at declaration and at receiving (explicit and "nothing acquired" paths), cumulatively. Test: `a_declaration_cannot_understate_the_acquired_quantity_even_across_partial_returns` (sell 10, acquire 5, return 10 declaring 0 refused; partial returns bounded cumulatively). Serialized ownership is exact and unaffected.
- Deployment guide: trigger reinstall requires quiescing all cost writers until both triggers are restored and verified, including after migration failure.

### Integration with main (0774d63c), 2026-11-11

Report conflicts (`InventorySummaryReportQueryService`, `InventoryValuationReportQueryService`) resolved on main's per-business
structure; `loadBuyoutTransactionEffects()` is loaded per business inside the loop, the non-owned leg is ignored (summary) /
treated as custody (valuation), and the recorded `average_after` is applied at the event. Buyout labels moved to
`InventoryTransactionTypeLabel` (all contexts); the now-unused private `TYPE_LABELS` constant was removed. Main's
transfer-date change in `InventoryReplaySupport` auto-merged and its regressions pass.

Attribution concurrency (confirmed defect, fixed): `setAttribution` did a plain read before the coordination lock, pinning a
REPEATABLE READ snapshot, so `attributionIsDependedOn()` could miss a reservation committed by a concurrent approval. Race
`attribution_edit_versus_buyout_approval_cannot_both_commit` failed on round 1 (both committed: attribution 6 -> 3/3 under an
ACTIVE reservation). Fix: discovery read moved before the transaction; dependency and gross-attribution reads use
`ConsignmentBuyoutReservationGuard::latest()`. After the fix: 6 rounds, approval-won 3 / edit-won 3, invariant held.

New tests: multi-business valuation/summary before/after acquisition, between acquisition and reversal, after reversal, with a
second business at a different cost (totals + summary export); owned period in/out and ledger/export labels; label contexts;
buyout destinations never enabled for POS; config defaults/env mapping.

Results on the integrated tree:
- `bash scripts/test-consignment-baseline.sh`: 940 passed (4635 assertions), 0 failed.
- `bash scripts/run-mysql-sequence-tests.sh` (MySQL 8.0.44): OK, 52 tests, 1480 assertions.
- Main's report/POS/sale-location/Purchase total & PKP tax files plus buyout POS/Purchase/screens files (37 files run
  individually with `php artisan test <file>`): 324 passed (1623 assertions).
- `openspec validate buy-out-unsold-consignment-stock --strict`: valid.

Known limitation: `WarehouseStockValuationReportQueryService` (main) values replayed per-location quantities at the *current*
average, so historical as-of values there do not reflect the buyout average at the event date (pre-existing design, not
buyout-specific). Inventory Detail is quantity-only.

### Review corrections (2026-11-11, after independent review of the integrated candidate)

1. **Cross-business return declaration (P1, fixed).** `declareReturn` loaded `SaleReturnDetail::findOrFail()` unscoped and
   `declare()` accepted a bare actor id. Now the HTTP lookup is scoped to the active business (the return's business or
   the sold source's inventory-owner business, for POS split-owner) and anything else is a 404; `declare()` requires a
   `User`, checks `consignments.buyouts.acquire_unsold` and access to one of those businesses, and refuses after receiving.
   Tests: `a_foreign_business_cannot_declare_...`, `declaring_requires_the_acquire_unsold_permission_...` (denials leave
   apportionments, custody, stock, transactions, purchases, audit and return state unchanged).
2. **Missing operator declaration flow (P1, fixed).** New panel `consignment::buyouts.partials.return-declaration` on the
   Sales Return and POS return detail screens: returned non-serialized quantity, sold/acquired/already-returned acquired
   quantities, owned destination, bounds, current declaration; form only when `ui_enabled`, permitted and not received.
   Serialized units are never offered. Test: `the_operator_declares_through_the_rendered_form_then_receives_and_releases_...`.
3. **POS-linked receiving ignored buyout ownership (found during this pass, fixed).** `PosReturnLifecycleService` posted
   returned stock straight to the original location with no apportionment, so an acquired unit went back to the
   consignment pool and an ambiguous split was guessed. It now calls `resolveForReceive()` and posts each ownership part
   to its destination (acquired -> buyout line's owned destination). Verified red on the pre-fix code. Tests:
   `pos_linked_receiving_routes_acquired_units_...`, `pos_linked_receiving_of_a_sale_without_a_buyout_is_unchanged`.
4. **Declaration vs reversal (confirmed race, fixed).** New race `declaration_versus_reversal_...` failed in the
   simultaneous-start round (both committed). Root cause: `finalizedSoldLines()` filtered status with `whereHas`; MySQL
   evaluates a subquery inside a locking read as a plain consistent read, so a just-reversed buyout still looked FINALIZED.
   Fix: status read by its own locking read; declaration and receiving lock the acquiring buyout rows (reversal locks the
   same rows FOR UPDATE); reversal's apportionment blocker uses a locking read; the same `whereHas` pattern in
   `attributionIsDependedOn()` was hardened. After: 10 rounds, reversal-won 7 / declaration-won 3, invariant held.
   Declaration vs receiving: 5 rounds, applied split never overwritten and always equal to owned custody.
5. Coordination keys for receiving (standard and POS) and declaration now come from
   `ConsignmentBuyoutReturnOwnershipService::coordinationPairs()` (return business + sold-source owner business, sorted).

Results (final code):
- `bash scripts/test-consignment-baseline.sh`: 946 passed (4691 assertions).
- `bash scripts/test-with-fresh-sqlite.sh --filter='ConsignmentBuyoutConfigTest|InventoryTransactionTypeLabelTest|TransferDateReplayRegressionTest|ProductReports.*BusinessScopeTest|ReportBusinessScopeTest|POSCheckoutPreflightTest|SaleLocationConfigurationTest|PurchaseManualLineTotalPersistenceTest|PurchaseProductCartPkpTaxReconciliationTest|PurchaseProductCartLineTotalTest|InventoryValuationReport|InventorySummaryReport|POSReturn|PosReturn'`: 593 passed (3283 assertions).
- `bash scripts/run-mysql-sequence-tests.sh` (MySQL 8.0.44): OK, 55 tests, 1648 assertions.

Remaining limitations: split-owner returns (return business != sold-source owner) have no automated test; with
`ui_enabled=false` an ambiguous mixed return cannot be declared and therefore cannot be received.

## Second review corrections (2026-11-11)

1. **POS receiving rounded decimal ownership splits (P1, fixed; corrected after the third review).** `PosReturnLifecycleService`
   cast each ownership allocation with `(int) round(...)` and cast existing stock balances, product totals and transaction
   snapshots to integers, so a 0.5/1.5 split posted 1/1 and 0.5 existing + 1 returned became 1.0. An interim fix that
   restricted declarations to whole units was wrong: migration `2026_06_03_000000_convert_quantity_columns_to_decimal`
   makes stock and transaction quantities DECIMAL(15,3), and an integer return total can have fractional ownership
   portions. That restriction is removed (service, HTTP `numeric`, form `step="0.001"` with the decimal min/max, so every
   value the server accepts is also submittable). POS posting now keeps every quantity, balance, product total and
   transaction snapshot as a 3-decimal value; parts are summed and checked in 3-decimal precision and must not exceed the
   returned quantity. Tests:
   `pos_receiving_posts_a_fractional_ownership_split_exactly_and_preserves_decimal_balances` (HTTP 0.5 declaration,
   0.25 existing -> 0.75 owned, 1.5 to consignment, transactions 0.5/1.5 with decimal snapshots),
   `pos_receiving_of_a_whole_split_preserves_existing_decimal_stock` (the reviewer's 0.5 + 1 = 1.5 case),
   `the_declaration_form_accepts_every_value_inside_fractional_bounds` (sold 6, acquired 2.5, return 4: bounds 0.5-2.5;
   1, 2, 0.5, 2.5 accepted, 0.4 and 2.6 refused), and
   `pos_receiving_splits_acquired_units_across_several_owned_destinations_exactly`.
2. **Concurrent different returns exceeded unacquired room (P1, fixed).** `SalesReturnController::receive` discovered
   coordination keys with a plain read inside the transaction (pinning the REPEATABLE READ snapshot before the lock wait),
   and the unacquired-room / ambiguity checks read received returns with a `whereHas` snapshot read. Fix: keys are
   discovered before the transaction (POS already did); new `receivedReturnQuantity()` reads the other details and their
   parent return statuses with separate latest-committed (locking) reads and is used by `resolveForReceive()` and
   `assertUnacquiredRoom()`. Tests (MySQL): see "Concurrency evidence (current)" in `uat-and-deployment.md`. The
   deterministic held-lock race was verified red on the pre-fix code (both received, 4 unacquired > 3) and green after.
   The race worker's `coord` mode gained an optional hold time.

The reviewer's fractional `/tmp` reproduction is now satisfied by the permanent tests above; the in-process stale-snapshot seam would now wait on the coordination lock its own connection holds
(the competitor must run in another process, which the held-lock race does).

Results (final code):
- `bash scripts/test-consignment-baseline.sh`: 950 passed (4724 assertions).
- `bash scripts/test-with-fresh-sqlite.sh --filter='Consignment|SalesReturn|SaleReturn|InventoryValuationReport|InventorySummaryReport|POSReturn|PosReturn|WarehouseStock|AverageCost|SaleLocation|POSCheckout'`:
  1551 passed, 3 failed. The 3 failures (`POSCheckoutNoteAndPaymentImageTest` x2, `POSCheckoutSplitPostingTest` x1)
  fail identically on a clean `main` (0774d63c) worktree: pre-existing, unrelated to this change.
- `bash scripts/run-mysql-sequence-tests.sh` (MySQL 8.0.44): OK, 59 tests, 1841 assertions.

## Independent reviewer sign-off for task 10.6 (2026-11-11)

Reviewed the resolved main integration and the final correction tree at HEAD `c7846edc`, main/MERGE_HEAD `0774d63c`, including the untracked return-declaration partial. Standards and specification reviews found no remaining merge-blocking findings in the reviewed corrections. The authorization, operator declaration flow, different-return concurrency, decimal ownership posting, existing decimal balance preservation, and decimal form-bound findings are closed. The incorrect interim whole-unit restriction and its spec premise have been removed.

Independent final verification:

- `bash scripts/test-consignment-baseline.sh`: final serial rerun **950 passed, 4724 assertions**, 107.45 seconds.
- `bash scripts/run-mysql-sequence-tests.sh Modules/Consignment/Tests/Feature/ConsignmentBuyoutReturnRoutingTest.php --filter='pos_receiving_posts_a_fractional_ownership_split_exactly_and_preserves_decimal_balances|pos_receiving_of_a_whole_split_preserves_existing_decimal_stock|the_declaration_form_accepts_every_value_inside_fractional_bounds|pos_receiving_splits_acquired_units_across_several_owned_destinations_exactly'`: MySQL 8.0.44, **4 passed, 33 assertions**, 20.007 seconds. The isolated container was cleaned up by the runner.
- `openspec validate buy-out-unsold-consignment-stock --strict`: valid. PHP syntax checks on the 11 changed tracked PHP/Blade files passed; the new partial was exercised by the rendered-page tests. No unmerged index entries remain.

Verification caveat: the first baseline attempt had **949 passed, 1 failed** at `StockOpnameRedesignTest::test_unauthorized_user_cannot_view_system_stock_or_differences`, line 1305 (`assertDontSee('142')`). This file is unchanged from main. The isolated rerun passed (1 test, 20 assertions) and the subsequent complete baseline passed. The same test already documents the risk of bare numeric assertions matching opaque random tokens, but the exact occurrence in the failed response was not captured; treat this as an intermittent test limitation, not a conclusively diagnosed production defect. No test was weakened or excluded. Logs: `/tmp/buyout-signoff-baseline.log`, `/tmp/buyout-signoff-opname-rerun.log`, `/tmp/buyout-signoff-baseline-rerun.log`, `/tmp/buyout-signoff-mysql-decimal.log`.

The full 59-test MySQL matrix and wider SQLite clean-main failure comparisons above are implementer-reported evidence, not full reruns by this final review. The reviewer independently ran the targeted decimal tests on MySQL; the prior independent different-return race review remains recorded in the conversation. The three documented pre-existing checkout failures remain outside this change's scope.

Task **10.6 is complete** for this reviewed tree. Task **10.5 remains open** for human staging UAT, including split-owner returns when applicable. This is code-review sign-off, not production activation approval; production-copy migration rehearsal and deployment prerequisites remain required. Complete the pending merge only with explicitly reviewed paths: the new return-declaration partial is required; unrelated untracked `backup/` and the historical `review-handoff.md` should not be included automatically. No application changes were made, staged, or committed by the reviewer.

## Production-review follow-up (2026-11-13): fail-closed deploy, dispatch-time compatibility

**P1 — deployment fails closed.** `deploy.bat`'s failure handler no longer runs `php artisan up`. It keeps maintenance mode on and prints recovery steps. The new read-only `php artisan consignment:verify-cost-ledger-triggers` (Modules/Consignment/Console/VerifyCostLedgerTriggersCommand.php) gates both the post-migration step and reopening. Before migrating, the operator must confirm that all cost writers are stopped. See `uat-and-deployment.md` ("`deploy.bat` behavior"). Tests: `VerifyCostLedgerTriggersCommandTest`. It covers success, a rolled-back probe with no data change, a missing insert, update or both trigger(s), a present-but-inert trigger, and the static `deploy.bat` contract (no `up` in the handler; confirm < migrate < verify; verify < the only `up`; each verify followed by `if errorlevel 1 goto :deployment_failed`). The batch script was not executed on Windows in this environment.

**P2 — timezone fallback no longer masks a dispatch-time edit.** `computeCanonicalLiveHash()` previously accepted the historical offset spelling whenever the snapshot's UTC instant equalled the live time. A genuine edit of the live time by exactly the offset (15:55:40 → 07:55:40 with snapshot `15:55:40+08:00`) therefore still matched. The fallback now also requires the immutable capture column `consignment_sold_sources.dispatched_at` to equal both the live time and the snapshot's UTC instant. Every historical row inspected locally (read-only) has this shape. Stored hashes, snapshots and records are not modified. Tests in `ConsignmentSoldSourceTimezoneHashTest`: legitimate historical compatibility (v1 and v2) with quantity, ±1 s and serial changes still rejected; unchanged evidence matches directly; and the exact eight-hour negative control (v1 and v2). The negative control fails on the pre-fix code. `/tmp/consignment-review-time-shift-repro.php`: before `changed_dispatch_still_matches: true`, after `false`, with `normal_capture_matches: true` both times.

### Second production-review follow-up (2026-11-13)

- **P1 — maintenance mode could silently fail to start.** `deploy.bat` used `php artisan down --message=...`, which Laravel 10 does not support, and ignored the failure. It now runs `php artisan down --retry=60` and on failure jumps to `:maintenance_failed`. That handler aborts before any install, build or migration, never runs `up`, and exits 1. The previous custom maintenance text is gone; a custom page would need `--render` with a view.
- **P2 — the verifier only exercised the update trigger.** The probe now performs an INSERT and an UPDATE in one rolled-back transaction. It checks that each appends exactly one ledger row with the correct product, setting and old/new averages.
- Regressions in `VerifyCostLedgerTriggersCommandTest`:
  - inert insert trigger, inert update trigger, and an insert or update trigger that records the wrong average (all must exit 1);
  - the unpriced-pair and delete/reinsert probe paths, which must leave `product_prices` and the ledger unchanged;
  - the `deploy.bat` contract: no `--message`; `down` is the first command and is followed by `if errorlevel 1 goto :maintenance_failed`; neither handler runs `up`.

