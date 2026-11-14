# Final-review handoff: track-transferred-consignment-provenance

Written for: the independent final reviewer and the human UAT/archive owner.

## Status

| Owner | Item | State |
| --- | --- | --- |
| Agent | Tasks 1.1–8.3, including 5.6 and 6.5 (implementation, tests, docs, MySQL races) | Done (45/46 checked); see verification below |
| Agent | 8.4 final diff/spec review and this handoff | Done |
| **Human** | 8.4 manual UAT (scenarios below) | **Pending** |
| **Human** | Archive: `buy-out-unsold-consignment-stock` **first**, then this change | **Pending**. The agent did not archive. |
| **Human** | Production activation of `consignment.buyouts.ui_enabled` / `finalization_enabled` | **Off.** The predecessor's exposure checklist still applies. |

## Final-review fixes (after the first handoff)

- **P1, held non-serialized returns counted as sellable:** fixed. `ConsignmentTransferProvenanceService::heldReturns()` turns unacquired returns still in held custody (`sale_return_custodies`: received − released − dispatched, at consignment locations) into a per-receipt **HELD** disposition at the custody location.
  - Serialized returns follow the serial's receipt.
  - Non-serialized returns are assigned only when exact (one attributed receipt, or held equal to the mapped returns). Otherwise they are a blocker, never a guess.
  - Released units become SELLABLE under the existing release policy.
  - Regressions: `ConsignmentRemoteDiscoveryTest::test_held_non_serialized_returns_are_never_offered_as_sellable_lot_stock`. This is the reviewer's case: A has 3 sellable + 2 held, B has 2. Selecting 5 from A is refused; A 3 + B 2 dispatches; release restores A. Also `…test_ambiguous_held_return_apportionment_blocks_instead_of_guessing`.
- **P2, reconstruction retry bypassed authorization:** fixed. `confirm()` authorizes an explicit origin filter up front. The idempotent retry path is scoped to that origin and re-checks `authorizeOriginReconciliation` for every origin it would return.
  - Regression: `ConsignmentHistoricalReconstructionTest::test_retry_rechecks_authorization_and_origin_scope`, covering an unauthorized user, a wrong-business filter, a foreign scope, and authorized scoped/unscoped retries.

## Diff scope

Changed (tracked):
- `Modules/Consignment`: services, controllers, views, routes, provider, race worker.
- `Modules/Adjustment`: consignment-only hooks.
  - V3 dispatch/receipt/cancel executors call `ConsignmentTransferProvenanceService` only when the source is a consignment location.
  - The V3 approval screen gets the lot panel and summary errors.
  - V1/V2 dispatch entry points get the legacy refusal guard.
- `Modules/Purchase/Services/HistoricalReplayEngine.php` and `app/Services/Reports/Concerns/InventoryReplaySupport.php`: buyout cost-event replay keys include the owner business.
- `app/Config/Permissions.php`: two permissions.

New (untracked):
- 2 migrations (`2026_11_11_000001..000002`)
- 4 entities
- 7 services
- 1 command (`consignment:reconstruct-provenance`)
- 1 controller and the lot-panel view
- tests
- `docs/consignment-transfer-provenance.md`
- this change directory

**Not changed:** anything under `Modules/Pos` or `Modules/Sale`. That means no POS cart, checkout, allocation, posting, location order, tax, receipt or customer-payment code (`git status --short Modules/Pos Modules/Sale` is empty). `ConsignmentRemotePosBoundaryTest` drives a real POS checkout over transferred consignment stock: it posts exactly as before, writes no provenance, and asks for no supplier.

## Migration requirements
- `php artisan migrate` (additive):
  - `000001`: provenance tables
  - `000002`: owner columns and a new cost-event unique key
  - No data is seeded or repaired.
- Run `php artisan db:seed --class="Modules\User\Database\Seeders\PermissionsTableSeeder"` to synchronize permissions from `app/Config/Permissions.php`, including the two new permissions and Admin grants. There is no separate permission migration.
- Rollback refuses when provenance, mapping or remote-owner evidence exists; forward-fix instead.
- If the predecessor's `2026_11_10_000004` (cost-ledger triggers) is (re)run in the same deploy, quiesce every cost writer first (the predecessor's rule, unchanged).
- Before deploy: re-create any open V1/V2 transfer with a consignment origin as V3 (none exist locally).
- After UAT, as operator actions, never as part of deployment:
  - `consignment:reconstruct-provenance` (preview, then confirm with hash and reason) per affected product
  - manual discovery

## Compatibility deviation (V1/V2)
The original plan was to integrate V1/V2 consignment routes through adapters. Implemented instead: new V1/V2 dispatches and return dispatches from consignment origins are **refused** before any stock effect, with an actionable V3 path (`ConsignmentTransferProvenanceService::LEGACY_REFUSAL`).
- Ordinary V1/V2 is unchanged.
- Receiving already-dispatched legacy documents is unguarded.
- V1/V2 consignment evidence surfaces as `UNSUPPORTED_WORKFLOW_VERSION` blockers.

Rationale: the local AXIOO movements are all V3. This is recorded in design §3, the `stock-transfer-inventory-movement` delta ("Legacy workflow consignment dispatch is refused"), the docs and task 2.5. Test: `ConsignmentLegacyTransferCompatibilityTest`.

## Verification (exact commands; rerun after the last code edit)

| Check | Command | Result |
| --- | --- | --- |
| Locked consignment baseline (SQLite), rerun after the P1/P2 fixes | `php artisan test -c phpunit.consignment.xml` | **1033 passed** (5126 assertions), 0 failed |
| Isolated MySQL races (8.0.44, REPEATABLE-READ, STRICT, utf8mb4) | `bash scripts/run-mysql-sequence-tests.sh --testsuite "MySQL Consignment Buyout Races"` | **OK (43 tests, 1963 assertions)**, 11:04, rerun after the P1/P2 fixes; container removed afterwards |
| Strict OpenSpec | `openspec validate track-transferred-consignment-provenance --strict`; `openspec validate buy-out-unsold-consignment-stock --strict` | both **valid** |

New remote races, each a separate OS process with staggered starts and solo controls proving the workers act:
- transfer vs buyout: transfer 2 / buyout 2
- origin vs remote discovery: one envelope, one binding
- legacy vs buyout of a remote share: legacy 2 / buyout 2
- payment vs remote reversal: reversal 2 / payment 2
- **remote return vs finalization: finalize 3 / return 3**. Ownership is routed to the holding business; returned units are never billed; a refused finalize posts nothing; finalize retry is idempotent.
- **remote owner cost change vs reversal: reversal 3 / cost-change 3**. B's ledger is a gap-free chain; a reversal never restores over an intervening B row; no consignment cost lands in the origin; stock/ownership is restored once; repeat reversal is a no-op.

Not used as evidence: the earlier clean-checkout comparison that loaded main-tree classes (**inconclusive**).

## Unresolved risks / limitations
1. **Pre-existing, outside this change:** `PurchaseController::approveReceiving` → `updateAveragePurchasePrice()` updates the product-level average. In the race fixture, a PERDANA purchase receiving also wrote a ledger row for the product-owner business (TOP IT). This was measured: the control printed `1` origin-ledger row from an ordinary B receiving. No consignment code writes origin cost, which the races assert. Recommend a separate Purchase-module review.
2. Pre-existing failing tests in Sales/POS-area suites and 3 in Adjustment (`set line quantity forbids cross tenant products`, `unknown exact scan…`, `an existing transfer renders a read only badge…`) are deferred. The earlier clean-checkout comparison loaded main-tree classes and is **inconclusive**; it is not cited as regression proof. A later comparison with an independent vendor copy found every examined Sales/POS file failing at least as much on the base.
3. Fractional non-serialized V3 transfers keep the existing whole-unit contract (not extended).
4. Held vs sellable custody for returned non-serialized goods is not a separate provenance state. The existing held-custody guards and physical buckets decide selectability.
5. The split-owner POS return path (return business ≠ sold-source owner) is covered only via remote returns. The predecessor's note stands.
6. `ConsignmentPhase3FeatureTest` showed one non-reproducible hash-mismatch once early in the work. It has not recurred in later full runs.

## Human UAT scenarios (8.4)
Use staging with the flags on only there:
1. Local buyout and reversal.
2. Remote buyout: TOP IT buys unsold PERDANA-held units; one TOP IT Purchase; PERDANA owns the stock; reversal.
3. Billing a transferred (remote) sale. Check supplier show/print/payment/export shows TOP IT only.
4. Non-serialized ambiguity:
   - lot panel with several lots
   - reconstruction `--choices`
   - back-office attribution
5. AXIOO reconstruction preview/confirm against a staging copy, and that the numbers reconcile.
6. Partial customer returns at PERDANA, both before and after acquisition.
7. Forbidden routes:
   - consignment ↔ standard transfer
   - a V1/V2 consignment dispatch, refused with the actionable message
