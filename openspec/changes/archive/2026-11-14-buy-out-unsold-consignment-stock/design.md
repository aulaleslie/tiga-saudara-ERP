# Design

## Context

See `proposal.md` for motivation and `specs/` for behavior contracts. This is Laravel 10, Livewire 3, nwidart modules, Eloquent, MySQL production, and a locked file-backed SQLite baseline. The latest reported consignment baseline was 741 passing tests; this planning run has not rerun it. The wider repository has unrelated known failures and is not a release gate for this change.

Observed constraints:

- `Modules/Consignment/Database/Migrations/2026_08_27_000001_create_consignment_sales_allocation_tables.php` makes confirmation and serialized allocation lines depend on sold sources. Existing `ConsignmentBillingConfirmationLifecycleService` validates versioned dispatch hashes and effective returns. Fabricating sold sources for unsold stock would defeat those contracts.
- `ConsignmentReceiptAllocationService::getEligibleReceiptPools` subtracts legacy waiting/approved financial allocations from approved receipt quantity. Its result does not prove physical unsold balance. Non-serialized `ProductStock` is pooled by product/location rather than supplier receipt.
- `ConsignmentReceivingService::approveReceiving` already increments physical quantities and includes custody goods in setting-scoped average cost; it does not change last purchase prices. Existing receipt reversal checks later transactions and serial histories as well as balances.
- `ConsignmentBillingConversionService` creates `CONSIGNMENT_BILLING` Purchases without inventory mutation. `PurchaseSourceGuard`, Purchase/Detail model guards, source presentation, and idempotency validators recognize that exact source and legacy confirmation lineage.
- Ordinary transfer validation blocks consignment-to-standard routes. `TransferV3InventoryPoster` uses integer quantities in several paths; consignment quantity supports three decimals.
- Warehouse valuation excludes consignment locations and values standard-location quantity at the shared setting product average. `HistoricalReplayEngine` can otherwise infer a receipt from a physically complete Purchase without a Received Note: buyout Purchases must not fall into that fallback.
- Sales dispatch and POS allocation currently read ordinary stock/serial availability, not buyout reservations. A buyout-only reservation check cannot prevent a concurrent sale.
- Standard Sales Return receiving uses `StandardReturnCustodyService` and inspection release, not immediate sellable receiving. POS return execution has its own existing policy and linked Sales Return evidence. Ownership routing must preserve both.

## Goals / Non-Goals

**Goals:** Give sold-only and buyout operations one capacity authority; distinguish financial receipt consumption, physical custody, and owned stock; post one durable financial/ownership event with compatible replay and reversal; provide directly testable conservation and locking invariants.

**Non-Goals:** Supplier advances, cross-business acquisition, lot-specific inventory valuation, historical snapshot or Sales HPP rewrites, ordinary transfer-policy relaxation, automatic payments, post-payment supplier returns/credits, automatic reservation expiry, and unrelated failing-test repairs. Do not include the independently discussed non-split POS checkout-ID correction in this change.

## Decisions

### 1. Add a separate buyout aggregate and lossless posting evidence

Add the following conceptual tables under Consignment migrations; exact SQL names follow module conventions:

| Record | Authority |
| --- | --- |
| `consignment_buyouts` | Setting, supplier, reference, state, revision, invoice metadata/intent, actor timestamps, unique nullable Purchase link, reversal link |
| `consignment_buyout_lines` | Positive base quantity, `SOLD_UNBILLED` or `UNSOLD_BUYOUT`, receipt detail, product, optional sold source, source location, destination or future-return destination, immutable original and accepted commercial snapshots |
| `consignment_buyout_serials` | Exact product serial identity and receipt lineage, unique within document; active exclusivity enforced through shared claim authority |
| `consignment_sold_receipt_attributions` | Non-financial mapping of sold-source quantities to explicit receipt details, revision/audit and effective-return apportionment |
| Buyout reservations/claims | Header revision, receipt/sold/physical-bucket resources, quantity or exact serial, active/consumed/released state |
| Buyout Purchase lineage/posting evidence | Purchase/detail to slice and receipt, serials, invoice allocations, signed movement links, ownership event, cost before/after snapshots |
| Append-only lifecycle/reversal events | Actor, reason, revision/hash, action, prior/result state, source/posting references |

Reuse/extend `ConsignmentActiveSerialClaim` as shared exclusivity authority instead of adding isolated claims invisible to legacy billing. Preserve old rows and constraints; add an explicit claim owner discriminator and mutually exclusive owner linkage if required. Database uniqueness protects active serial identity, one Purchase per buyout, one posting leg per slice/direction, and one reversal per finalization. Do not weaken mandatory sold-source fields in legacy tables. Buyout lineage stays separate from the legacy table whose confirmation FKs are mandatory.

Alternative rejected: nullable legacy sold-source relationships, fake dispatches, or reusing ordinary receiving would blur eligibility and double physical quantities. A separate aggregate limits snapshot compatibility risk while sharing calculational services rather than copying financial rules.

### 2. Separate sold attribution from billing and physical stock

A complete receipt attribution is required before approval of an unsold non-serialized slice for the same product/location. Preparation exposes all outstanding effective sales in that pool, including other suppliers; attribution for other suppliers does not put those suppliers on the invoice. Reuse unambiguous legacy allocation evidence as attribution input without counting it twice. Keep unbilled attribution revisable with audit until a reservation or finalization depends on it. No FIFO/LIFO or automatic supplier assignment.

Definitions per receipt `r`:

- Financial available = approved received quantity minus non-reversed finalized consumption, legacy approved allocations, and active legacy/buyout financial reservations. A finalized buyout is consumed, not also reserved.
- Physical supplier-owned residual = approved received quantity minus net physically-out sold attribution and finalized unsold acquisitions, adjusted only through authoritative receipt reversal/return evidence. Do not deduct a sold bill a second time after subtracting its attributed sale.
- Available unsold = physical supplier-owned residual minus active unsold acquisition reservations, further constrained by actual eligible product/location condition/tax buckets and competing operational claims.
- Available sold-source quantity = original eligible sold quantity minus physically effective unacquired returns, legacy approved/pending allocations, and new active/finalized sold buyout consumption.

All values must reconcile without negatives. A conservation mismatch blocks buyout and shows evidence; do not clamp an inconsistent ledger to zero and silently proceed. Historical movement ambiguity, receipt transfers without reliable lot attribution, unsupported source versions, or missing serial lineage similarly block affected selection.

Example: supplier A received 10, supplier B received 8 at one product/location, and 6 units sold. Before the 6 units are attributed, neither receipt's unsold remainder is known. If staff assign 4 sold units to A and 2 to B, A has 6 unsold and B has 6 unsold. Buying A's four unbilled sold and six unsold units bills 10, moves 6, leaves B's 6 physical units in consignment, and creates no bill for B. Existing reservations/bills reduce the selectable commercial subset.

Legacy confirmation submission/approval must respect existing receipt attribution once established and adopt/update matching attribution atomically for newly allocated sales. Attribute all effective sold quantities, not only discovered rows: discovery must establish a complete pool watermark and report unsupported/ambiguous dispatches as blockers. Effective returns apportion to receipt attribution through exact serials or explicit non-serialized quantity mapping; never free another supplier's pool implicitly.

### 3. Explicit lifecycle and reservation timing

`DRAFT -> WAITING_APPROVAL -> APPROVED -> FINALIZED -> REVERSED`; rejection is available from waiting, revision/resubmission from rejected, and cancellation before finalization from draft/waiting/approved. Cancelled/finalized/reversed documents are immutable except permitted administrative maintenance and audit append. Use existing Bahasa Indonesia labels, scanners/search patterns, and action notifications.

Unlike legacy confirmations, new buyout drafts and waiting documents hold no reservations. Approval creates all receipt/sold/physical/serial reservations together. This is intentional: preserve legacy submission-time reservations without changing their timing. Rejected/cancelled reservations become released; finalized reservations become consumed; approved reservations never auto-expire. Editing approved terms requires cancellation and a new approved document; do not mutate approved evidence in place.

Store canonical approved revision/hash over identities, quantities, destinations, receipt snapshots, supported sold snapshot version/hash, pricing/tax intent and metadata. At finalization revalidate that revision, the current relevant return/ownership evidence, reservations, receipt eligibility, and exact resource availability. Do not hash unrelated stock balances merely to reject harmless changes; operational cost uses the locked current state at posting. A changed tax policy affecting accepted invoice intent or ineligible destination requires cancellation/reapproval, not silent reinterpretation.

Add dedicated `consignments.buyouts.*` action permissions with a separate unsold-acquisition authority composed with approval/billing authority. Avoid implicit grants to sold-only billers. Controllers and services both enforce business access and policy; unauthorized state mutations propagate 403 rather than being swallowed by generic toast catches.

### 4. One coordinated resource-lock protocol

Add shared product/business inventory coordination rows, uniquely keyed by `(setting_id, product_id)`, acquired in sorted key order. They serialize shared average-cost state and pooled physical/financial attribution, including absent stock rows. They are transaction-scoped database locks, not process memory or SQLite-only locks.

Affected entry points acquire these coordination locks before their document, receipt, stock, or serial locks: legacy/new consignment approval/conversion/reversal, custody receiving, Sales dispatch, POS posting, affected transfer/stock-opname operations, and return receive/release. Include ordinary receiving/cost changes for affected setting/products so they cannot invalidate acquisition cost or reversal state concurrently. Product/business sets are discovered read-only first; if revalidation finds new resources, roll back and retry with the complete set rather than acquiring earlier keys late.

After coordination locks, lock document ancestry consistently: source Sale, POS Return, Sales Return and custody ancestry, dispatch parents/details, consignment receiving parents/details, document allocation rows; then locations ordered by ID, Products, product/location stocks, ProductPrice, sold sources/claims, and serials in deterministic identity order. Refactor affected inverted legacy sequences to the same planner. Acquire source and destination stock rows together; serialize creation of missing stock under locked Product/Location authority.

Existing Purchase payments already lock Purchase first. For reversal, lock the linked Purchase first, then coordination resources and buyout state, then payment/dependency evidence. Finalization only inserts a new Purchase: it must not lock a previously existing Purchase after holding resource locks. Its retry path reads the committed link and validates/returns it without reposting. Global payment operations retain ordered Purchase locking. Keep unrelated ordinary workflows unchanged apart from the shared resource-lock/availability boundary needed for these races.

Availability reads can be provisional for UI; approval/posting checks are authoritative under locks. Every consuming Sales/POS/transfer/adjustment path subtracts active buyout physical reservations by condition/tax bucket; it cannot consume claimed serials. Buyout stock posting consumes only its own claim and does not bypass other claims. Return mutations that would invalidate acquired/reserved sold evidence must be apportioned/revalidated through the same authority.

Alternative rejected: independent document locks or a buyout-only claim table would permit over-allocation and deadlocks against legacy dispatch/returns. Acceptance requires real MySQL races rather than relying on SQLite serialization.

### 5. Reuse financial calculations, introduce ownership posting

Create a receipt-backed adapter to `ConsignmentBillingPricingCalculator` and the existing monetary/sequence conventions. Do not call legacy conversion with invented confirmation IDs. Use stable slice identities, server-side preview/finalize calculations, proportional original tax evidence, the existing PKP invoice policy, and precise row totals. Keep sold and unsold slices commercially separate even for the same product; receipt distinctions remain lossless.

Invoice metadata and pricing are approved before finalization. Allocate global discount across all commercial slices by positive pre-global tax-inclusive row total, using the deterministic adjustment allocator with stable slice ordering and residual assignment. No new shipping control is introduced; if existing supported invoice metadata includes a capitalizable adjustment, use existing Purchase allocation semantics and snapshot it. Reject unreconciled/negative inventory cost instead of silently clamping it. Persist allocations so the sum exactly reconciles to the Purchase header.

Insert one Purchase with explicit `CONSIGNMENT_BUYOUT` source, financially active and physically complete but without Received Notes. Add a consignment-financial-source predicate to source/model guards, payment screens, provenance views, and administrative maintenance. Keep exact legacy source predicates where legacy confirmation linkage is required. Buyout has its own unique lineage/idempotency validator. Both individual/global payments use ordinary balances and `PaymentStatus` constants; finalization never writes payment records.

The dedicated ownership poster records a negative source and positive destination transaction per unsold slice, exact decimal(15,3) buckets, actor, posting time, source/destination balances, global and setting totals, receipt and buyout links, and cost-event references. Aggregate setting/global physical quantity remains unchanged. Preserve source tax/non-tax and condition buckets and serial tax identity; invoice tax is financial evidence, not authority to relabel stock. Unsold serials must be active, sellable and unclaimed. Non-serialized physical shortages or unexplained damaged buckets block the affected allocation rather than using unrelated stock.

Keep the original consignment receipt link on serials and record separate acquisition history/current ownership. Do not globally clear receipt lineage. Source standard locations ensure future Sales/POS discovery does not re-bill acquired inventory.

### 6. Explicit carrying-value replacement, not a second weighted receipt

Retain the setting-wide shared average model. For each product, aggregate all unsold slices in one finalization, then obtain locked total physical quantity `Q` across that setting's locations, current average `A`, acquired unsold quantity `q`, and accepted inventory cost total `C` for those slices. Use:

`V_before = Q * A`

`V_after = V_before - q * A + C`

`A_after = round(V_after / Q, existing average-cost precision)`

Require `Q > 0`, `0 < q <= Q`, consistent physical/claim evidence, and finite nonnegative monetary values. Do not seed a missing/invalid carrying average silently: surface a costing blocker for reconciliation. Zero invoice cost is allowed when the validated financial controls legitimately yield it. Write before/after snapshots and exact monetary rounding residuals once per setting/product. Other settings and historical Sales/POS HPP remain unchanged. No last-purchase-price field changes in this first buyout workflow, matching its ownership-reclassification nature and avoiding an additional global price policy.

Calculate `C` from accepted discounted invoice inventory amounts using existing Purchase DPP semantics: recoverable line tax is excluded; non-PKP line tax is zero; document discounts are allocated once. Persist original, reviewed, and inventory-cost amounts separately. Mixed invoices allocate discounts across sold and unsold slices first; only unsold `C` changes the current average.

Examples with no tax:

| Case | Calculation | Result |
| --- | --- | --- |
| Q=10, A=100, q=4, invoice unit inventory cost=120 | (1000 - 400 + 480) / 10 | Q stays 10, average 108 |
| Q=10, A=100; sold row=200, unsold row=480; global discount=68 | Discount sold=20, unsold=48; C=432; (1000 - 400 + 432) / 10 | Payable 612, average 103.20, no sold stock added |
| Sold-only document | q=0, no ownership cost event | Average and quantity unchanged |
| Partial 1.250 units of Q=5 at A=100, C=150 | (500 - 125 + 150) / 5 | Q stays 5, average 105 |

The resulting owned inventory is still valued at the shared average, not at a distinct invoice cost per lot. That trade-off was explicitly accepted. Add zero-quantity cost-adjustment events to historical cost replay using the actual finalization time and recorded before/after state; do not let buyout Purchase details enter the engine's no-Received-Note fallback as new receipts. Retain legacy sold-only behavior and add regression coverage showing this change does not replay historical costs merely because buyout support was installed. Future intentional replay must account for buyout/reversal cost events once and never duplicate acquisition quantity.

### 7. Ownership-aware return routing without changing custody policy

For every sold slice, store a same-business standard future-return destination even though nothing moves at finalization. A returned bought-out serial resolves acquisition from immutable serial/source lineage; a non-serialized return explicitly maps quantities to the acquired or unacquired source portions under the same capacity locks. Track cumulative returned acquired quantities to prevent duplicate ownership assignment.

Use acquisition ownership when resolving Standard Return custody owner/destination. Standard receiving remains held and unavailable until authorized inspection release; POS retains its established execution semantics. Inspection release posts to the acquired standard bucket, while unacquired portions retain their existing route. Pending/held returns are not selectable as unsold sellable buyout stock. No source dispatch, sold hash, supplier bill, or prior return is rewritten. Legacy post-billing return behavior is not generalized by this feature.

### 8. Dedicated full reversal with conservative dependency gates

A finalized buyout can be reversed only as a complete document, with reason and permission, no historical Purchase payment (including invalidated payments), no subsequent source/destination inventory movements for affected product/location pairs, no later serial histories/claims, no subsequent return or stock-operation dependency, and no incompatible setting-product cost event. Checking current quantities alone is never sufficient. Existing source receipt must remain eligible and recoverable.

Acquire Purchase/payment and resource locks as in decision 4. Mark the financial record cancelled through a tightly scoped domain operation allowed by the buyout source guard, preserve original totals and immutable audit, set collectible outstanding to zero through canonical cancellation-aware balance rules, record compensating movements and cost/ownership events, and release consumption together. Do not ordinary-delete Purchase/details or alter original posting evidence. Payment after reversal is denied under existing cancelled-Purchase semantics. A repeated reversal returns the same result without reposting.

After a cancelled unfinalized document, release only its reservations. After a finalized full reversal, restore exact prior attribution consumption and pool availability, while retaining any independent sold attribution still needed by other documents. Receiving reversal must inspect both live buyout claims and non-reversed acquisition dependencies even for sold slices that had no stock movement.

### 9. Presentation, rollout, and verification boundaries

Add module routes/controllers/services and Livewire/Blade screens using existing consignment conventions. Preparation shows receipts, outstanding sales attribution, sold versus unsold, exclusions, exact serials, destination mapping, original versus invoice pricing, and actionable blockers. Detail/reconciliation shows reservation versus finalized movement, owned return destinations, Purchase, paid/due amounts and reversal evidence. Administrative Purchase fields remain standard and do not rewrite approved buyout snapshots.

Add all new tests to `phpunit.consignment.xml` when outside the module's discovered directory; maintain the locked baseline runner. Verify direct forged requests as well as UI controls. Test source/stock/reversal failures after an earlier line to prove rollback. Exercise real Sales dispatch and real POS checkout before and after acquisition, plus standard held-return release and POS returns.

## Risks / Trade-offs

- [Pooled supplier attribution can block a buyout] → Show exact outstanding sales and allow explicit attribution during preparation; do not bill unrelated suppliers or guess balances.
- [Shared average repricing changes other owned units' displayed valuation] → Preserve the agreed pooled model, show before/after averages, retain variance evidence, and avoid claiming per-lot valuation.
- [Coordination locks serialize shared-product traffic] → Lock only affected setting/products in deterministic order, keep database transactions short, and benchmark approval/finalization with realistic receipt counts.
- [An unintegrated consumer ignores reservations] → Inventory all Sales/POS/transfer/adjustment/return/cost writers in implementation tasks and require contested-operation tests at real posting entry points.
- [Historical cost replay double-counts physically complete buyout Purchases] → Explicit source filtering and zero-quantity cost events with replay regressions.
- [Reversal is intentionally restrictive] → Return actionable dependency references; supplier credits and post-payment correction need their own future workflow.
- [Repository-wide tests are already failing] → Gate this change on the consignment suite and concrete integration coverage; keep unrelated backlog enabled and documented.
- [SQLite cannot prove lock correctness] → Execute isolated MySQL concurrency tests before declaring concurrency verified; a discovered/listed test is not a passing test.

## Migration Plan

1. Ship additive schema, indexes, explicit source handling, and permission definitions without granting unsold acquisition implicitly. Refuse destructive rollback when buyout, claim, lineage, or posting evidence exists.
2. Establish shared attribution/capacity/lock boundaries before exposing a usable finalization action. Reuse unambiguous legacy evidence read-only or materialize versioned mappings without changing legacy snapshots; leave ambiguous pools blocked for staff reconciliation.
3. Deploy buyout lifecycle and atomic poster together with reservation-aware consumers, return routing, source guards, and report/replay support. Never expose finalization while some stock consumers still ignore its claims.
4. Validate upgrade fixtures containing legacy unversioned/versioned sold sources, pending/approved/billed confirmations, serial claims, and partially returned quantities. Confirm old hashes, payment balances, and pricing remain compatible.
5. Run locked SQLite baseline and isolated MySQL race matrix, then UAT full/partial, serialized/non-serialized, mixed sold/unsold, cancellation, return, and eligible/ineligible reversal cases.
6. Before any finalized data exists, roll back only empty new schema plus corresponding application changes. Once operational evidence exists, disable new buyout actions and forward-fix or perform eligible audited reversals; do not drop provenance tables or revert source-aware application code beneath existing records.
