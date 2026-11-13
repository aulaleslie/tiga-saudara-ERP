# Operator guide, UAT scenarios and deployment/rollback

Every scenario below is backed by an automated feature scenario (named in brackets). All mutation actions stay **disabled** until the exposure checklist at the end is satisfied: `consignment.buyouts.ui_enabled` hides every mutation route (404, no side effect) and `consignment.buyouts.finalization_enabled` gates finalization and reversal inside the services.

## Permissions

| Action | Permissions required (all) |
| --- | --- |
| View list / preparation / detail / reconciliation | `consignments.buyouts.access` (+ business access) |
| Create / revise draft | `consignments.buyouts.create` / `.edit`; plus `.acquire_unsold` when any unsold line exists |
| Submit / reject / cancel | `.submit` / `.reject` / `.cancel` (+ `.acquire_unsold` for submit with unsold lines) |
| Approve | `.approve` **and** `consignments.allocations.approve` (+ `.acquire_unsold` with unsold lines) |
| Finalize | `.finalize` **and** `consignments.billing.convert` (+ `.acquire_unsold`) |
| Reverse | `.reverse` |
| Attribute sales to receipts | `consignments.buyouts.create` |
| Declare acquired quantity of a returned item | `consignments.buyouts.acquire_unsold` |

A sold-only biller (billing permissions only) has no buyout access [ConsignmentBuyoutLifecycleServiceTest::sold_only_billers_gain_no_buyout_access..., ConsignmentBuyoutScreensTest].

**How permissions reach roles (actual behaviour).** `php artisan permissions:sync` runs `PermissionsTableSeeder`, which creates every permission in `app/Config/Permissions.php` and then calls `syncPermissions()` on the **Admin** role. Admin therefore receives *every* `consignments.buyouts.*` permission, including `.finalize`, `.reverse` and `.acquire_unsold`, on every sync, and any manual removal from Admin is undone by the next sync. This repository-wide convention is kept unchanged. Consequences:

- Admin users can act on buyouts as soon as the activation switches are on. The switches (below), not role assignment, are what keep the feature dark in production.
- No other role receives any buyout permission automatically. Grant them per business deliberately (e.g. read-only `consignments.buyouts.access` first, then create/submit to preparers, approve to approvers, finalize/reverse to finance) through the normal role screens.
- Billing permissions (`consignments.billing.*`, `consignments.allocations.*`) never imply a buyout permission; finalize additionally requires `consignments.billing.convert` and approve requires `consignments.allocations.approve` *on top of* the buyout permission.
- If Admin must not be able to buy out before UAT sign-off, keep both switches off; do not try to strip Admin (it will be re-granted).

## Activation switches

| Config key | Environment variable | Default | Effect when `false` |
| --- | --- | --- | --- |
| `consignment.buyouts.ui_enabled` | `CONSIGNMENT_BUYOUTS_UI_ENABLED` | `false` | Every buyout mutation route (store, update, submit, approve, reject, cancel, finalize, reverse, attribution, return declaration) returns 404 with no side effect. Read screens stay available behind `consignments.buyouts.access`. |
| `consignment.buyouts.finalization_enabled` | `CONSIGNMENT_BUYOUTS_FINALIZATION_ENABLED` | `false` | `ConsignmentBuyoutFinalizationService` and `ConsignmentBuyoutReversalService` refuse to run, even when called directly. |

Defined in `config/consignment.php`. Authorization always still applies when a switch is on [ConsignmentBuyoutScreensTest::when_enabled_forged_requests_get_403...].

- **Config cache:** production runs `php artisan config:cache`, after which `.env` is not read. After changing either variable run `php artisan config:cache` again (or `config:clear`) and restart PHP-FPM/Octane and queue workers so every process sees the same value.
- **Activate** (only after the exposure checklist): set both to `true`, re-cache, restart. Enabling only `ui_enabled` lets users prepare/approve (which creates reservations) but not finalize.
- **Disable:** set both to `false`, re-cache, restart. This stops new actions only. Existing drafts, approved reservations, finalized buyouts and lineage remain and are still honoured by POS, sales dispatch, transfers, returns and reports. Approved reservations do not expire; to release them, cancel the document (requires the switch on, or a forward-fix). The return declaration is also a gated mutation: while `ui_enabled` is `false`, a customer return whose ownership split is ambiguous (non-serialized, partly acquired sale) **cannot be received** until the switch is re-enabled or a person with database access forward-fixes it. Unambiguous and serialized returns keep receiving normally.

## Scenarios

1. **Full unsold buyout** - prepare (supplier -> receipts, capacity, blockers) -> draft (explicit quantity, tax/non-tax split, destination) -> submit -> approve (all reservations at once) -> finalize (Purchase + stock move + one carrying-value replacement). [ConsignmentBuyoutFinalizationTest::mixed_finalization..., ConsignmentBuyoutScreensTest]
2. **Partial buyout across destinations** - one receipt split across two destinations never exceeds the receipt and costs once. [::splitting_a_receipt_across_destinations...]
3. **Mixed sold + unsold on one invoice** - sold units are billed with no stock effect, unsold units move; 5 billed / 3 moved example. [::mixed_finalization_bills_five_moves_three...]
4. **Two suppliers sharing a product/location** - attribute each sale to a receipt first (non-financial); buying supplier A never bills B. [ConsignmentBuyoutCapacityTest::attribution_example_a10_b8_sold6...]
5. **Exact serials and fractional stock** - serials are selected by identity (whole numbers, unique, unclaimed); decimal quantities move exactly. [ConsignmentBuyoutFinalizationTest::injected_failures_cover_serial_moves_and_history, ::fractional_quantities...]
6. **Customer return after acquisition** - acquired units are held, then released to the owned destination; mixed returns need an explicit "acquired quantity" entered on the return detail screen (Sales Return or POS return, panel *Kepemilikan Barang Retur*, permission `consignments.buyouts.acquire_unsold`); POS-linked receiving posts acquired units to the owned destination; supplier capacity is never reopened. [ConsignmentBuyoutReturnRoutingTest]
7. **Payments** - partial then full payment through the ordinary Purchase workflow; no stock movement; approved evidence untouched. [ConsignmentBuyoutPurchaseProtectionTest]
8. **Reversal** - eligible: restores stock, serials, average and capacity, cancels the liability (original amounts kept). Blocked: any payment history, later movement, serial history, return apportionment, intervening cost event. [ConsignmentBuyoutReversalTest]
9. **Cancel before finalization** - releases every reservation; stock, cost and payables unchanged. [ConsignmentBuyoutLifecycleServiceTest::reject_and_cancel...]

Operator notes: damaged or moved stock, held returns and unattributed sales appear as blockers on the preparation screen - fix them, the system will not borrow other stock. Approved reservations never expire; cancel the document to release them. After reversal the Purchase shows CANCELLED with zero collectible due.

## Deployment order

Prerequisites (confirm against the real production server, not the test container):

- **Database engine/version.** Verified only on MySQL 8.0.44 (InnoDB, REPEATABLE-READ, binlog on). Migration `000002` adds an enforced `CHECK` constraint (MySQL >= 8.0.16; MariaDB uses different `DROP CONSTRAINT`/CHECK semantics and is **not** verified) and changes two foreign-key columns of `consignment_active_serial_claims` to nullable. Run `SELECT VERSION(), @@log_bin, @@log_bin_trust_function_creators, @@transaction_isolation;` on production and stop if it is not MySQL 8.0.16+.
- **Trigger privilege.** The migration user needs `TRIGGER` on the schema plus, with binlog on, `SUPER`/`SET_USER_ID` or `log_bin_trust_function_creators=1` for the migration window (see below).
- **Backup.** Take a full logical or physical backup immediately before step 1 and confirm it restores. This is the only clean way back from a partially applied `000001`/`000002`.
- **Rehearsal.** Run steps 1-2 against a restored copy of the production database (which contains real legacy consignment claims and `product_prices` rows) before production. A synthetic rehearsal was done on the isolated MySQL 8.0.44 container (see "Migration rehearsal evidence"); it does not replace the production-copy rehearsal.

Steps:

1. Maintenance mode; stop queue workers, schedulers, imports and any job that writes `product_prices` (cost writers, see the trigger section). Run `php artisan migrate --force`. Verify: the four `2026_11_10_00000x` rows are in `migrations`; `information_schema.TRIGGERS` lists exactly `trg_ppce_insert` and `trg_ppce_update` on `product_prices`; a test average update appends one `product_price_cost_events` row (then restore the value); existing `consignment_active_serial_claims` rows have `owner_type = 'LEGACY'`.
2. Deploy the complete application release (all reservation-aware consumers: Sales dispatch, POS, transfers, opname, breakage, legacy adjustments, return dispatches, purchase-return dispatch/settlement, reports) to **every** web, queue and scheduler node with both switches `false`. Run `php artisan permissions:sync` (Admin receives all buyout permissions; see Permissions) and `php artisan config:cache`. Restart workers. Only then leave maintenance mode.
3. Do not run mixed application versions once any reservation exists: an old node ignores reservations and buyout lineage and can sell or move reserved goods. Every node must be on this release before step 4, and no node may later be rolled back to a pre-buyout build.
4. Grant non-Admin roles per business deliberately; read access (`consignments.buyouts.access`) first.
5. Activation only after the exposure checklist below.

## Migration failure recovery

MySQL DDL is not transactional: each `CREATE`/`ALTER` commits implicitly, and Laravel records a migration only after its `up()` completes. A failed migration therefore leaves its earlier statements applied but unrecorded.

- **`000001` (six new tables) / `000003` (one new table).** No existing data is touched. If it fails midway, the tables created so far exist (empty) and a re-run fails with "table already exists". Recovery: keep maintenance mode, list the tables the migration creates, confirm each is empty, drop them (children first: reservations, attributions, audit events, serials, lines, buyouts), fix the cause and re-run. Never drop a table that has rows.
- **`000002` (five new tables, plus ALTERs of existing `consignment_buyouts` and `consignment_active_serial_claims`, the owner CHECK and the coordination table).** The serial-claim alteration is a sequence of separate ALTERs (add `owner_type`/buyout columns -> make legacy FK columns nullable -> add FKs/unique -> add `chk_casc_owner`). A failure can stop between them. Recovery: compare the claims table with `SHOW CREATE TABLE` against the migration, drop only the objects this migration added (empty new tables, the added columns/FKs/unique/CHECK, revert the two columns to `NOT NULL` — legacy rows always have values there), confirm legacy claim rows are unchanged, then re-run. If the state cannot be reconciled with certainty, restore the pre-migration backup. Do not resume normal operations on a half-altered claims table.
- **`000004` (cost-event ledger and triggers).** Safe to re-run: it creates the table only when missing and drops/recreates both triggers. Keep cost writers stopped through the failure and the re-run (see the trigger section).
- After any recovery, re-run the step-1 verification before deploying application code.

## Rollback

Rollback boundaries:

- **Before any buyout evidence exists** (no buyout documents, reservations, attributions, claims owned by a buyout, postings, apportionments or ledger-referenced cost events): the schema can be rolled back in order 4, 3, 2, 1 (`down()` refuses before any DDL otherwise). Verified on MySQL 8.0.44 with legacy claim and price rows present. SQLite cannot drop foreign keys, so use MySQL for this path. The application must be rolled back together with or before the schema.
- **Once operational evidence exists** (any reservation, attribution, finalized/reversed buyout, cost event referenced by a buyout): do **not** roll back schema or code. Older code ignores buyout reservations and lineage and would sell or move reserved/acquired goods and mis-value them. Instead: set both switches to `false`, re-cache, then forward-fix; where an individual buyout is wrong, use the audited reversal when it is eligible (no payments, later movements, serial history, return apportionment or intervening cost event) or cancel pre-finalization documents to release reservations. Cases that are not eligible for reversal need a forward-fix by a developer, not a restore.
- A database restore after evidence exists also discards every unrelated business transaction since the backup; treat it as disaster recovery only.

## Migration rehearsal evidence (isolated MySQL 8.0.44 container, synthetic data)

Performed 2026-11-11 on the integrated branch with a non-SUPER migration user and `log_bin=1`:

1. Rolled back `000001`-`000004` from a fresh schema; inserted a legacy `product_prices` row and a legacy `consignment_active_serial_claims` row.
2. With `log_bin_trust_function_creators=0`, `migrate` applied `000001`-`000003` and `000004` failed with the actionable privilege message. State after the failure: `000004` unrecorded, `product_price_cost_events` present, **zero** triggers on `product_prices`, and a cost update made in that state wrote **no** ledger row (the hazard the quiesce rule prevents).
3. After setting the flag to `1`, re-running `migrate` succeeded: exactly `trg_ppce_insert` and `trg_ppce_update`; an average update appended exactly one ledger row.
4. Legacy rows preserved: the claim became `owner_type = LEGACY` with its confirmation/allocation ids intact; the price row unchanged until the test update. The `chk_casc_owner` CHECK rejected an incomplete claim.
5. Empty-schema rollback 4, 3, 2, 1 succeeded with the legacy rows present.

Not rehearsed: a production data copy, MariaDB, mid-`000001`/`000002` failures (recovery above is by inspection), and lock/ALTER duration on a large claims table.

## Staging UAT activation (not production)

Human UAT (task 10.5) needs working controls, so the switches are turned **on in an isolated staging environment only**:

1. Staging database restored from (or shaped like) production, never shared with production. Deploy the candidate release and run the migrations on **every** participating web/queue/scheduler process.
2. `php artisan permissions:sync`; assign per-business test roles: preparers (create/edit/submit), approvers (approve + `consignments.allocations.approve`), finance (finalize + `consignments.billing.convert`, reverse), a return operator (`consignments.buyouts.acquire_unsold` + sales/POS return receive and inspect), a billing-only user, and users restricted to different businesses.
3. Set `CONSIGNMENT_BUYOUTS_UI_ENABLED=true` and `CONSIGNMENT_BUYOUTS_FINALIZATION_ENABLED=true` in the **staging** environment, `php artisan config:cache`, restart PHP workers and queues.
4. Execute the scenarios above plus the manual checklist below, recording document IDs, expected/actual stock buckets per location, owned destinations, average costs, Purchase totals, payment balances and screenshots.

Manual checklist (in addition to scenarios 1-9):

- **Ambiguous non-serialized return** (e.g. sell 6, buy out 3 sold units, return 2): receiving is refused until a declaration exists; on the Sales Return (or POS return) detail screen, the *Kepemilikan Barang Retur* panel shows the returned quantity, sold/acquired/already-returned acquired quantities, the owned destination and the allowed bounds. Declare 1, receive, inspect/release. Expect 1 unit at the owned destination, 1 at the consignment location, supplier capacity unchanged, no new Purchase. Repeat through a POS-linked return (POS receiving posts directly: 1 owned, 1 consignment).
- **Denials change nothing:** out-of-bounds declaration, declaration by a user of another business (404 when that business is active; 403 otherwise), a user without `acquire_unsold` (403), a declaration after receiving, and repeated receive/release.
- Serialized returns are routed automatically by exact serial lineage; no declaration is offered.
- Historical/multi-business Inventory Valuation and Summary before acquisition, between acquisition and reversal, after reversal, including exports and labels. Warehouse Stock Valuation values at the current average (documented limitation).
- POS destination assignments stay as configured; zero enabled POS locations still blocks checkout.

Switching the staging flags on is **not** production activation and is not evidence for it.

## Production activation checklist (all must be true before enabling the flags in production)

- [x] Isolated MySQL race matrix executed and passing on the candidate (see "Concurrency evidence (current)").
- [x] Lifecycle notifications (4.5) implemented and tested (`ConsignmentBuyoutNotificationTest`, `ConsignmentLockPlannerTest`); nothing outstanding.
- [ ] Human UAT (10.5) executed on staging and signed off by a person.
- [ ] Final review (10.6) of the corrected candidate completed.
- [ ] Production-copy migration rehearsal done; production DB version, CHECK support, trigger privileges and backup restore confirmed (Deployment prerequisites).
- [ ] Every production node runs this release; `config:cache` refreshed; workers restarted; switches still `false` until this list is complete.

## MySQL deployment requirement: cost-event triggers (added at sign-off)
Migration `2026_11_10_000004` creates two triggers on `product_prices`. On MySQL with binary logging enabled (the 8.0 default) `CREATE TRIGGER` needs `SUPER`/`SET_USER_ID` for the migration user, or `log_bin_trust_function_creators=1` during the migration. Without it the migration stops with an actionable message and can be re-run safely (the table step is skipped when present). The isolated test compose file sets `--log-bin-trust-function-creators=1` for this reason; production must grant the privilege or set the variable for the migration window.

**Safety: quiesce every cost writer first.** Every (re)run of migration `000004` DROPS the existing `trg_ppce_insert` / `trg_ppce_update` triggers before recreating them, and MySQL DDL commits implicitly. Between the drop and the successful recreate, any write to `product_prices.average_purchase_price` (purchase/receiving approval, receival cancellation, consignment receiving or reversal, buyout finalization or reversal, cost replay, imports, manual SQL) would change the average WITHOUT a cost-ledger row, silently defeating the reversal protection. Therefore:
1. Before running the migration (first install, re-run, or retry after a failure), stop or maintenance-mode ALL writers of product costs and confirm nothing is mid-transaction.
2. Keep them stopped until BOTH triggers are restored and verified, including after a migration failure (a failed run can leave zero or one trigger): `SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'product_prices'` must list exactly `trg_ppce_insert` and `trg_ppce_update`; a test average update must append one `product_price_cost_events` row.
3. If the migration fails, do not resume writers; fix the cause (e.g. privilege/`log_bin_trust_function_creators`), re-run, and verify as in step 2.
   `php artisan consignment:verify-cost-ledger-triggers` performs the step-2 check: it requires exactly both triggers, then inside one transaction that is always rolled back (no business data changes) INSERTs a priced `product_prices` row (an unpriced product/setting pair, or a temporarily removed existing row) and UPDATEs its average, and requires each statement to append exactly one ledger row with the matching product, setting and old/new averages. It exits non-zero and prints recovery steps on any failure.

**`deploy.bat` behavior (fail closed).** The first step is `php artisan down --retry=60` (Laravel 10 has no `--message` option); if it fails the script aborts before installing, building or migrating, and exits 1 without touching anything. Before `migrate`, the script lists the cost writers (all queue workers on every node, the scheduler, imports/cost replays/manual SQL) and continues only if the operator types `YES`. `php artisan queue:restart` alone does not count: the worker and scheduler services must be stopped. After `migrate` it runs `consignment:verify-cost-ledger-triggers`, runs it again immediately before the only `php artisan up`, and reopens only when both runs pass. On any failure (including a declined confirmation) the handler leaves **maintenance mode ON**, prints the recovery steps (fix the cause → `php artisan migrate --force` → `php artisan consignment:verify-cost-ledger-triggers` must print OK → re-run `deploy.bat` or finish the steps → only then `php artisan up` and restart workers/scheduler), and exits 1. The script never reopens the application after a failure. After a successful deployment the operator restarts the worker and scheduler services that were stopped.
4. Leave the buyout flags off until this verification passes.

## Concurrency evidence (current)

This replaces the earlier "not yet executed" and "final" race notes. All races run as real OS processes against the isolated MySQL 8.0.44 container (`bash scripts/run-mysql-sequence-tests.sh`, `phpunit.mysql.xml`), invariants asserted on committed data for both winner orders, each with a solo control proving its worker performs the operation. Covered: buyout vs buyout, legacy allocation, duplicate finalization, payment vs reversal, duplicate reversal, purchase-return dispatch, sale dispatch, transfer, POS checkout, customer return receive (vs approval and finalization), receiving approval/cost (vs finalization and reversal), purchase receiving cancellation, consignment receiving reversal, attribution edit vs approval, **return declaration vs buyout reversal**, and **return declaration vs return receiving**. Latest results and the defects these races found are in `testing.md` ("Integration with main" and "Review corrections").

Different returns of one sale (second review): `receiving_versus_receiving_of_different_returns_...` (7 rounds), `receivers_queued_behind_a_held_coordination_lock_...` (deterministic: a holder keeps the coordination lock while both receivers queue; red on the pre-fix code with both returns committed, 4 unacquired > 3), and `declaration_versus_receiving_of_a_different_return_...` (6 rounds). The applied unacquired total never exceeded sold minus acquired.

Not covered by a race: receiving approval vs buyout *approval* (no shared state beyond the coordination lock), and split-owner returns where the sale-return business differs from the sold-source owner (**not tested** by any automated test; the coordination keys include both businesses, sorted, by design). Cover it in staging UAT if split-owner POS returns occur in production.
