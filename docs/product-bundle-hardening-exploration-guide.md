# Product Bundle Hardening Exploration Guide

## Purpose

This document breaks product-bundle hardening into small, dependency-ordered exploration sessions. The bundle flow already exists end to end across Product, Sales, POS, dispatch, returns, receipts, and reports. These sessions should therefore preserve current behavior and identify narrow edge-case hardening—not redesign or implement the subsystem in one large change.

At the time this guide was written, no production bundle definitions were in use. This gives us room to clarify invariants before enabling wider usage, without assuming that the existing implementation is broken.

## How to use this guide

Run one sequence at a time by copying its complete prompt into a new exploration session. Every prompt deliberately asks for investigation only.

Do not create a proposal until the exploration produces a sufficiently specific, evidence-backed boundary. After an exploration, decide whether the result is:

- Already safe: document the evidence and proceed to the next sequence.
- Test-only gap: propose a narrowly scoped regression-test change.
- Validation gap: propose a small hardening change.
- Domain ambiguity: resolve the business rule before proposing implementation.
- Cross-cutting defect: record dependencies, but do not silently expand the current sequence.

For every sequence, prefer current code and tests over historical assumptions. Historical OpenSpec artifacts remain useful as intended-behavior references.

## Existing flow at a glance

```text
Bundle definition
      |
      +--------------------+
      |                    |
      v                    v
 Normal Sales           POS cart
      |                    |
      |              allocation/splitting
      +----------+---------+
                 v
       Sale + component rows
                 |
                 v
        Dispatch and inventory
                 |
        +--------+---------+
        |                  |
        v                  v
 Receipt/detail         Returns
        |                  |
        +--------+---------+
                 v
               Reports
```

## Sequence overview

| Sequence | Exploration subject | Primary risk | Expected scope |
|---:|---|---|---|
| 1 | Bundle definition integrity | Invalid or ambiguous definitions | Small |
| 2 | Activation and lifecycle | Expired/inactive bundles remain sellable | Small-medium |
| 3 | Pricing and allocation | Totals or rounding fail to reconcile | Medium |
| 4 | Cart and draft snapshot drift | Definition changes during a transaction | Medium |
| 5 | Normal Sales persistence | Quantity or row identity corruption | Small-medium |
| 6 | POS owner-split posting | Duplication or owner/tax misattribution | Medium |
| 7 | Dispatch and inventory identity | Incorrect merging or double movement | Medium |
| 8 | Component serial handling | Unsupported adapter/path combinations | Medium |
| 9 | HPP and profitability | Component cost omitted or double-counted | Medium, decision-sensitive |
| 10 | Return eligibility and reversal | Wrong historical composition restored | Medium |
| 11 | Report reconciliation | Revenue/HPP double-counting or omission | Medium |
| 12 | Production enablement | Unsafe rollout despite correct core flow | Small |

---

## Sequence 1: Bundle definition integrity

### Goal

Confirm the actual bundle-authoring contract and identify only missing integrity protections.

### Exploration prompt

```text
/opsx:explore Investigate current product bundle definition and authoring integrity end to end, without implementing anything.

Assume the bundle subsystem already works and we only want narrow hardening. Inspect the current migrations, ProductBundle and ProductBundleItem models, controller validation, Livewire bundle table, routes, permissions, product ownership/catalog patterns, and existing bundle tests.

Establish with file and line evidence:
1. What is already enforced when creating, editing, and deleting a bundle.
2. Whether a parent can be its own component.
3. Whether duplicate component products are accepted and how downstream code treats them.
4. Whether nested bundles are possible and whether any recursion/cycle protection exists.
5. Whether update verifies that the route product owns the bundle.
6. Whether component products and bundle headers are correctly scoped to the active setting/company.
7. What happens when a referenced component product or bundle is deleted.
8. Whether concurrent edits or delete-and-recreate of bundle items can create stale references.

Compare implementation with existing OpenSpec history and tests. Separate confirmed defects, plausible risks, and already-protected behavior. Recommend the smallest independently releasable hardening boundary and an exact regression-test matrix. Do not write code or create a proposal unless I explicitly ask afterward.
```

### Decision to carry forward

Document whether the supported model permits duplicate components and nested bundles. Later sequences should not invent their own answer.

---

## Sequence 2: Bundle activation and lifecycle

### Goal

Determine whether `is_active`, `active_from`, `active_to`, deletion, and persisted transaction snapshots have consistent runtime meaning across each independently managed setting copy.

### Sequence 1 handoff

Carry these definition rules forward without reopening them in this sequence:

- Creating a bundle creates independent copies for every setting available at creation time; later-added settings and cross-setting group management/backfill are out of scope.
- Each setting independently edits, enables/disables, and removes its copy. A lifecycle change in one setting must not affect another setting's copy.
- `is_active` is an administratively maintained per-setting state introduced by Sequence 1, but comprehensive runtime enforcement is deferred to this sequence.
- Bundle composition expands exactly one level. Self-components and components that have their own bundles are valid; component bundles are not recursively fetched.
- Duplicate component rows are invalid, and products referenced as bundle parents or components cannot be deleted until those definitions are removed.
- Concurrent edit protection is intentionally out of scope; last-write-wins administration is acceptable.

### Exploration prompt

```text
/opsx:explore Trace product bundle activation and lifecycle behavior through Product administration, normal Sales, POS search, barcode scanning, cart addition, saved drafts, checkout preflight/finalization, Sales submission/approval, and historical transaction display. Do not implement anything.

Sequence 1 established independent per-setting bundle copies, a per-setting is_active state, one-level composition, valid self-components, duplicate-component rejection, and product-deletion protection. Do not reopen those authoring decisions. Inspect every query or resolver that loads ProductBundle records and determine with file and line evidence whether is_active, active_from, active_to, setting_id, bundle deletion, and valid/non-empty composition are enforced consistently at runtime.

Investigate one reusable eligibility rule equivalent to:

eligible = is_active
    AND active_from has started
    AND active_to has not ended
    AND bundle belongs to the transaction setting
    AND composition remains valid/non-empty

Do not assume that this rule should be applied to historical display or blindly applied at every transaction stage; determine the correct enforcement points from existing snapshot and posting behavior.

Explore these cases:
- Bundle enabled in one setting and disabled in another.
- Future bundle.
- Expired bundle.
- Open-ended activation dates.
- Inclusive versus exclusive date boundaries and application/business/database timezone behavior.
- Current date versus transaction/reporting date as the lifecycle clock.
- Bundle eligible at cart creation but disabled or expired before Sales submission/approval or POS checkout preflight/finalization.
- Bundle disabled, expired, deleted, or rendered invalid while present in an open Sales/POS cart or persisted draft.
- POS payment stages committed before lifecycle invalidation is detected.
- Finalize retried after lifecycle state changes.
- Direct/manual bundle IDs submitted after the bundle becomes ineligible.
- Historical posted sale after the bundle becomes inactive or changes.
- Completed POS receipt/reprint, return, and report behavior after the live bundle is disabled, expired, edited, or deleted.

For every path, identify whether an in-progress transaction should be blocked, warned and forced to reselect, or allowed to complete from its captured snapshot. Preserve historical readability: posted Sales, completed POS receipts/reprints, returns, and reports must continue using persisted historical composition rather than live eligibility filters.

Verify setting independence explicitly: disabling, expiring, editing, or deleting a bundle copy in one setting must not alter availability in another setting. Classify each path as protected, inconsistent, or undefined. Recommend the smallest reusable lifecycle hardening boundary and an exact regression-test matrix. Do not write code or create artifacts unless I ask.
```

### Dependency

Uses the valid-definition and per-setting replication rules established in Sequence 1. Sequence 2 owns runtime enforcement of `is_active`, date eligibility, deletion during in-progress transactions, cart/draft transitions, finalization revalidation, and historical-display isolation.

---

## Sequence 3: Bundle pricing and allocation reconciliation

### Goal

Verify that bundle price, component informational prices, parent residual, tax, discounts, quantities, and split totals reconcile exactly.

### Exploration prompt

```text
/opsx:explore Investigate current bundle pricing and monetary allocation across Product authoring, normal Sales, POS cart totals, POS split planning, posted sale_details, and sale_bundle_items. This is exploration only; do not implement.

Start from the actual meaning and use of bundle_sale_price, legacy product_bundles.price, ProductBundleItem informational_item_price, SaleBundleItem price/sub_total, and parent residual allocation. Trace calculations with file and line evidence.

Test the model mentally and against existing tests for:
- All informational component prices are zero.
- Component allocations exactly equal the bundle price.
- Component allocations exceed the bundle price.
- Bundle quantity greater than one.
- Decimal and rounding-sensitive prices.
- Line discount plus bill/global discount.
- PKP and non-PKP tax extraction.
- Manual Sales price differing from configured bundle price.
- Same bundle sold at different prices.
- Multi-owner split posting.

Determine where reconciliation is already enforced and where normal Sales and POS differ. State the exact monetary invariants in minor units, identify any confirmed double-count or remainder risk, and recommend the smallest test or validation change. Do not redesign pricing and do not write code.
```

### Required invariant to investigate

```text
parent residual + all component allocations = posted bundle commercial amount
sum(owner-group totals) = checkout total
```

---

## Sequence 4: Bundle cart and draft snapshot drift

### Goal

Understand what happens when bundle definitions change after selection but before posting.

### Exploration prompt

```text
/opsx:explore Trace bundle snapshot identity and drift handling in normal Sales carts, Sales edit hydration, POS carts, saved POS drafts, checkout preflight, and finalize. Do not implement.

Use current code and tests to identify exactly what is snapshotted, reloaded from the live bundle definition, hashed, trusted from session/request data, or revalidated from persisted products.

Explore these races:
- Bundle price changes after it is added to a cart.
- Component added or removed.
- Component quantity or informational price changes.
- Component stock_managed or serial requirement changes.
- Bundle expires or is deleted.
- Draft is reopened after any of those changes.
- Payment stages are committed before drift is detected.
- Finalize is retried with the same idempotency key.

Distinguish intentional historical snapshot behavior from unsafe stale-cart behavior. Determine whether normal Sales and POS should have the same rule or deliberately differ. Recommend the smallest drift contract and focused tests; do not write code or propose implementation yet.
```

### Dependency

Uses lifecycle decisions from Sequence 2 and monetary identity from Sequence 3.

---

## Sequence 5: Normal Sales bundle persistence

### Goal

Validate the already-working normal Sales bundle path at its row-identity and quantity boundaries.

### Exploration prompt

```text
/opsx:explore Investigate normal Sales bundle behavior from product selection through cart aggregation, SaleNormalizer, SaleService creation/update, sale_details, sale_bundle_items, edit hydration, approval, and the dispatch screen. Do not implement.

Assume the normal path works. Focus on edge cases and existing regression coverage:
- Same parent with two different bundles.
- Bundled and non-bundled instances of the same product.
- Same component used by two bundle lines.
- Parent quantity increases and decreases repeatedly.
- Avoiding component quantity double expansion.
- Removing one bundle cart row.
- Updating a draft after the live bundle definition changed.
- Transaction rollback when component persistence fails.
- Monetary-only edits after dispatch.
- Standalone sale_bundle_items with null sale_detail_id.
- Sale creation with insufficient component stock versus enforcement at dispatch.

Produce an evidence-backed current flow, state which behavior is explicitly tested, and identify only missing invariants or regression tests. Clarify whether deferred stock validation is intentional and consistently applied. Recommend a narrowly bounded hardening feature, not an end-to-end rewrite. Do not write code.
```

### Required invariant to investigate

```text
persisted component quantity = parent quantity x quantity per bundle
component sale_id = parent sale_detail sale_id
```

---

## Sequence 6: POS owner-split bundle posting

### Goal

Stress the existing split planner and posting adapters without changing their architecture prematurely.

### Exploration prompt

```text
/opsx:explore Investigate existing POS bundle allocation, owner-aware adapter selection, split planning, inline posting, split posting, tax bucket resolution, persistence, idempotency, and receipt reconstruction. Do not implement.

Treat the current design as operational and look specifically for edge combinations not covered by tests:
- Parent and components owned by different settings.
- One owner supplies only a component and no parent stock.
- One owner supplies part of the parent quantity and all of a component.
- Component quantity sourced from multiple locations.
- Stock and non-stock components together.
- PKP and non-PKP owners in one bundle.
- No configured non-PKP source for a stockless component.
- Same SKU used as parent, component, and standalone line.
- Child allocations copied or lost between groups.
- Exception after one group starts posting.
- Finalize retry after failure or successful completion.

Map the exact transaction boundary and reconciliation checks. Cross-reference the extensive POS bundle tests and identify coverage gaps rather than repeating protected scenarios. Recommend only small independent hardening changes or test additions. Do not write code.
```

### Dependency

Uses pricing invariants from Sequence 3 and snapshot rules from Sequence 4.

---

## Sequence 7: Bundle dispatch and inventory identity

### Goal

Verify that component demand remains distinct and inventory moves exactly once.

### Exploration prompt

```text
/opsx:explore Trace bundle demand through Sales dispatch aggregation, POS automatic dispatch, DispatchDetail persistence, approval, inventory transactions, stock validation, non-stock audit rows, and dispatch status calculation. Do not implement.

Inspect the current composite keys and all places using product_id, tax_id, bundle_id, sale_detail_id, or line_group_key. Explore:
- Same SKU standalone and inside a bundle.
- Same SKU in two different bundles.
- Same bundle definition added as two separate transaction lines.
- Same component under different tax buckets.
- Partial dispatch from multiple locations.
- Pending, approved, and rejected dispatches.
- Non-stock audit rows accidentally influencing inventory or completion.
- Snapshot stock flags conflicting with persisted product classification.
- Retry or approval race causing duplicate movement.
- Parent and component both being stock-managed.

Determine whether the current composite identity is sufficient or only theoretically ambiguous. Do not recommend a schema change without demonstrating a concrete collision. Produce current invariants, confirmed safeguards, gap tests, and the smallest hardening boundary. Do not write code.
```

### Required invariant to investigate

```text
required quantity = approved dispatch + valid outstanding quantity
one approved dispatch quantity creates one inventory effect
```

---

## Sequence 8: Bundle component serial handling

### Goal

Map serial support by adapter and product position, then harden only unsupported cells.

### Exploration prompt

```text
/opsx:explore Build an evidence-backed support matrix for serial-tracked bundle parents and components across normal Sales dispatch, POS inline posting, POS split posting, receipts, transaction detail, and returns. Do not implement.

Inspect code comments as well as tests; do not assume a test in one adapter proves another adapter works. Explore:
- Serial parent with ordinary components.
- Ordinary/non-stock parent with one serial component.
- Multiple serial components.
- Serial SKU sold standalone and bundled in the same transaction.
- Split-owner and multi-location serial bundles.
- Serial reassigned or moved after cart selection.
- Duplicate checkout/finalize attempts.
- Partial dispatch and partial return.
- Same serial offered in two active returns.

Produce a matrix with rows for parent/component serial combinations and columns for normal Sales, POS inline, POS split, dispatch, receipt, and return. Mark each cell confirmed by code/test, unsupported, or unknown. Recommend the smallest sequence of fixes or tests for unsupported cells. Do not write code.
```

### Dependency

Uses dispatch identity from Sequence 7.

---

## Sequence 9: Bundle HPP and profitability

### Goal

Resolve the most important accounting ambiguity without unnecessarily replacing existing reports.

### Exploration prompt

```text
/opsx:explore Investigate current HPP treatment for bundles from product average purchase prices through SaleDetails cost snapshots, SaleBundleItem persistence, parent/component inventory movement, returns, operational profit/loss, movement events, and product reports. Do not implement.

Prove with file and line evidence:
- Which product supplies cost_unit_snapshot for a bundled sale.
- Whether sale_bundle_items persist any immutable component cost.
- How a non-stock parent with stock-managed components affects HPP.
- How a stock-managed parent plus stock-managed add-on components affects HPP.
- Whether split-owner components use the correct setting-specific average cost.
- Whether returns reverse or merely change the current sale quantity used by reports.
- Which reports use parent cost snapshots, current average prices, dispatch movements, or no HPP.

Use worked examples for at least:
1. Non-stock parent, stock components.
2. Stock parent, no stock components.
3. Stock parent plus stock add-on component.
4. Missing or zero average purchase price.
5. Component owner differs from parent owner.

Separate confirmed accounting defects from undefined business semantics. Compare minimal compatible options: aggregate component cost onto the parent snapshot, persist component cost snapshots, or both. Explicitly guard against double-counting. Recommend a narrow decision and test matrix, but do not write code or create a proposal unless asked.
```

### Required accounting invariant to investigate

```text
recognized HPP = cost of inventory actually fulfilled for the bundle
the same physical cost must never be recognized twice
```

---

## Sequence 10: Bundle return eligibility and reversal

### Goal

Ensure returns reverse the original transaction rather than the current bundle definition.

### Exploration prompt

```text
/opsx:explore Trace bundled Sales and POS returns from eligibility lookup through snapshot creation, grouping, submission, approval, receiving, stock restoration, serial handling, cash refund or replacement, lifecycle status, and reporting. Do not implement.

Use existing SaleReturnEligibilityService, POS return services, sale_bundle_items, original dispatch details, transaction-line metadata, and bundle regression tests as evidence.

Explore:
- Full bundle return.
- Partial parent bundle quantity return.
- Attempt to return one component independently.
- Same bundle on multiple transaction lines.
- Same component SKU sold bundled and standalone.
- Component dispatched from multiple owners or locations.
- Bundle definition changed or deleted after sale.
- Cumulative returns across multiple return documents.
- Rejected/cancelled return releasing eligibility.
- Serial already returned in another active return.
- Refund value versus internal component allocation.
- Replacement dispatch preserving original bundle identity.

Determine the current authoritative source for composition, quantities, owner, location, tax, serial, value, and HPP reversal. Identify only concrete gaps and propose the smallest hardening boundary and regression tests. Do not write code.
```

### Dependency

Uses snapshot, dispatch, serial, and HPP decisions from Sequences 4, 7, 8, and 9.

---

## Sequence 11: Bundle report reconciliation

### Goal

Confirm that each report answers the intended business question without omitting or double-counting bundle values.

### Exploration prompt

```text
/opsx:explore Audit current bundle behavior across Sales Report, Sales by Product, Sales by Customer, Sales Tax, Sales Delivery, Sales Order Completion, operational Profit/Loss, movement events/general ledger, stock reports, and return reports. This is read-only exploration; do not implement.

For each report, identify:
- Source tables and status/date/setting filters.
- Whether it displays the parent, components, or both.
- Revenue source and tax treatment.
- HPP source.
- Return treatment.
- Split-owner behavior.
- Export parity.

Use one worked fixture with a bundle price, parent residual, two component allocations, two component HPP values, mixed owner/tax context, dispatch, and a partial return. Calculate expected results report by report.

Specifically check:
- Parent full value plus component values being summed twice.
- Zero-value component delivery.
- Same SKU standalone and bundled.
- Category/product filtering when parent and component categories differ.
- Split checkout represented by multiple Sale records.
- Profit/loss omitting component HPP.
- Screen and export using different logic.

Classify differences as intentional report semantics or defects. Recommend separate narrowly scoped report hardening changes rather than one cross-report rewrite. Do not write code.
```

### Suggested report viewpoints

```text
Commercial: what the customer bought
Operational: what physically moved
Accounting: what revenue and cost were recognized
```

---

## Sequence 12: Production enablement and reconciliation

### Goal

Define a safe rollout once earlier explorations have resolved their concrete gaps.

### Exploration prompt

```text
/opsx:explore Assess readiness to enable the existing bundle functionality for real operational use. Do not implement.

Use the findings and decisions from all earlier bundle explorations. Inspect permissions, menus, audit logging, validation messages, transaction atomicity, idempotency, diagnostics, existing fixtures, test commands, and deployment/rollback practices.

Design a minimal production-readiness checklist covering:
- Supported and unsupported bundle shapes.
- Required configuration and permissions.
- One representative normal Sales UAT scenario.
- One representative POS split-owner UAT scenario.
- Stock, non-stock, serial, tax, receipt, return, HPP, and report verification.
- Reconciliation queries or a possible read-only diagnostic command.
- Pilot rollout, monitoring, rollback, and operator guidance.

Define the reconciliations that should hold across sale headers, sale_details, sale_bundle_items, dispatch_details, inventory transactions, returns, and reports. Identify which checks already exist and which would merit a later proposal. Do not write code or create a proposal unless I explicitly ask.
```

### Final readiness invariants

```text
Commercial total
  = authoritative posted bundle revenue

Allocation total
  = parent residual + component allocations

Inventory quantity
  = required component quantity fulfilled exactly once

HPP
  = authoritative fulfilled inventory cost exactly once

Return quantity
  <= originally fulfilled quantity - previously active returns
```

## Recommended execution order

Run the sequences in their numbered order. If time is limited, use these priority groups:

### Before creating the first real bundle

1. Definition integrity.
2. Activation and lifecycle.
3. Pricing reconciliation.
4. Snapshot drift.
9. HPP and profitability.

### Before using bundles in normal Sales

5. Normal Sales persistence.
7. Dispatch and inventory identity.
8. Serial handling if any component is serial-tracked.
11. Report reconciliation for the reports operators use.

### Before using bundles in POS

6. POS owner-split posting.
10. Return eligibility and reversal.
12. Production enablement.

## Exploration result template

Use this structure at the end of each exploration so findings remain comparable:

```text
Sequence:

Confirmed current behavior:
-

Existing safeguards and tests:
-

Confirmed gaps:
-

Undefined business decisions:
-

False alarms / already protected:
-

Smallest recommended change boundary:
-

Regression-test matrix:
-

Dependencies on other sequences:
-

Ready for an OpenSpec proposal: Yes / No
Reason:
```

The goal is not for every exploration to produce a change. A result of “already sufficiently protected” is valuable and should prevent unnecessary implementation work.
