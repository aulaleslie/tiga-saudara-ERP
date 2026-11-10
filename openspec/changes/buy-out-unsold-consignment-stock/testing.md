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
- `ConsignmentBuyoutCapacityService` legacy-reservation `exists()` read (~line 302) is still a plain read.
- UAT (10.5) must be executed by a person; the flake `livewire component renders business selector` stays unresolved.

### Final-review follow-up (two P1 + one deployment gap)

- Legacy consumption in `soldReceiptResidual`: pending (WAITING_APPROVAL) and approved legacy receipt allocations of the exact source/receipt pair are now subtracted (A=5/B=5 with A's five billed leaves A=0, B=5). Test: `legacy_pending_and_approved_allocations_consume_the_exact_source_receipt_pair`.
- Return ownership lower bound: `assertUnacquiredRoom` enforces that units declared NOT acquired fit in the sale's unacquired, unreturned room (sold - acquired - other unacquired returns) at declaration and at receiving (explicit and "nothing acquired" paths), cumulatively. Test: `a_declaration_cannot_understate_the_acquired_quantity_even_across_partial_returns` (sell 10, acquire 5, return 10 declaring 0 refused; partial returns bounded cumulatively). Serialized ownership is exact and unaffected.
- Deployment guide: trigger reinstall requires quiescing all cost writers until both triggers are restored and verified, including after migration failure.
