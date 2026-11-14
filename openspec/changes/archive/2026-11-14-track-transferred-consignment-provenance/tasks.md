# Tasks

Implementation applied by the agent. **Human UAT and archive pending (human-owned):** archive `buy-out-unsold-consignment-stock` first, then this change. See `handoff.md`.

Planning change only until explicitly applied. Build on the implemented `buy-out-unsold-consignment-stock` baseline and preserve its outstanding UAT/exposure gates. No production POS checkout/cart/allocation/posting edits, customer-transaction rewrites, automatic discovery, or inter-business payable generation. Narrow transfer integration is consignment-only. Every group includes focused verification; unrelated failing repository tests remain enabled and deferred.

## 1. Receipt-custody schema and identities

- [x] 1.1 Add additive Consignment transfer-lot selection, provenance-event, obligation-binding, and historical-mapping schema/models with original billing, custody, seller, and acquired-owner identities; verify foreign keys, exact supported quantities, duplicate source-event rejection, and lossless multi-receipt fixtures.
- [x] 1.2 Extend buyout slice/lineage/cost-event owner fields and unique keys to distinguish inventory business from header billing business; verify the same product can produce one cost event per affected owner and old records remain compatible.
- [x] 1.3 Implement receipt-rooted custody projections distinguishing sellable, held, transit, net sold and acquired exits; verify quantity conservation without double-subtracting billed sales, and reject unexplained negative balances.
- [x] 1.4 Add permission/delegation definitions for original receipt reconciliation and remote consignment acquisition without global foreign-business access; verify origin-only billers cannot mutate arbitrary remote stock.
- [x] 1.5 Document schema/identity and rollback contracts and add populated rollback guards; verify upgrade fixtures leave old receipt, sold-source hashes, bills, payments, serials, and stock byte-identical.

## 2. Consignment-only transfer provenance

- [x] 2.1 Implement exact serialized receipt-chain validation and non-serialized lot selection, with sole-lot proposal/confirmation and explicit multi-lot quantities; verify wrong supplier/product/location, insufficient lot capacity and unconfirmed selection are rejected.
- [x] 2.2 Add consignment-only preparation controls in the existing transfer workflow, preserving its draft/workspace versus execution boundaries; verify ordinary transfer screens gain no lot-selection requirement and all selected quantities are revalidated at dispatch.
- [x] 2.3 Post source custody to transit atomically with existing dispatch and claim writes; verify multi-receipt/multi-business dispatch, both tax buckets, source shortfall, later-line rollback, and repeated dispatch without duplicate provenance.
- [x] 2.4 Post exact transit to destination custody on receipt, and compensate custody on eligible cancellation/transfer return; verify no source/destination/transit double availability, partial legacy receiving where supported, multi-hop movements and return-to-origin.
- [x] 2.5 Integrate supported executable legacy consignment entry points through version-specific evidence adapters; verify the observed historical AXIOO movement shape and that no executable consignment path silently bypasses provenance checks.
  - Deviation (documented in design §3 and the stock-transfer delta): the AXIOO movements are all V3, so the V3 pre-provenance adapter covers them. V1/V2 consignment dispatches are **refused** with an actionable V3 path instead of being integrated. Ordinary V1/V2 is unchanged and receiving already-dispatched legacy documents is unguarded (`ConsignmentLegacyTransferCompatibilityTest`).
- [x] 2.6 Preserve existing classification and tax rules; verify ordinary consignment-to-standard and standard-to-consignment transfers remain rejected, consignment-to-consignment routes retain current tax behavior, and no supplier bill or stock-debt replay is created by provenance posting.
- [x] 2.7 Document lot selection and transit/cancellation/return semantics with fixture-backed examples; verify transfer operators can resolve ambiguous lots without changes to POS cashier steps.

## 3. Manual discovery and back-office sale attribution

- [x] 3.1 Extend manual discovery candidate selection through verified receipt custody reachability, retaining one actual sold-source envelope per DispatchDetail; verify TOP IT discovers relevant PERDANA sales, origin/remote repeated discovery is idempotent, and unrelated foreign sales stay inaccessible.
- [x] 3.2 Add versioned supplemental receipt/transfer obligation bindings and supported new-envelope canonical evidence; verify original v1/v2 hashes/setting identities stay unchanged and live authority validates through the correct version.
- [x] 3.3 Resolve remote serialized sales through receipt pivots and completed paths at dispatch time, including moved-back or later-returned serials; verify wrong product, missing path, transit-only, reversed receipt and substituted serial remain blockers.
- [x] 3.4 Implement explicit back-office non-serialized sold attribution against actual location receipt-backed custody, with sole-lot proposal for confirmation; verify no FIFO/LIFO and that POS completes unchanged before attribution while dependent consignment actions remain blocked.
- [x] 3.5 Enforce exact source/receipt residual across legacy waiting/approved allocations and buyout active/finalized consumption globally; verify one source split among original businesses is not duplicated and already-billed A cannot consume unbilled B's share.
- [x] 3.6 Preserve pool completeness and current physical-capacity safeguards without adding automatic discovery; verify a recent undiscovered POS sale cannot be reused by transfer/buyout and discovery reads do not mutate the original sale, dispatch, tax or payment.
- [x] 3.7 Document discovery-versus-attribution and original/remote permissions; verify manual commands/actions remain the only discovery entry points and no new event listener or scheduler silently invokes them.

## 4. Original-business supplier billing and privacy

- [x] 4.1 Extend confirmation and sold buyout validation to accept remote sold envelopes only through verified original-receipt bindings; verify Purchase supplier/business/sequence and invoice policy remain original-business scoped and actual selling transactions remain unchanged.
- [x] 4.2 Extend supplier billing and payment projections for mixed local/remote sources while preserving one original supplier/business per document; verify no duplicate supplier charge, no cross-origin consolidation, and old bills/paid balances remain unchanged.
- [x] 4.3 Separate internal lineage views from supplier-facing bill/payment/print/export projections; verify supplier outputs identify TOP IT only and exclude PERDANA names, foreign customer/sale/transfer references and automatic internal attachment export.
- [x] 4.4 Add scoped internal drill-down and explicit 403/foreign-data tests; verify relevant remote provenance is visible to authorized original-business staff without granting general remote sales/customer access.
- [x] 4.5 Document supplier versus internal evidence and the provenance-only settlement boundary; verify reconstruction, billing and acquisition generate no internal payable/invoice/payment or duplicate independent stock-debt event.

## 5. Local and remote unsold buyout

- [x] 5.1 Extend receipt capacity and proposal-all selection to actual custody locations, separating local/remote/transit/held and unexplained balances; verify TOP IT can select four local HYPE 1 units despite proven remote sales and select one local plus two remote HYPE 2 units without duplicate receipt consumption.
- [x] 5.2 Extend approval revision evidence, reservations and complete-resource lock discovery to original receipt and every custody/owner business; verify missing delegation, changed destination, stale path, resource-set growth and conflicting claims fail without partial reservations.
- [x] 5.3 Extend finalization to create one original-business supplier Purchase while posting unsold ownership movements only within each holding business; verify remote PERDANA stock becomes PERDANA-owned without physical transfer back, new Received Notes, or changed original serial receipt lineage.
- [x] 5.4 Group carrying-value replacement and price-ledger evidence by inventory-owner business/product, allocating invoice discounts once; verify mixed local/remote examples, different business tax policies, unchanged last prices and historical HPP, and no source-business cost adjustment for remote-only acquisition.
- [x] 5.5 Extend historical cost/quantity/valuation replay for owner-specific zero-quantity cost events and existing signed movement legs; verify correct event-time/as-of effects and no inferred receipt or double capitalization from the origin Purchase.
- [x] 5.6 Add failure injection and retry/idempotency tests across multiple owners, receipt lots, destinations and serials; verify one Purchase and exact posting counts, full rollback after the last remote slice, and existing exposure flags remain off unless explicitly enabled for tests.
- [x] 5.7 Document remote destination and inventory-owner semantics, invoice versus physical tax, and cross-business authorization; verify accepted UI preview identifies what each business will own and what only TOP IT will owe.

## 6. Returns, reversal, and shared locking

- [x] 6.1 Extend unacquired remote return attribution and standard held-custody routing through original receipt bindings; verify pre-billing returns reduce original-business eligibility once while actual selling/return business and inspection rules remain intact.
- [x] 6.2 Extend acquired customer-return routing to inventory-owner business with exact serial or explicit non-serialized apportionment; verify both ownership bounds, cumulative partial returns, direct/bypassed declaration validation, and no reopening supplier capacity.
- [x] 6.3 Extend dedicated full reversal and receiving-reversal dependency checks across local/remote stock, transfer paths, claims, returns and cost-ledger positions; verify payment history and remote consumption-then-replenishment block reversal, while eligible complete reversal restores every owner and original financial effect once.
- [x] 6.4 Reuse Purchase-first payment/reversal locking and sorted complete-resource retry for all affected origin/custody pairs, with latest authoritative MySQL reads; verify rollback/retry preserves notifications after commit only and cannot acquire earlier keys late.
- [x] 6.5 Add isolated MySQL multi-process races for cross-business transfer/buyout, origin/remote concurrent discovery, legacy/remote billing, remote return/finalization, remote cost changes/reversal, and payment/reversal; verify both winner orders with controls proving workers actually post, and record real results separately from SQLite.
- [x] 6.6 Document remote dependency blockers and lock participants; verify unrelated business operations and POS source ordering require no production changes.

## 7. Historical reconciliation and operator workflow

- [x] 7.1 Add read-only reconstruction preview and explicit confirmed mapping command/UI, with source hashes, permission checks, reason and append-only audit; verify preview writes nothing and repeated confirm/discovery creates no duplicate events or source bindings.
- [x] 7.2 Build the AXIOO historical fixture with referenced transfers and remote sales; verify HYPE 1 = 43 local net sold + 3 remote sold + 4 local unsold, HYPE 2 = 22 sold + 1 local + 2 remote unsold, and original billed quantities 26/15 remain consumed under TOP IT.
- [x] 7.3 Add historical non-serialized synthetic cases for sole-lot confirmation, mixed suppliers, ambiguous partial transfer, cancellation, onward transfer and return; verify unresolved evidence stays blocked and no current live data is repaired automatically.
- [x] 7.4 Extend internal reconciliation and buyout screens with original supplier/business, actual custody/seller, transit, pending attribution, physical and financial balances, and actionable references; verify explained remote quantities no longer appear as missing local stock.
- [x] 7.5 Deliver operator/UAT/deployment/rollback guidance for local and remote buyout, manual discovery, non-serial attribution and supplier privacy; verify examples match feature tests and preserve predecessor UAT gates and cost-trigger writer-quiescence requirements.

## 8. Acceptance and scope verification

- [x] 8.1 Run real Sales and POS integration fixtures through existing routes before/after transfer and acquisition; verify exact location order, source-business tax buckets, selected serials, checkout business splits, revenue/customer payments and receipt output remain unchanged with no supplier prompts.
- [x] 8.2 Register new external tests in phpunit.consignment.xml and isolated MySQL config/runner; run the locked consignment baseline and race suite after final edits, recording commands, totals and remaining limits without repairing unrelated failures.
- [x] 8.3 Review upgrade compatibility and overlapping predecessor spec deltas before later sync/archive; verify all prior buyout capacity, returns, claims, source protections, permissions and historical hashes remain represented without silently overwriting unsynced requirements.
- [ ] 8.4 (agent part done: final diff/spec review and handoff in `handoff.md`; **human UAT pending - do not mark complete until a person signs off**) Perform the final diff/spec review and human UAT for the AXIOO case, remote ownership, non-serialized ambiguity, supplier output and forbidden ordinary classification-crossing routes; verify no production POS transaction files or historical operational records were changed and keep exposure blocked until required verification passes.
