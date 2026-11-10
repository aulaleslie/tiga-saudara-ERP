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

## Scenarios

1. **Full unsold buyout** - prepare (supplier -> receipts, capacity, blockers) -> draft (explicit quantity, tax/non-tax split, destination) -> submit -> approve (all reservations at once) -> finalize (Purchase + stock move + one carrying-value replacement). [ConsignmentBuyoutFinalizationTest::mixed_finalization..., ConsignmentBuyoutScreensTest]
2. **Partial buyout across destinations** - one receipt split across two destinations never exceeds the receipt and costs once. [::splitting_a_receipt_across_destinations...]
3. **Mixed sold + unsold on one invoice** - sold units are billed with no stock effect, unsold units move; 5 billed / 3 moved example. [::mixed_finalization_bills_five_moves_three...]
4. **Two suppliers sharing a product/location** - attribute each sale to a receipt first (non-financial); buying supplier A never bills B. [ConsignmentBuyoutCapacityTest::attribution_example_a10_b8_sold6...]
5. **Exact serials and fractional stock** - serials are selected by identity (whole numbers, unique, unclaimed); decimal quantities move exactly. [ConsignmentBuyoutFinalizationTest::injected_failures_cover_serial_moves_and_history, ::fractional_quantities...]
6. **Customer return after acquisition** - acquired units are held, then released to the owned destination; mixed returns need an explicit "acquired quantity"; supplier capacity is never reopened. [ConsignmentBuyoutReturnRoutingTest]
7. **Payments** - partial then full payment through the ordinary Purchase workflow; no stock movement; approved evidence untouched. [ConsignmentBuyoutPurchaseProtectionTest]
8. **Reversal** - eligible: restores stock, serials, average and capacity, cancels the liability (original amounts kept). Blocked: any payment history, later movement, serial history, return apportionment, intervening cost event. [ConsignmentBuyoutReversalTest]
9. **Cancel before finalization** - releases every reservation; stock, cost and payables unchanged. [ConsignmentBuyoutLifecycleServiceTest::reject_and_cancel...]

Operator notes: damaged or moved stock, held returns and unattributed sales appear as blockers on the preparation screen - fix them, the system will not borrow other stock. Approved reservations never expire; cancel the document to release them. After reversal the Purchase shows CANCELLED with zero collectible due.

## Deployment order

1. Run migrations `2026_11_10_000001`-`000004` (additive; the claim table gets owner columns and a CHECK/trigger, the price ledger gets triggers). Deploy permissions without granting any buyout permission.
2. Deploy application code with both flags OFF. All stock consumers now honour reservations (Sales dispatch, POS, transfers, opname, breakage, legacy adjustments, return dispatches, purchase-return dispatch/settlement) - there is nothing to reserve yet.
3. Configure per-business roles; grant read access (`consignments.buyouts.access`) first.
4. Exposure (NOT yet allowed) requires the checklist below.

## Rollback

`down()` of every migration refuses (before any DDL) once buyout, claim, lineage, posting, apportionment or ledger-referenced evidence exists. With evidence present: turn the flags off and forward-fix, or perform audited reversals. Empty-schema rollback order: 4, 3, 2, 1 (SQLite cannot drop foreign keys, so use MySQL for that path).

## Exposure checklist (all must be true before enabling the flags)

- [ ] Isolated MySQL race matrix executed and passing: buyout vs buyout, legacy allocation, dispatch, POS checkout, transfer, return, receiving/cost change, duplicate finalization, payment-vs-reversal, receiving-reversal-vs-buyout (tasks 1.4, 3.6, 6.3, 8.5, 10.4)
- [ ] Remaining notification conventions (4.5) and the final UAT run (10.5) completed
- [ ] Baseline and module comparisons rerun after the last change (10.3)


## MySQL deployment requirement: cost-event triggers (added at sign-off)
Migration `2026_11_10_000004` creates two triggers on `product_prices`. On MySQL with binary logging enabled (the 8.0 default) `CREATE TRIGGER` needs `SUPER`/`SET_USER_ID` for the migration user, or `log_bin_trust_function_creators=1` during the migration. Without it the migration stops with an actionable message and can be re-run safely (the table step is skipped when present). The isolated test compose file sets `--log-bin-trust-function-creators=1` for this reason; production must grant the privilege or set the variable for the migration window.

**Safety: quiesce every cost writer first.** Every (re)run of migration `000004` DROPS the existing `trg_ppce_insert` / `trg_ppce_update` triggers before recreating them, and MySQL DDL commits implicitly. Between the drop and the successful recreate, any write to `product_prices.average_purchase_price` (purchase/receiving approval, receival cancellation, consignment receiving or reversal, buyout finalization or reversal, cost replay, imports, manual SQL) would change the average WITHOUT a cost-ledger row, silently defeating the reversal protection. Therefore:
1. Before running the migration (first install, re-run, or retry after a failure), stop or maintenance-mode ALL writers of product costs and confirm nothing is mid-transaction.
2. Keep them stopped until BOTH triggers are restored and verified, including after a migration failure (a failed run can leave zero or one trigger): `SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'product_prices'` must list exactly `trg_ppce_insert` and `trg_ppce_update`; a test average update must append one `product_price_cost_events` row.
3. If the migration fails, do not resume writers; fix the cause (e.g. privilege/`log_bin_trust_function_creators`), re-run, and verify as in step 2.
4. Leave the buyout flags off until this verification passes.

## MySQL race matrix: what is and is not proven
See `testing.md` "Group 10". Exposure stays blocked until the not-yet-executed races (POS checkout, transfer, return receive, receiving/cost, receiving-reversal vs buyout) are run, and until UAT (10.5) is executed by a person.

## Race-matrix status update (final)

Executed on isolated MySQL 8.0.44 (51 tests, 1425 assertions): buyout vs buyout, legacy allocation, duplicate finalization,
payment vs reversal, duplicate reversal, purchase-return dispatch, sale dispatch, transfer, POS checkout, customer return
receive (vs approval and finalization), receiving approval/cost (vs finalization and reversal), purchase receiving
cancellation and consignment receiving reversal. Exposure of `consignment.buyouts.finalization_enabled` / `ui_enabled` stays
blocked until UAT (10.5) is signed off and the final review of 10.6 is complete.
