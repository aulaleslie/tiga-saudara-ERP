# Consignment Transfer Provenance

Contracts for `track-transferred-consignment-provenance`. Builds on the unarchived
`buy-out-unsold-consignment-stock` baseline; its UAT/exposure gates still apply.

## Identities

| Identity | Column(s) | Authority |
| --- | --- | --- |
| Original billing business / supplier | `original_setting_id`, `supplier_id` | Approved `consignment_receiving_details` → receiving/receival. Immutable. |
| Custody location / business | `from_/to_location_id`, `from_/to_setting_id` (events); `source_/destination_*` (lot selections) | Latest completed receipt-backed transfer path, or explicit TRANSIT/HELD state. |
| Seller location / business | `seller_location_id`, `seller_setting_id` (bindings) | Persisted `DispatchDetail` / sale evidence. |
| Inventory owner after buyout | `inventory_owner_setting_id` (buyout lines, lineages, movements, ownership events); `consignment_buyout_cost_events.setting_id` | Holding business at acquisition. `NULL` on legacy rows = header `consignment_buyouts.setting_id`. |

A foreign location ID never grants access by itself. See `ConsignmentProvenanceAuthorizer`.

## Tables (migration `2026_11_11_000001`)

- `consignment_transfer_lot_selections`: explicit receipt-lot quantities (or serial) for a consignment transfer, per selection revision. Ordinary transfers never write rows.
- `consignment_provenance_events`: append-only custody movements between states `SELLABLE`, `HELD`, `TRANSIT` (located) and `SOLD`, `ACQUIRED`, `EXTERNAL`. `source_event_key` is globally unique, so replay is idempotent. Quantity is always positive; direction is from → to.
- `consignment_obligation_bindings`: binds a sold envelope (`consignment_sold_sources`, unchanged, original hash kept) to an original receipt with its own `binding_version`/`binding_hash`/`revision`. Unique per (sold source, receipt, revision).
- `consignment_historical_mappings`: operator-confirmed reconstruction revisions, unique per (receipt, revision) and per (receipt, evidence hash).

## Projection

`ConsignmentCustodyProjection::forReceipt()` rebuilds custody from the receipt plus events. Without an explicit `EXTERNAL → …` root event, the approved receipt is implicit SELLABLE custody at its receiving location (legacy-compatible, no seeding). Supplier billing classifies SOLD exits financially and is never subtracted again. Negative balances and non-conservation are returned as blockers, never clamped.

## Cost events (migration `2026_11_11_000002`)

Uniqueness changed from `(buyout, product, kind)` to `(buyout, setting, product, kind)`: one buyout acquiring the same product in two businesses yields one cost event per owner. Existing rows are untouched; no owner backfill.

## Permissions (central configuration and seeder)

- `consignments.provenance.reconcile`: reconcile/attribute/map evidence for receipts of a business the actor belongs to.
- `consignments.buyouts.acquire_remote`: delegated acquisition of unsold supplier stock held by another business. Also requires `acquire_unsold` plus membership of the origin and every affected custody business.

Only Admin is granted these automatically.

## Rollback

- `000001` down refuses while any provenance table has rows.
- `000002` down refuses while any line's owner differs from its header, or while a buyout has more than one cost event per (product, kind).
- Once provenance, billing or acquisition evidence exists: disable the new actions and forward-fix. Never delete mappings or regenerate old bills.

Verified by `ConsignmentProvenanceUpgradeTest`: a finalized local buyout, sold-source hashes, purchases and payments, serials, stock and prices are byte-identical across a down/up cycle.

## Transfers (phase 2)

Only transfers whose **source is a consignment location** are affected. Ordinary transfers, POS checkout and cashier steps are unchanged. Consignment ↔ standard routes stay rejected by the existing allocation rules.

### Lot selection (V3 approval workspace)

- Serialized goods need no prompt. Each serial's `consignment_receiving_detail_id` is its lot, and dispatch checks that the serial's latest custody is SELLABLE at the source.
- Non-serialized rows show the **Lot Penerimaan Konsinyasi** panel. Candidates are receipts with supplier-owned sellable custody at the source, net of sales, finalized buyouts and active unsold buyout reservations.
  - **One candidate:** it is prefilled as a proposal. The operator still confirms it. Viewing the page writes nothing.
  - **Several candidates:** the operator enters quantities per lot. They must sum to the row quantity. No FIFO/LIFO.
- Each confirmation is a new `selection_revision`; older rows are VOIDED. Only DRAFT/PENDING transfers can change lots.
- The review summary lists missing or invalid lot confirmations as approval errors.

Example: receipts R1 (CANDRA, 4) and R2 (other supplier, 6) at rack A; transfer 7 to rack B. No proposal is made. The operator enters R1 = 4, R2 = 3. Dispatch posts two SELLABLE@A → TRANSIT events, one per supplier, and both keep the original business.

### Execution (inside the existing transaction)

| Step | Event | Effect |
| --- | --- | --- |
| Dispatch | `TRANSFER_DISPATCH` | SELLABLE@source → TRANSIT (keyed to destination). Lots and quantities are revalidated under lock. Unattributed sales in a multi-lot source pool block dispatch. |
| Receipt | `TRANSFER_RECEIPT` | TRANSIT → SELLABLE@destination, exactly once per dispatch event. |
| Cancellation | `TRANSFER_CANCEL` | TRANSIT → SELLABLE@source, exactly once. |

A failure on any allocation rolls back the whole dispatch: stock, claims, serials and provenance. Replay with the same operation key adds nothing. Goods in transit are available at neither end. A multi-hop return to the origin moves the same lot back; availability is never doubled.

Non-serialized events record the bucket the existing poster applied (`TAX`, `NON_TAX` or `MIXED`); serial events record the serial's bucket. Route classification and tax snapshots are the ordinary V3 ones. Provenance creates no purchase, bill, payment or stock-debt row.

### Workflow versions

- Local data check (read-only): the AXIOO transfers TSM-2026-10-0021/0162/0329/0550 and TSM-2026-11-0042 are all **V3**. No V1 rows exist; the 2 V2 transfers have no consignment origin.
- **V3 dispatched before this change** (no events): receipt and cancellation proceed and record nothing. `ConsignmentTransferEvidenceAdapter` exposes the frozen allocations and serial pivots for preview-first reconstruction.
- **V1/V2 (compatibility deviation from the original plan):** new dispatches and return dispatches from consignment origins are refused before any stock effect, so no executable path can skip provenance. Ordinary non-consignment V1/V2 is unchanged, and receiving already-dispatched legacy documents is untouched. The adapter reports V1/V2 consignment origins as `UNSUPPORTED_WORKFLOW_VERSION` blockers.
  - **Operator path when refused:** cancel or archive the legacy document (it has not moved stock), create a new transfer (V3 when `STOCK_TRANSFERS_V3_CREATION_ENABLED=true`), then confirm *Lot Penerimaan Konsinyasi* in the V3 approval screen and approve.
  - **Before deploy:** list open (unshipped) V1/V2 transfers with a consignment origin and re-create them as V3. Local data currently has none.

## Discovery and attribution (phase 3)

**Discovery** captures sold evidence. **Attribution** says which supplier receipt a sale consumed. Both stay manual: the `consignment:discover-sold-sources` command and the back-office discovery action are the only entry points. No listener, observer or schedule calls them (`ConsignmentDiscoveryStaysManualTest`). POS checkout is never involved.

### Remote reach

Discovery for business X also scans consignment locations of **other** businesses that received X's receipt custody through provenance events. It only scans products that arrived there, and only dispatches approved at or after the first arrival. Unrelated foreign sales are never read.

- Web action: remote reach needs `consignments.provenance.reconcile` in addition to the existing `consignments.allocations.create`.
- CLI command: full reach.

### Envelope and bindings

- The sold envelope (`consignment_sold_sources`) is always the **actual selling business's** v2 evidence: setting, location, hash. One per dispatch detail, so origin and remote discovery never duplicate it.
- Each original-receipt contribution from a transferred custody is a `consignment_obligation_binding`, version `b1`, with its own canonical hash over the envelope hash, receipt, identities, quantity, serials and custody path. Old v1/v2 envelopes and hashes are never changed.
- **Serialized:** custody of each serial as of the dispatch time, from its events, must be SELLABLE at the sale location. Bindings are created automatically during manual discovery and never move. Missing path, transit-only custody, reversed receipt, wrong product and substituted serial are all blockers.
- **Non-serialized:** `setAttribution` accepts a transferred receipt when its custody at the sale location (as of dispatch, net of its other attributions there) covers the quantity. It writes a `CONFIRMED_LOT` binding revision; changes supersede the old revision. `proposeLotAttribution` is a read-only sole-lot proposal. No FIFO/LIFO.
- Local sales (receipt never moved) need no binding.

Shared capacity stays exact per (sold source, receipt) pair across legacy waiting/approved allocations and buyout reserved/finalized lines. One remote sale split between two original businesses is consumed once per share.

### Pool completeness

An undiscovered approved dispatch, or an unattributed sale, in a multi-lot consignment pool blocks transfer dispatch from that pool until it is discovered and attributed. Buyout pool blockers are unchanged. The checkout itself is never blocked.

## Supplier billing (phase 4)

- A confirmation stays one original supplier plus one original (receipt) business. Its lines may reference a foreign-selling envelope only through an ACTIVE, hash-verified binding to a receipt of that same business and supplier. This applies at draft (`saveLinesAndAllocations`), submit/approve (`validateReceiptAllocations` and `resolveSerialLineage`, both with the sold-source ID), and the conversion preview.
- A remote receipt pool is capped by the exact (sold source, receipt) residual, so a share is never billed twice. The remote business cannot bill the origin's receipts. There is no cross-origin consolidation.
- The Purchase, numbering sequence, invoice/tax policy and payments are the original business's, exactly as for local sales. The remote sale, dispatch, tax and customer payment are untouched. Old bills and paid balances are not rebuilt.

### Supplier-facing vs internal

| Output | Shows |
| --- | --- |
| Purchase show / print / payments (supplier-facing) | Original business only: its receival/receiving references, quantities, serials. No remote business, location, sale reference or customer (`supplier_facing_outputs_identify_the_origin_business_only`). |
| Confirmation create/edit/show (internal) | Sale reference and selling location as plain text, for authorized origin staff. No customer data. |
| Sold-source list (internal) | Own envelopes, plus remote envelopes bound to this business's receipts **only** with `consignments.provenance.reconcile`, badged "Penjualan bisnis lain (transfer)". |

The provenance view grants no access to the remote sale itself: `sales.show` for a PERDANA sale still returns 403/404 to origin staff. Attachments are not copied from internal provenance onto supplier output.

### Provenance-only settlement boundary

Transfers, discovery, bindings and billing create **no** inter-business payable, invoice, payment, commission or stock-debt event. The only financial document is the original business's supplier Purchase. `add-cross-business-stock-debt-balances` is not a dependency, and its listener is never invoked by consignment replay.

## Remote buyout (phase 5)

- **Billing business** = `consignment_buyouts.setting_id` (original receipt business). It owns the one supplier Purchase, its sequence and invoice/tax policy.
- **Inventory owner** = holding business of the custody location. An unsold line may set `source_location_id` to any consignment location holding the receipt's custody; if that location belongs to another business, `inventory_owner_setting_id` is set to it. Its destination must be an active standard location **of that business**. Goods are never transferred back to the origin, no Received Note is created, and serial receipt lineage is kept.
- **Authorization**: remote lines need `acquire_unsold` + `consignments.buyouts.acquire_remote` + membership of every holding business, checked at create/edit/submit/approve/finalize.
- **Capacity**: per (receipt, location) custody, net of sales, acquisitions and other buyouts' reservations. Never-moved receipts keep their exact legacy numbers. Serials are selectable only where their receipt custody is SELLABLE.
- **Locks**: coordination covers (origin, product) and (each holder, product), using the existing sorted complete-resource planner.
- **Costing**: one replacement per (owner, product): `(Q*A − q*A + C)/Q` with that owner's Q/A and the cost C of its own slices only. The invoice discount is allocated once across all slices. A remote-only acquisition changes nothing at the origin. Last-purchase price and historical HPP are untouched. Replay keys include the owner.
- **Tax**: invoice tax follows the origin's supplier invoice policy; physical tax buckets/serial tax follow the holding location and prior transfer policy.
- **Revision hash**: an `owner` key is added only for remote lines, so local approved buyouts keep their hashes.
- **UI**: the proposal and show screens list "Menjadi milik" per row and state that the supplier bill goes to the origin only.

## Returns, reversals and locking (phase 6)

### Customer returns
- **Held custody is not sellable.** Unacquired returned units still in held return custody count as HELD for their receipt at the custody location, not as sellable lot stock. Serialized returns are exact. Non-serialized returns are assigned only when exact, otherwise they block the lot until returns are mapped explicitly. Released units become sellable again.
- Returns stay with the actual selling business's sale/dispatch: POS and sales-return flows are unchanged.
- **Unacquired** remote returns: the effective return removes the unit from sold attribution (serial exact; non-serialized through the existing explicit return apportionment). The origin's unbilled eligibility drops once, and custody reappears at the real return location under the original supplier. Held/inspection release rules still decide when it becomes selectable.
- **Acquired** remote sold units: a buyout's SOLD line on a remote envelope is owned by the **selling** business (`inventory_owner_setting_id` = seller). Its return destination must be that business's standard location, so later customer returns are owned there. Existing lower/upper apportionment bounds apply; supplier capacity is never reopened.

### Full reversal
Purchase lock first, then every (origin, owner, product) coordination key through the sorted planner, then resources. Remote acquisition reversal needs the same delegation as acquiring. Each compensating leg and average restore posts in its owner's business, exactly once.

Blockers (unchanged rules, now over local and remote legs):
- any payment history
- any later transaction at either leg's product/location (consumption then replenishment included)
- later serial history or foreign claims
- return apportionments
- any later or intervening cost event in the owner's ledger

### Lock participants
| Operation | Coordination keys |
| --- | --- |
| Transfer dispatch (consignment) | (source business, product), plus receipt-detail row locks |
| Attribution change | (selling business, product) |
| Billing confirmation submit/approve | (origin, product) plus (seller, product) for remote sources |
| Buyout approve/finalize/reverse | (origin, product) plus (each owner/holding or selling business, product) |
| Customer return receive/declare | (return business, product) plus (sold-source business, product) — unchanged |

No change was needed to unrelated business operations or to POS source ordering.

## Operator guide, UAT and deployment (phase 7)

### Operator steps
1. **Consignment transfer:** in the V3 approval workspace, confirm the *Lot Penerimaan Konsinyasi* panel (sole lot prefilled, or enter quantities per lot), then approve. Serialized goods need no extra step. [`ConsignmentTransferProvenanceTest`]
2. **Historical reconstruction**, once per affected product, before relying on remote billing or buyout:
   ```
   php artisan consignment:reconstruct-provenance --product=<id> [--origin=<setting>]
   php artisan consignment:reconstruct-provenance --product=<id> --confirm --hash=<printed hash> --actor=<user id> --reason="..." [--choices='{"<dispatch alloc id>":{"<receipt id>":qty}}']
   ```
   The preview writes nothing. Confirm requires `consignments.provenance.reconcile` in the origin business. Unresolved legs stay listed and keep blocking only dependent consignment actions. Repeating is a no-op. [`ConsignmentHistoricalReconstructionTest`, `ConsignmentAxiooHistoricalFixtureTest`]
3. **Manual discovery:** run *Discover* (needs `consignments.provenance.reconcile` for remote reach) or `consignment:discover-sold-sources`. This binds remote serialized sales. Attribute non-serialized remote sales explicitly; a sole lot is proposed, never auto-applied. [`ConsignmentRemoteDiscoveryTest`]
4. **Supplier billing** of remote sales: the normal confirmation flow in the origin business. [`ConsignmentRemoteBillingTest`]
5. **Remote buyout:** pick an origin destination plus one standard destination per holding business, review "Menjadi milik", then draft, approve and finalize. Needs `consignments.buyouts.acquire_remote` plus membership of the holding business. [`ConsignmentRemoteBuyoutTest`]
6. **Supplier privacy:** supplier-facing Purchase/payment pages show only the origin's references. Reconciliation shows *Kustodi penerimaan* per location and business.

### AXIOO (local data, read-only observation)
After reconstruction and discovery the expected result is:
- HYPE 1: 43 local net sold + 3 PERDANA sold + 4 local unsold = 50
- HYPE 2: 22 sold + 1 local + 2 PERDANA unsold = 25
- Existing bills keep 26/15 consumed under TOP IT.

Do **not** run reconstruct or discover against production as part of deployment; it is an operator action after UAT.

### Human UAT (required; not automated)
- Local and remote buyout (including reversal)
- Transferred-sale billing
- Non-serialized ambiguity (choices)
- Supplier outputs (show/print/payment/export)
- Partial returns at the remote business
- Forbidden consignment ↔ standard transfers

### Deployment
1. Keep `consignment.buyouts.ui_enabled` and `consignment.buyouts.finalization_enabled` **off**. The predecessor's exposure checklist and human UAT gate still apply unchanged (`openspec/changes/buy-out-unsold-consignment-stock/uat-and-deployment.md`).
2. **Quiesce every cost writer** if migration `2026_11_10_000004` (cost ledger triggers) is (re)run in the same deploy. The predecessor's writer-quiescence rule is unchanged. The new migrations `2026_11_11_000001..000002` are additive and create no triggers.
3. Run `php artisan migrate` for schema changes, then `php artisan db:seed --class="Modules\User\Database\Seeders\PermissionsTableSeeder"` to synchronize permissions from `app/Config/Permissions.php` and grant the configured permissions to Admin. There is no separate permission migration.
4. Deploy code. Consignment-source transfers immediately require lot confirmation; ordinary transfers and POS are unchanged. Already-dispatched V3 consignment transfers settle normally.
5. Grant `consignments.provenance.reconcile` / `consignments.buyouts.acquire_remote` to named roles only after UAT.

### Rollback
- Code rollback is safe while the new tables are empty.
- After any provenance, binding, mapping or remote acquisition exists: migrations refuse to roll back. Disable the flags and forward-fix, or use eligible audited buyout reversals. Never delete mappings or regenerate old bills.

### Verification record
- SQLite consignment baseline (`php artisan test -c phpunit.consignment.xml`).
- Isolated MySQL 8.0.44 (`bash scripts/run-mysql-sequence-tests.sh --testsuite "MySQL Consignment Buyout Races"`): **43/43 OK**. This includes six remote races (transfer vs buyout, origin vs remote discovery, legacy vs buyout remote share, payment vs remote reversal, remote return vs finalization, remote owner cost change vs reversal), each won by both sides.
- SQLite consignment baseline: 1030/1030. Strict OpenSpec validation: both changes valid.
- Known pre-existing (not this change): an ordinary Purchase receiving can write a cost-ledger row for the product-owner business via `updateAveragePurchasePrice()`. See handoff risk 1.

## Spec sync / archive compatibility (8.3)

`buy-out-unsold-consignment-stock` (unarchived, UAT outstanding) and this change both MODIFY two `consignment-sales-allocation` requirements:
- "Sold and receipt capacities are enforced atomically"
- "Reconciliation exposes custody-to-allocation balances"

This change's text contains the predecessor's statement and **every** predecessor scenario verbatim, and only adds scenarios: shared receipt balance across transfers/remote billing; proven transfers explain apparent shortages; internal vs supplier-facing views. The predecessor's third modified requirement ("Physically effective pre-billing returns reduce eligibility") and its `consignment-custody-receiving` / `consignment-stock-buyout` deltas are not touched here.

**Archive order:** predecessor first, then this change. Archiving in the other order would let the predecessor's shorter MODIFIED text overwrite the added scenarios. Re-diff both blocks at archive time if either changes.

Implementation compatibility, all covered by the green SQLite baseline and the MySQL races:
- predecessor capacity (legacy + buyout exactly once), returns/apportionment, serial claims, sold-source v1/v2 hashes, permissions and exposure flags
- predecessor approved-revision hashes for local buyouts (the owner key is added only for remote lines)
- cost-event replay (owner-aware keys, legacy rows owner = header)
