# Design

## Context

See `proposal.md` for scope and the four spec deltas for behavior. The target is the existing Laravel 10/Livewire 3 ERP. The working tree was clean before this planning change. `buy-out-unsold-consignment-stock` is implemented but unarchived and has human UAT outstanding; this design extends that implementation, not its outstanding release approval.

Observed implementation constraints:

- `ConsignmentSoldSourceDiscoveryService::discoverForSetting` selects dispatches by the current location business. TOP IT discovery cannot see a PERDANA dispatch, and a manually discovered remote source would currently belong to PERDANA.
- `ConsignmentReceiptAllocationService::resolveSerialLineage` rejects receiving setting/location different from the sale setting/location. This is appropriate without transfer proof but rejects proven transferred serials.
- `ConsignmentBuyoutCapacityService`, `ConsignmentBuyoutLifecycleService`, and `ConsignmentBuyoutFinalizationService` assume receipt business, custody business, and inventory-cost business are identical. Whole-receipt residual versus original-location stock incorrectly treats remote custody/sales as shortages.
- `TransferMovementSerial`, immutable movement history and serial histories retain serial identity and source/destination provenance. V3 `TransferMovementAllocation` additionally freezes applied routes and business/tax identities; older transfer evidence requires a version-specific adapter rather than inventing V3 rows.
- Transfer receipt/cancellation/return executors already post stock and serial effects. Ordinary routes cannot cross consignment/standard classification; this restriction remains.
- POS allocation has an explicit configured source order and source-business tax policy. These are read-only inputs here. Sales/POS dispatch evidence already records which product, location, business, quantity and serials actually moved.
- Existing buyout coordination rows and `ConsignmentLockPlanner` provide a sorted complete-resource retry protocol. MySQL REPEATABLE READ requires latest locking reads for authoritative capacity checks after acquiring coordination locks.
- Existing buyout cost evidence is unique by buyout/product/kind and costing uses header setting. Remote acquisitions require grouping by inventory-owning business as well, not changing the supplier Purchase owner.

Observed local case (read-only investigation; fixtures for acceptance, not permission to repair data):

| TOP IT receipt TPI-CRN-2026-08-00001 | Received | Net local sales | Remote sales | Local unsold | Remote unsold |
| --- | ---: | ---: | ---: | ---: | ---: |
| AXIOO HYPE 1 | 50 | 43 | 3 | 4 | 0 |
| AXIOO HYPE 2 | 25 | 22 | 0 | 1 | 2 |

Transfers `TSM-2026-10-0021`, `TSM-2026-10-0162`, `TSM-2026-10-0329`, `TSM-2026-10-0550`, and `TSM-2026-11-0042` explain all movements. Remote sales are `PD-JL-2026-10-00436`, `PD-JL-2026-10-01916`, and `PD-JL-2026-11-00498`. They currently have no stored consignment sold sources. Six existing CANDRA consignment Purchases all belong to TOP IT, covering 26 HYPE 1 and 15 HYPE 2; no wrong-business bill was found. The non-serialized ALFA INK receipt has 20 received/20 on hand and no transfer: there is no historical non-serialized transfer requiring local data repair.

## Goals / Non-Goals

**Goals:** Preserve one original supplier obligation across custody locations; allow origin billing for remote sales and remote unsold acquisition; make every physical/financial capacity traceable without coupling checkout to supplier attribution.

**Non-Goals:** Production changes to POS cart/checkout/allocation/posting or sales transaction semantics; automatic discovery; FIFO/LIFO supplier selection; automatic inter-business payables, stock-debt settlement, invoices or payments; changing ordinary transfer taxes/routes; rewriting existing stock, transfers, sale/payment evidence, or v1/v2 hashes; unrelated test backlog or the independent POS location-authorization changes.

## Decisions

### 1. Separate four identities instead of relaxing tenant checks

| Identity | Authority |
| --- | --- |
| Original billing business and supplier | Immutable approved consignment receiving detail and receival |
| Custody business/location | Latest completed receipt-backed transfer path or explicit transit/held state |
| Selling business/location | Actual persisted DispatchDetail and source Sale/checkout evidence |
| Inventory owner after unsold buyout | Holding business at acquisition, with standard destination owned by that same business |

A supplier Purchase keeps the original receipt setting. Internal provenance records may span businesses, but a foreign location ID alone never grants permission. Do not simply remove setting/location predicates from serial lineage or copy the supplier receipt into PERDANA. Verification proves the chain from original receipt to actual sold/custody location.

Keep one sold-source envelope per DispatchDetail, retaining actual selling/custody identity; create separate receipt-backed obligation bindings for each original supplier/business contribution. One dispatch may legitimately consume multiple receipts/business obligations. The origin business discovers and views bindings relevant to its receipts; the selling business retains its transaction and authorized custody view. This prevents duplicate source records or rewriting sales into the supplier-paying business.

### 2. Add an append-only receipt-custody/provenance layer

Introduce conceptual Consignment records (final names follow module conventions):

- Transfer-lot selections: exact receipt detail, source custody lot, product, positive base quantity, optional serial, source/destination, and approved selection revision. Persist confirmation before consignment dispatch; no effect for ordinary transfers.
- Provenance events: original receipt, event kind, exact source transfer/movement/allocation/serial or sale/return/buyout reference, source/destination custody, condition/bucket evidence, signed quantity and actor/effective timestamp. Unique source-event identity makes replay idempotent.
- Obligation bindings: sold envelope plus original receipt and billing setting, attributed quantity/serials, custody path/version/hash, relevant returns, and dependency revision. Enforce exact `(sold occurrence, receipt)` residual including legacy pending/approved and buyout reserved/finalized quantities.
- Historical mapping decisions: preview evidence hash, confirmed mapping, operator/reason, source references and unresolved blockers. Store immutable revisions; dependent billed/reserved mappings cannot move.

Use approved receiving details as financial roots; mutable balance caches, if any, are rebuildable projections, never the authority. Physical custody keys include original receipt, actual location/business, product, condition, tax bucket and transit/held state. Existing immutable transfer allocations remain the physical movement authority; consignment events attach to them rather than duplicate stock transactions.

Quantities retain existing supported base precision; do not manufacture serials. V3 transfer posting still supports only its current whole-unit contract; do not silently extend or integer-truncate fractional transfer quantities. Non-serialized fractional buyout remains supported where its current flow supports it.

Reconcile physical events as disjoint dispositions: supplier-owned sellable custody, supplier-owned held custody, supplier-owned transit, net sold exits, and acquired-unsold exits. Billed sold quantity is a financial classification of the sold exit, not an additional physical deduction. Bought-out customer returns remain outside supplier availability; unacquired effective returns leave sold exits and enter held custody, becoming sellable only under existing release policy. Track unexplained loss/corruption as blockers rather than forcing equality.

### 3. Narrow transfer integration, no cashier integration

For future consignment transfers, preparation proposes a sole eligible receipt lot for explicit confirmation, or requires quantities across selected lots. Exact serial selections already identify the lot after chain validation. Multiple origin suppliers/businesses can share one custody location; retain separate receipt balances even if ordinary ProductStock pools them.

At dispatch, validate complete receipt-backed quantity under the same stock/claim/coordination locks and record source custody to transit atomically with the existing stock decrement. At receipt, close exact transit quantity and post destination custody alongside the existing stock receipt. Cancellation and supported transfer returns compensate the original allocations once. Preserve the current transfer route/tax classification and active serial-claim rules; no ordinary consignment-to-standard movement is permitted.

Support current V3 execution and the versioned historical V1/V2 movements needed by the existing AXIOO data through explicit adapters. Future executable legacy consignment routes must call the same provenance validation/posting boundary; do not silently allow an unguarded version to bypass tracking. Legacy unsupported or insufficient evidence is visible and blocks dependent consignment actions. Ordinary non-consignment routes do not gain lot prompts or provenance writes.

**Implementation deviation (V1/V2), recorded during apply.** A read-only check of local data found the AXIOO transfers TSM-2026-10-0021/0162/0329/0550 and TSM-2026-11-0042 are all V3. No V1 rows exist, and the two V2 transfers have no consignment origin. So no historical V1/V2 consignment movement needs an adapter. Integrating posting into the V1/V2 executors would add an untested write path that real data never uses. Instead:
- V1 (`TransferMovementService::dispatch` / `dispatchReturn`) and V2 (`ForwardDispatchApprovalExecutor`, `ReturnDispatchApprovalExecutor`) **refuse new dispatches whose origin is a consignment location**. They refuse before any stock effect, with an actionable message: cancel or archive the legacy document, create a new transfer (V3 when `STOCK_TRANSFERS_V3_CREATION_ENABLED` is on), and confirm its receipt lots.
- Receiving already-dispatched legacy documents is not guarded, so nothing is stranded.
- Ordinary non-consignment V1/V2 dispatches are unchanged.
- `ConsignmentTransferEvidenceAdapter` reports any V1/V2 consignment origin as an `UNSUPPORTED_WORKFLOW_VERSION` blocker. Pre-provenance V3 legs are adapted and reconstructed preview-first.

Revisit only if a real V1/V2 consignment movement is found; the adapter then needs version-specific evidence, not invented V3 rows.

POS still selects stock and posts exactly as before. A non-serialized sale may make per-receipt custody allocation unresolved until manual discovery/reconciliation; that is allowed for checkout. Read actual existing dispatches when calculating pool completeness so stale provenance does not make a later transfer/buyout consume already sold units. When a multi-lot pool is ambiguous, back-office attribution is required before affected transfer dispatch or financial acquisition; never introduce this requirement into checkout. A provisional aggregate physical difference can be shown, but it must not become guessed receipt attribution.

No new automatic sold-source event listener or scheduled discovery. All discovery and mapping is triggered manually by the existing consignment back-office action/command. Transfer posting hooks only preserve consignment custody; they do not synthesize customer sales or supplier bills.

### 4. Manual cross-business discovery and immutable compatibility

Expand candidate discovery for an original receipt business to locations reachable through proven receipt custody, rather than querying every foreign sale globally. A serial sold at PERDANA resolves via original receipt pivot plus exact completed movements/history up to dispatch time. Current serial location alone is insufficient after subsequent returns or transfers. For non-serialized candidates, use confirmed receipt-backed custody lots, expose unresolved selling-location pools, and let reconciliation staff select the lot quantities. A sole eligible lot is proposed, never silently financially allocated.

New envelopes use an explicit supported snapshot version for actual dispatch facts; supplementary obligation/provenance bindings carry their own canonical hash and revision. Keep legacy source rows, version-specific canonical payloads and original hashes unchanged. A previously captured remote v1/v2 source can gain a verified sidecar binding without changing its header setting or hash. Stable source-plus-binding identity prevents origin and remote discovery from duplicating the same quantity. Newly discovered dispatches also have one envelope even when several original businesses fund the sale.

Submit/approve/finalize validate actual dispatch authority using the source's version, then binding receipt/product/path/quantity, original billing authority, effective return history, and exact source-receipt capacity. Verified cross-business access is a narrowly scoped Consignment projection, not global access to foreign sales/customer data. Unknown version, incomplete transfer chain, wrong serial/product, unavailable receipt, unexplained physical mismatch, or inconsistent attribution is a blocker.

### 5. Supplier billing remains origin-owned and internal details stay internal

Confirmation/buyout headers group by one original supplier and billing business. Their sold lines may point to foreign-selling envelopes only through verified obligation bindings. Shared financial capacity remains keyed to original receipt and exact sold occurrence/receipt pair across all locations and both legacy/buyout paths. Preserve original legacy consumption; do not reconstruct six old CANDRA bills as new acquisition or debit events.

Use the original business's invoice/tax/sequence/permission and standard Purchase payment behavior. Existing sold-only conversion stays inventory-inert; remote sold slices cause no stock/cost change. Supplier-facing bill/payment/print/export projections use original business identity and omit remote business names and internal sale/transfer/customer references. Authorized internal screens retain full provenance. Shared receipt/provenance attachments are not automatically copied onto supplier-facing output with foreign internal labels.

Internal traceability is not inter-business financial accounting. Do not emit invoices, monetary payables, stock-debt settlement events, commissions, or payments from this layer. Do not make `add-cross-business-stock-debt-balances` a prerequisite or mutate its independent transfer semantics. If deployed, its own transfer receipt listener remains authoritative for its existing behavior; consignment replay must not invoke it again.

### 6. Remote buyout extends the existing ownership poster

Retain the original buyout header setting as billing business. Add explicit custody/inventory-owner setting evidence to each unsold slice and its posting lineage. Require an active consignment source with sufficient receipt-backed local custody, and an active standard destination belonging to that holding business, not necessarily the header business. Do not physically transfer remote goods back to TOP IT or move them into TOP IT's standard warehouse merely to satisfy an old validation.

Acquire all receipt, original-business and holding-business resource locks first. Create one original-business Purchase, details and original receipt lineage. Move each unsold slice between consignment and standard stock within its holding business, maintain the existing global physical quantity, preserve serial receipt identity, and write separate acquisition ownership evidence. Existing reservation guards already use product/location claims; extend Consignment reservation discovery/coordination to include the holding business without changing POS source selection/posting. Preserve full rollback/idempotency guarantees and verify with existing POS entry points read-only regression tests.

Group cost adjustments by `(inventory_owner_setting_id, product_id)` rather than header setting/product. Update cost-event uniqueness and replay indexes accordingly so one invoice buying the same product in two businesses produces two independent cost events. For each owner use the existing carrying-value replacement formula `(Q*A - q*A + C)/Q`, with that owner's locked Q/A and the invoice-allocated C of only its unsold slices. Both location movements remain in that owner setting; TOP IT gets no stock or average adjustment for PERDANA's acquisition.

Financial invoice tax and inventory-cost amount C derive from the accepted original-business supplier invoice policy; physical bucket/serial classification remains authoritative from the holding location and prior transfer policy. Do not overwrite transfer tax history or recalculate historical PERDANA customer tax. Allocate global discounts once across all slices, then group inventory cost by owner; supplier payable totals and owned-cost allocations reconcile without double counting. Keep last-purchase fields and historical sales costs unchanged as in the base buyout flow. Replay zero-quantity cost events only in the relevant inventory business and never infer a new receipt from the original-business Purchase.

### 7. Returns, reversals, and dual-business authorization

Customer returns remain attached to actual selling-business sale/dispatch evidence. Unacquired goods retain original supplier responsibility while held at the real return location; their effective returns reduce only unbilled original supplier eligibility. Exact serials or explicit non-serialized apportionment preserve original lot capacity. Acquired goods remain owned by their holding/selling business, with both lower and upper cumulative ownership bounds enforced. Preserve standard held-custody/inspection release and existing POS return policy; no POS transaction changes are required.

Supplier-facing actions require original business access. Transfer selection requires its existing source/destination authority. Remote ownership acquisition additionally requires explicit delegated consignment acquisition authority for the affected custody business; existing access to TOP IT bills alone does not authorize arbitrary PERDANA inventory changes. Limit internal foreign references to the actual contributing receipts/dispatches. Return actions retain their selling-business permissions and do not require cashier awareness of supplier identity.

A full buyout reversal keeps the original Purchase-first payment lock, then coordinates every affected original/custody/inventory-owner product set in sorted order using the existing complete-resource retry planner. Check all remote movements/claims/returns and price-ledger positions, including changes that restore the same average, as dependencies. Any historical supplier payment or incompatible local/remote downstream use blocks reversal. Eligible reversal compensates all businesses' physical/cost/ownership and original financial effects together. Receiving reversal and location reclassification also inspect remote custody, transit and obligation dependencies.

### 8. Historical reconciliation is an explicit operator operation

Provide read-only preview and explicit confirmed reconstruction through back-office command/UI, with filters by original receipt and source references. Do not run historical repair in a migration or discovery request implicitly. Confirm verified serialized chains from immutable receipt pivots, movement serials/history and actual dispatch/return facts; write only new provenance/mapping events and missing manually discovered sold envelopes.

AXIOO acceptance fixture uses the observed transfer references and a synthetic equivalent data set with the same quantities. HYPE 1 reconciles 43 net local sales + 3 remote sales + 4 local units = 50. HYPE 2 reconciles 22 sales + 1 local + 2 remote = 25. Existing billed quantities 26/15 remain consumed under TOP IT, with remote three becoming eligible only after manual discovery. Repeated preview/confirm/discovery changes no existing stock, serial location/status, transfer, customer sale/payment, or old bill. A local four-unit HYPE 1 selection is not blocked merely because earlier receipt custody moved to PERDANA.

Historical non-serialized transfer attribution must be explicit when evidence does not identify a sole lot. A sole lot can be proposed for confirmation; never auto-assign FIFO. No such historical transfer currently requires local repair, but add synthetic ambiguous fixtures to guard future behavior. Support multi-hop, partial return-to-origin, partial receipt and cancellation paths according to the source transfer version; unresolved historical evidence remains visible and blocks only dependent consignment operations.

## Risks / Trade-offs

- [Origin liability differs from inventory ownership] → Name all identities explicitly in schema/UI and test original Purchase versus remote stock/cost; do not generate unapproved internal accounting.
- [One sale contains several receipt-business obligations] → Keep one immutable sale envelope and quantity-bounded obligation bindings; never duplicate sold-source quantity per origin.
- [Non-serialized POS sales temporarily leave lot attribution unresolved] → Accept checkout unchanged, show unresolved back-office quantity, and block only dependent transfer/billing/buyout until attribution is confirmed.
- [Historical transfers span workflow versions] → Use versioned evidence adapters and preview-first reconstruction; unknown or incomplete chains remain blockers rather than silently changing balances.
- [Remote claims or averages race posting] → Reuse sorted complete-resource locking and latest authoritative MySQL reads for origin and every affected custody/owner business; test both winner orders.
- [Supplier output leaks remote identities] → Separate supplier-facing projection and internal detail, test print/export/payment output, and do not auto-export internal attachments.
- [Unarchived predecessor deltas overlap] → Apply after the implemented buyout baseline, reconcile overlapping capacity/reconciliation deltas against the predecessor before syncing/archive, and preserve its return/claim/source protections.
- [Independent POS/stock-debt changes are in flight] → No production POS edits, no dependency on stock-debt settlement, targeted consignment-only hooks, and explicit compatibility regression snapshots.

## Migration Plan

1. Verify implemented buyout schema/services and retain the predecessor's human UAT/exposure requirements. Add custody/provenance/binding schema and remote-owner columns/indexes without changing historical hashes or existing business IDs. Refuse destructive rollback when dependent evidence exists.
2. Seed/propose direct custody from approved receipts only as an auditable, repeatable operation; preview existing movement chains before confirming. Migrations do not modify operational stock or silently run discovery.
3. Deploy Consignment services and narrow transfer-only hooks atomically for executable consignment routes. Require receipt selections at actual dispatch boundaries; ordinary transfers and POS remain unchanged. Block unsupported incomplete legacy chains from dependent actions.
4. Deploy original-business discovery/financial projection and remote acquisition/return/reversal support together before exposing remote buyout. Verify dual-business permissions and all current mutation gates remain honored; no flags are enabled by migration.
5. Validate synthetic and local-reference historical previews, upgrade fixtures, locked SQLite baseline and isolated MySQL races. A human confirms UAT for local and remote buyout, transferred sales, non-serial ambiguity, supplier output, partial returns and forbidden ordinary classification-crossing transfers.
6. Roll back empty new schema only. After provenance/billing/acquisition exists, disable new actions and forward-fix or use eligible audited reversals; do not delete movement mappings, regenerate old supplier bills, or revert to origin-only capacity checks beneath live remote records.
