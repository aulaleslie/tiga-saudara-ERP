# Tasks

All work is limited to consignment and shared entry points needed to protect its invariants. Keep unrelated repository failures enabled and outside scope. Each group includes its own focused tests; the final group is for integration acceptance. Do not expose finalization until all reservation consumers, ownership return routing, and source guards are deployed together.

**Revised dependency order (agreed during implementation):** finish group 2 -> build group 4 lifecycle/reservation domain services (no production approval/reservation action exposed) -> integrate group 3 consumers and lock protocol alongside those services, using real competing operations -> expose approval only once every stock consumer respects reservations -> keep finalization (group 6) unavailable until all integrations are complete. MySQL race verification (1.4, 3.6, 8.5, 10.4) stays unchecked until an isolated MySQL environment actually runs; SQLite results are never concurrency proof.

## 1. Additive schema and source contracts

- [x] 1.1 Add buyout header, receipt-backed sold/unsold lines, exact serial selections, revision/audit fields, and nullable unique Purchase link under Consignment migrations; verify fresh SQLite migration and model relationship tests enforce supplier/business/receipt identity and decimal quantities.
- [x] 1.2 Add explicit non-financial sold-receipt attribution, reservations, ownership/movement/cost evidence, Purchase lineage, and reversal linkage; verify duplicate posting/Purchase/reversal constraints and lossless mixed-receipt fixtures.
- [x] 1.3 Extend shared active serial claims to identify legacy or buyout owners without weakening legacy sold-source FKs; verify legacy claim upgrade fixtures and cross-path duplicate serial rejection.
- [x] 1.4 Add unique setting/product coordination rows and safe creation/locking support; verify concurrent row creation in isolated MySQL and deterministic multi-resource ordering in focused tests.
- [x] 1.5 Protect populated-schema rollback and document deployment/rollback order in change-specific testing documentation; verify rollback refuses existing claim, buyout, lineage, or posting evidence without partial DDL destruction.

## 2. Explicit supplier attribution and shared capacity

- [x] 2.1 Implement pool discovery completeness and non-financial sold-receipt attribution, reusing unambiguous historical allocation evidence without rewriting snapshots; verify unknown versions, unsupported dispatches, missing lineage, and incomplete discovery block affected buyouts.
- [x] 2.2 Implement explicit non-serialized attribution editing and effective-return apportionment with immutable dependency checks; verify the design's A=10/B=8/sold=6 example and prove attribution for B creates no B invoice or financial allocation.
- [x] 2.3 Implement shared receipt and sold-source capacity including legacy waiting/approved and buyout approved/finalized states exactly once; verify draft/rejected/cancelled/reversed exclusions, duplicate slice aggregation, and conservation mismatch errors.
- [x] 2.4 Implement physically available unsold capacity by receipt provenance plus location condition/tax buckets and operational claims; verify financial residual alone cannot permit purchase, held returns cannot be selected, and ambiguous moved/damaged stock is blocked.
- [x] 2.5 Integrate shared capacity/attribution into legacy confirmation submit, approve, conversion validation, and reconciliation; verify old snapshot hashes and submission-time reservation behavior remain unchanged and conflicting supplier assignments fail atomically.
- [x] 2.6 Document capacity equations, effective-return treatment, and reconciliation blockers alongside the feature tests; verify examples reconcile received, sold, acquired, reserved, and available balances without negative clamping.

## 3. Shared locking and reservation-aware stock consumers

- [x] 3.1 Implement the design's resource-lock planner and complete-resource retry protocol for legacy/new consignment receiving, allocation, conversion, cancellation, and reversal; verify no late earlier-key acquisition and no partial writes on changed resource sets.
- [x] 3.2 Integrate coordination locks and claim-aware quantities/serials into real Sales dispatch approval and rejection/cancellation paths; verify dispatch cannot consume buyout-reserved stock and can consume unreserved stock without altering claim ownership.
- [x] 3.3 Integrate reservation-aware availability and authoritative posting checks into POS stock resolution/finalization, including bundles and split-owner sources; verify real checkout respects claimed serials/buckets and rolls back completely on contention.
- [x] 3.4 Integrate affected transfer dispatch, stock-opname/adjustment, serial-state operations, and their cancellation paths with the shared planner and availability authority; verify reserved goods cannot move or be reclassified and ordinary consignment-to-standard transfers stay prohibited.
- [x] 3.5 Integrate affected Purchase receiving/cost changes and Sales/POS return receive/release with coordinated setting/product locks; verify average changes and return evidence cannot race past acquisition/reversal validation.
- [x] 3.6 Publish the participant/lock-order matrix in change-specific testing documentation and add isolated MySQL buyout-versus-dispatch/checkout/return/transfer/receiving races; verify losers roll back without lost claims or deadlock-driven partial posting.

## 4. Buyout lifecycle, permissions, and approved evidence

- [x] 4.1 Implement draft/submit/reject/revise/approve/cancel transitions and canonical revision hashes; verify invalid transitions, stale rows, duplicate approvals, and later-line failures leave no partial state or duplicate audit.
- [x] 4.2 Reserve all receipt/sold/physical/serial resources on approval only, and consume/release them through explicit transitions; verify waiting documents reserve nothing, approved claims never auto-expire, and cancellation preserves stock/cost/payables.
- [x] 4.3 Add dedicated buyout action permissions and unsold-acquisition authority, composed with existing approval/billing authority and business access; verify sold-only billers gain no implicit buyout access and direct controller/service violations return 403 without mutation.
- [x] 4.4 Add exact serialized receipt selection and active-claim checks; verify fractional serialized quantities, duplicate texts within product identity, wrong supplier/product/location, dispatch/return conflicts, and one bad serial roll back approval.
- [x] 4.5 Add immutable lifecycle audit and existing document notification conventions; verify actor/time/reason/revision and no stock, cost, Purchase, or payment mutation before finalization.

## 5. Invoice pricing and inventory-cost calculation

- [x] 5.1 Adapt the existing consignment pricing calculator to receipt-backed slice identities and invoice metadata; verify preview and accepted terms agree for full/partial mixed receipts, row overrides, percentage/fixed discounts, and invalid monetary inputs.
- [x] 5.2 Implement persisted PKP/non-PKP financial tax controls and proportional original evidence; verify forged non-PKP tax is neutralized, PKP unchanged terms reproduce original amounts, and invoice tax cannot relabel physical buckets/serials.
- [x] 5.3 Allocate document discounts deterministically across sold and unsold slices and derive accepted inventory DPP amounts; verify exact header reconciliation, rounding residuals, zero-cost legitimate invoices, and rejection of negative/unreconciled cost.
- [x] 5.4 Implement shared carrying-value replacement per setting/product using locked Q and A, aggregated unsold q and C; verify all design costing examples, multiple destinations/receipts for one product, fractional quantities, sold-only no-op, other-setting isolation, and missing-average blockers.
- [x] 5.5 Document original invoice versus inventory amounts and retained last-price policy; verify tests show last-purchase fields and historical Sales/POS HPP unchanged.

## 6. Atomic ownership acquisition and Purchase integration

- [x] 6.1 Implement dedicated decimal-safe unsold ownership posting with paired source/destination transactions, exact buckets, serial movement/history, and preserved receipt provenance; verify location balances, unchanged global/setting physical quantity, and absent destination-stock creation.
- [x] 6.2 Implement finalization as one transaction creating a single CONSIGNMENT_BUYOUT Purchase, details, lineage, movement/cost evidence, audit, and consumed reservations; verify mixed five-billed/three-moved scenario and fault injection after each later posting stage.
- [x] 6.3 Implement unique posting/idempotency validation and retry behavior; verify repeated requests return the same Purchase, incompatible retries fail, and isolated MySQL competing finalizations create one complete posting set.
- [x] 6.4 Extend Purchase/Detail source guards and dedicated reversal allowance without routing buyouts through legacy confirmation validators; verify ordinary receiving, commercial edits, correction, supplier returns, deletion, and archival remain denied for both financial source types.
- [x] 6.5 Connect normal/global Purchase payments, administrative dates/notes/references/attachments, and source presentation to buyout lineage; verify partial/full payment balances, canonical payment-status values, immutable approval evidence, and no payment-triggered inventory movement.
- [x] 6.6 Preserve authorization exceptions on relevant consignment Purchase status endpoints; verify HTTP 403 and unchanged data for unauthorized real buyout and legacy consignment-billing Purchases, with business-transition errors retaining ordinary handling.

## 7. Ownership-aware customer returns

- [x] 7.1 Persist and validate a standard same-business future-return destination for sold slices and trace sold/unsold acquisition ownership; verify purchased goods cannot create a second consignment bill when sold through real Sales/POS.
- [x] 7.2 Implement acquired/unacquired non-serialized return apportionment and exact serial ownership lookup with cumulative limits; verify partial and mixed-ownership returns route correctly, ambiguous mapping blocks, and repeated receives do not duplicate ownership or quantity.
- [x] 7.3 Integrate StandardReturnCustodyService receiving/inspection release with acquired destination routing while preserving held stock; verify refund/settlement alone does not make stock sellable and authorized release posts acquired units only to standard stock.
- [x] 7.4 Integrate existing POS return execution and linked Sales Return eligibility with acquisition lineage; verify acquired returns do not reopen supplier capacity or rewrite dispatch/sold snapshots, and legacy/unacquired behavior stays compatible.
- [x] 7.5 Document acquisition-return routing and custody/inspection behavior with operator examples; verify real Sales/POS end-to-end return tests match the documented flows.

## 8. Reversal and receiving dependency safety

- [x] 8.1 Implement full-reversal eligibility preview with payment history, both movement legs, serial history/claims, return dependencies, and setting-product cost events; verify consumption-then-replenishment and invalidated historical payment still block reversal.
- [x] 8.2 Implement Purchase-first reversal locking and atomic liability cancellation, compensating stock/serial/ownership/cost evidence, restored capacity, and immutable reason/audit; verify repeat reversal, later-line rollback, and no ordinary deletion or partial reversal.
- [x] 8.3 Integrate cancelled buyout Purchases with canonical outstanding/aging/payment projections; verify collectible due becomes zero while original amounts/evidence remain and subsequent payment is denied.
- [x] 8.4 Extend custody receiving reversal dependencies for attribution, active reservations, and finalized non-reversed buyout sold/unsold slices; verify cancellation removes only its own blocker and matching current stock cannot bypass an acquisition dependency.
- [x] 8.5 Add isolated MySQL payment-versus-reversal and receiving-reversal-versus-buyout races; verify at most one incompatible operation commits and neither produces partial financial or inventory state.

## 9. UI, reconciliation, and historical report projections

- [x] 9.1 Add module routes/controllers and Livewire/Blade preparation screens for supplier/receipts, all/partial selection, attribution resolution, serials, per-source destinations, approved pricing, and blockers; verify permissioned navigation, direct-forgery rejection, and no silent selection substitution.
- [x] 9.2 Add review/approval/finalization/cancellation/reversal screens and notifications using existing Bahasa Indonesia conventions; verify status-appropriate actions, explicit confirmation, stock-neutral draft behavior, and protected final documents.
- [x] 9.3 Extend reconciliation/detail/Purchase views to distinguish attribution, physical reservations, billed sold/unsold, movements, returns, reversals, paid and due; verify grouped receipt/serial quantities reconcile once across legacy and buyout paths.
- [x] 9.4 Extend inventory quantity/valuation projections and transaction date/sign handling for acquisition/reversal event types; verify before/after/as-of location quantities and owned valuation without phantom receiving or backdating stock to invoice date.
- [x] 9.5 Extend historical cost replay with zero-quantity acquisition/reversal cost events and explicit buyout Purchase fallback exclusion; verify no second receipt, exact once-only cost adjustment, no automatic historical Sales HPP rewriting, and compatible legacy conversion results.
- [x] 9.6 Deliver operator/UAT and deployment/rollback documentation with full/partial, mixed, returns, payments, and reversal examples; verify every documented action has a matching feature scenario and supported permission.

## 10. Consignment acceptance and compatibility

- [x] 10.1 Add an upgrade/regression matrix for historical unversioned/versioned sold sources, legacy active serial claims, waiting/approved/billed confirmations, partial returns, and old tax snapshots; verify schema upgrade preserves hashes, original evidence, balances, and inventory-inert legacy conversion.
- [x] 10.2 Register external integration tests in phpunit.consignment.xml and MySQL buyout races in the isolated MySQL configuration/runner; verify list-tests discovers every new acceptance and race file without excluding unrelated backlog tests.
- [x] 10.3 Run bash scripts/test-consignment-baseline.sh through its shared SQLite lock after the final edits; record command, passing/failing/skipped totals, assertion count, and any concrete consignment limitations in the handoff.
- [x] 10.4 Run the isolated MySQL race matrix for buyout-versus-buyout, legacy allocation, dispatch, POS checkout, transfer, return, receiving/cost changes, duplicate finalization, payment, and reversal; record actual execution results and keep concurrency explicitly unverified if its required environment is unavailable.
- [ ] 10.5 Execute documented UAT with at least two suppliers sharing a product/location, a mixed sold/unsold invoice, exact serials, fractional stock, a customer return, partial/full payment, and eligible/blocked reversal; verify conservation, payable totals, owned destinations, and no second supplier bill.
- [x] 10.6 Validate the completed change artifacts and review the implementation diff against the consignment-only scope; record outstanding business conflicts rather than weakening tests or repairing unrelated default-suite failures. Independent reviewer sign-off and verification caveats are recorded in `testing.md` (2026-11-11).
