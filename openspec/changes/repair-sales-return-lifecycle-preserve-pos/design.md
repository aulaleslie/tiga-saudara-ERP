# Design

## Context

See proposal.md for motivation and specs/ for behavioral contracts. This is a Laravel 10/Livewire 3 brownfield repair. Standard return behavior lives in `app/Livewire/SalesReturn`, `Modules/SalesReturn/Http/Controllers`, and `app/Support/SalesReturn`. POS uses separate orchestration in `PosReturnLifecycleService` but shares Sales Return entities, payment evidence, and `SaleReturnLifecycleSyncService`.

Observed baseline before implementation:

- Standard-related filter: 19 failed, 34 passed. Fourteen fail on missing customer foreign-key fixtures; five hit deleted `SaleReturnDetail::selectableSettlementMethods()` (removed in commit 90694353).
- Focused POS Return classes: 27 failed, 357 passed. Two replacement-preview failures expose a cash fallback overwriting canonical replacement values; two audit/receiving failures expose valuation of raw omitted resolutions as none after validation resolved them to cash return. Two component quantity fixtures conflict with captured composition; two error expectations are stale. Nineteen execution tests stop at monetary validation before reaching their intended assertions; fixing their fixtures is not proof their downstream behavior passes.
- A separate successive-return rounding test manually sets COMPLETED without reducing active quantities. Replace this simulation with real execution. The broader Return substring filter also selected unrelated POS tests and is not a clean return-only gate.
- Source inspection found standard settlement resubmission, bulk dispatch, completion coverage, same-serial stock, and lock-time validation gaps. These need executable reproductions, not just source assertions.

## Goals / Non-Goals

**Goals:** Establish a green relevant POS baseline before standard changes; isolate ownership at every mutation boundary; represent physical custody separately from financial resolution; conserve stock and money with locked, auditable effects; apply policy prospectively.

**Non-Goals:** Redesign POS approval, change POS non-serial note-only replacement, unify standard/POS entitlement accounting, alter historical completed returns, create a general repair command, rewrite Spec Kit artifacts, redesign unrelated reports, or fix unrelated POS test failures. Existing `openspec/` history remains reference material; this explicitly requested OpenSpec change contains the planning artifacts.

## Decisions

### 1. Stabilize POS before changing shared services

Preserve canonical replacement amount in `PosReturnApprovalPreviewPlannerService::buildLinePlan`; cash valuation overrides apply only to cash resolutions. Build draft valuation selections from normalized validated intent in both `store` and `update`, preserving explicit none. Retain current rounding and stale-source checks. The submission fallback also references undefined `$cumQty` inside a swallowed exception; remove that ambiguity by sharing the canonical valuation result and surfacing genuine valuation failure, with a targeted fallback-path regression.

Repair fixtures to match authoritative source charges, captured component totals, serial quantity normalization, persisted bundle scope, and actual execution state. Cost reversal fixtures must distinguish immutable HPP from refundable commercial amounts. Rollback tests must pass monetary preconditions and reach their injected failure. Do not simply relax exception expectations or monetary guards. Record and repair any new return-domain defects these tests expose before phase 2.

Alternative rejected: weaken guards or accept the existing red baseline, which would hide compatibility regressions.

### 2. Separate workflow ownership at source and action boundaries

Introduce a small shared workflow resolver/policy in `app/Support/SalesReturn`. Identify POS-origin sales through both `Sale::posCheckout()` and `Sale::checkoutSale()` and recognize linked POS return headers/details. A null `pos_return_id` alone does not prove an ordinary sale. Apply policy to search, create/edit submit, header approval/rejection/receiving/archive/delete, settlement Livewire actions, item approval, dispatch request/approval/rejection, inspection, and legacy payment redirects. Read actions retain existing authorization; authorized browser navigation can redirect to POS, while mutation endpoints reject before writing and return actionable guidance.

Standard-only services reject linked POS records. POS continues its atomic preview execution, legacy lifecycle paths, original owner/location handling, rounding, serial lineage, and note-only semantics. Branch shared status/completion/archive behavior explicitly by workflow; do not impose standard settlement rows on POS headers finalized directly. For historical mixed standard/POS claims, audit and block unsafe new entitlement/financial/archive actions instead of combining incompatible quantity models.

Alternative rejected: allow both creation paths for POS sales. Standard subtracts return detail quantities from already POS-reduced dispatch quantities, while POS guards omit standard claims; safe coexistence needs a separate unified accounting design.

### 3. Persist prospective custody separately from product stock

Add module-owned migrations for `sale_return_custodies` and append-only custody movements. A custody balance anchors return/detail, source dispatch, product, location owner, original tax identity, quantity, optional serial, and policy version. Record received, released, and customer-dispatched quantities; enforce `held = received - released - dispatched >= 0`. Use decimal quantities consistent with existing stock precision and validate the standard return model's supported quantity granularity without introducing truncation.

Held quantities are outside `ProductStock` buckets and `Product::product_quantity`; those counters continue to describe existing stock inventory. Show held quantities separately on the return and custody view so warehouse physical totals can be reconciled as existing inventory plus held custody without counting held units as sellable inventory. Do not mislabel all uninspected goods as broken.

At standard receiving, lock the header and source claims, validate serial occurrence, insert custody once, and record receiving history. Use existing RETURN_IN_PROCESS status/return-process flag for held serials, preserving dispatch lineage through custody anchors. Audit selection paths that bypass `sellable()` or `available()` so held serials cannot leak into sales, POS replacement, transfer, purchase reuse, or adjustment workflows. POS receiving keeps its current stock path.

Inspection release uses new `saleReturns.inspect` permission, registered through existing permission conventions and assigned explicitly rather than granted broadly. Record mandatory notes, actor/time, selected quantity or serials. Permit store release only for originals retained after final cash return or replacement; customer-bound repair/cannot-process goods are ineligible. Transfer held quantity into the proper location-owner/tax bucket, update aggregate/global counters once, and activate released serials. Financial completion does not imply inspection release.

Original repair/cannot-process dispatch consumes custody with an outbound custody movement, not sellable inventory. Different-unit replacement dispatch consumes separate available stock and leaves the original held. Retain standard physical non-serial replacement, while POS non-serial replacement remains note-only. Each movement records both source and replacement identities and owner/location evidence.

Alternative rejected: add held stock to good or broken buckets and subtract it in selected screens. That risks selling it through consumers that use aggregate quantities.

### 4. Centralize guarded standard lifecycle mutations

Extract focused standard lifecycle, custody, settlement, and coverage services under the existing Sales Return support/module conventions; keep controllers and Livewire as adapters. Restore the missing selectable-method API while preserving all historical labels. Whitelist methods server-side and validate authoritative source dispatch, product, owner, quantities and serials rather than trusting hydrated rows.

Use a consistent lock order: source sales sorted by ID, return headers, source dispatch/details, custody and settlement rows, serials, then product/location stock and financial evidence. Reload state and authorization context under locks before effects. Serialize new standard claims against the source sale before computing remaining entitlement. Use unique database evidence keys per action/settlement/physical occurrence in addition to transactional checks. Notifications run after commit.

Only draft/rejected settlement rows can be submitted/reset. Both bulk and item dispatch enforce approved-awaiting-dispatch state, appropriate method, custody and replacement eligibility. Completion compares expected units/serial occurrences from all received details with final settlement coverage; existence of one approved item cannot imply full completion.

Alternative rejected: UI disabled controls or pre-transaction checks alone; both are bypassable and race-prone.

### 5. Record debt reduction and cash refund as distinct effects

Add an immutable standard settlement adjustment ledger keyed uniquely to settlement, with source sale/detail/serial allocation, committed return value, debt reduction, actual refund, actor/time, reviewed source fingerprint, policy version, and linked payout ID. Reuse `SaleReturnPayment` only for positive actual cash payouts; remove zero-to-subtotal substitution. Debt-only and zero-charge physical return completion produce no cash-payment row.

Resolve attributable charged value from stored source lines with discounts/tax allocations and prior committed adjustments. Payment authority uses active source-sale payment evidence, shared-payment allocation evidence where available, prior refunds, and committed debt reductions. Block if attribution is missing or contradictory. Formula under the source lock: `debt_reduction = min(return_value, outstanding_debt)`; `cash_refund = return_value - debt_reduction`, bounded by verified payments remaining. Do not silently truncate an invalid payout to fit a cap.

Preserve original standard sale detail/dispatched quantities for lineage and coverage. Apply the separately evidenced commercial reduction to source net header balances and payment status through a standard-only financial projection. Preserve gross source line evidence; affected receivables, sale displays, and financial projections consume the adjustment exactly once instead of reconstructing gross amounts as net or subtracting both payout and adjustment. Returns' paid/due display distinguishes settled value from actual cash paid. Shared global payment collection must revalidate the reduced outstanding debt. Standard repair/replacement/cannot-process have no commercial adjustment. Existing POS financial correction and payment invalidation stay authoritative for POS.

Alternative rejected: copy POS detail/dispatch reductions into standard returns, which would corrupt standard coverage and mixed-flow quantity accounting.

### 6. Change standard coverage without changing POS archival

Update all three existing `sale-return-completion-archival` requirements explicitly: current wording counts received quantities regardless of final resolution and assumes receiving activates stock; both conflict with the agreed new policy. Standard coverage uses final cash-return settlements per original dispatch line, including debt-only cash returns, excluding unresolved/service-return quantities and ambiguous lineage. Keep active sale status for repair/replacement/cannot-process (preserving any independently valid prior partial cash-return status). Only full finalized cash-return coverage archives; never use aggregate over-return to hide a short line.

Keep the POS active-quantity branch and linked-header completion behavior. Inspection remains available for retained custody even after the source sale archives. A completed return need not have zero held balance.

### 7. Version the first receiving action and audit legacy records

New nullable policy markers default to legacy/unknown for existing rows; no migration backfills stock or status. First receiving after deployment opts an unreceived standard return into the custody policy. Already-received legacy returns remain readable; actions requiring inferred custody or ambiguous monetary evidence block with audit guidance. Completed historical records remain immutable.

Provide a module command such as `sale-returns:audit` with required business scope and optional source/return filters, human-readable or JSON findings, and no apply mode. Report proven/suspected/ambiguous evidence for stock/serial differences, duplicate refund effects, incomplete settlement coverage, and mixed histories. Include archived records explicitly without changing them. Aggregate stock alone cannot prove a past discrepancy.

## Risks / Trade-offs

- Separate custody is a new physical balance outside existing product-stock counters -> label and reconcile held stock separately; exercise all serial availability consumers.
- Correcting nineteen blocked test cases can reveal more application defects -> phase 1 is a gate, not a promise that all are fixture-only.
- Source financial projections span displays, reports and shared collection -> test net-value conservation and no double deductions; block ambiguous allocation.
- SQLite does not prove production row-lock behavior -> run targeted concurrency checks against an isolated MySQL/MariaDB test database in addition to SQLite.
- Legacy already-received returns can require manual investigation -> read-only audit and safe blocks preserve evidence; corrective repair is a separate change.
- Existing cross-business stock-debt work is in flight -> preserve that change and test owner/location isolation; do not alter its accounting model here.
- The existing main `sale-return-completion-archival/spec.md` begins with `## ADDED Requirements` rather than a main-spec Purpose/Requirements structure -> normalize its structure without changing its existing requirement text before archive. Strict change validation currently passes but explicitly reports that archive would refuse this malformed target. This planning turn leaves the main spec untouched.

## Migration Plan

1. Complete POS stabilization and capture the green relevant regression baseline before shared standard changes.
2. Deploy additive custody, movement, adjustment and policy schema plus permission registration; existing records remain legacy and untouched.
3. Deploy workflow boundaries and standard services together; verify authorized inspection access before accepting new standard receipts. Do not automatically grant inspection to existing roles.
4. Validate with isolated synthetic cases, then run the read-only scoped audit. Never use historical production transactions as mutating verification fixtures.
5. Rollback before new-policy activity can revert application/schema safely. Once custody or adjustments exist, disable new standard mutations and retain the additive schema/evidence; do not deploy old receiving code that would reactivate held goods or drop populated ledgers. Use a forward fix or separately reviewed reconciliation.
