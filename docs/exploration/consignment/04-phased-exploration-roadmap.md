# Phased Exploration Roadmap

Each phase below is a standalone brief for a future exploration session. The output of a phase should be reviewed before moving to the next. “Done” means decisions and documents are ready, not that code is implemented.

## Phase 0 — Confirm the sold-to-bill rule

Prompt for the future session:

> Explore the confirmed inbound supplier-consignment rule for this ERP: goods are received without a supplier bill, and the supplier bill occurs when the goods are sold. Define exactly what counts as sold, the billed cost formula, whether billing is per sale or periodic, normal payment terms, taxes, returns, cancellations, loss, and exceptional cases. Use `docs/exploration/consignment` as context. Produce a short glossary, event timeline, worked examples, explicit scope, and answered decision register. Do not design implementation yet.

Questions:

- Which existing sale state counts as sold: approved, dispatched, completed, or customer-paid?
- At what exact event do HPP and the supplier bill/payable arise?
- Is payment based on sale, dispatch, customer payment, or periodic stock count?
- Is the supplier paid a fixed agreed unit cost, a percentage of selling price, or another formula?
- Who absorbs discount, damage, shrinkage, customer return, and tax?
- Can one location contain consigned goods from multiple suppliers?
- Which sale, revenue-recognition, supplier invoice, and Faktur Pajak dates govern each tax period?

Exit evidence: signed-off vocabulary, first-release direction, worked financial/tax examples, exclusions, and tax-adviser confirmation of the posting model.

## Phase 1 — Current workflow and data audit

Prompt:

> Trace every current code path affected by inbound consignment: location administration, purchase receiving, stock/serial ledger, transfer, sales dispatch, POS allocation, returns, adjustments, HPP, payables, journals, reports, permissions, imports, and deletion/archive rules. Produce a code-path map, invariant catalog, schema dependency map, and regression-test inventory. Do not implement.

Special investigations:

- confirm unique/index assumptions for `product_stocks`;
- identify every query that aggregates stock without ownership context;
- trace average-cost and HPP updates from receipt through sale;
- trace source location through POS split posting and bundles;
- identify journal behavior actually enabled in production.

Exit evidence: exhaustive integration inventory and identified technical spikes.

## Phase 2 — Domain and accounting design

Prompt:

> Design the inbound consignment domain using consignment-only locations with supplier ownership established per approved receiving line. Support multiple suppliers' fungible stock in one location, serial-derived ownership, manual non-serial billing allocation, and Purchase generation without duplicate receiving. Separately model customer output VAT and supplier input VAT, including supplier PKP status, sale date, invoice/Faktur Pajak date, tax period, DPP method, creditability, and returns. Specify entities, invariants, lifecycle state machines, immutable snapshots, event links, taxes, accounting events, reversals, concurrency, and audit. Produce a data model, state diagrams, calculation examples, and ADRs.

Exit evidence: approved lifecycle, ownership rules, accounting treatment, and failure/reversal semantics.

## Phase 3 — MVP interaction and contract design

Prompt:

> Define user workflows and contracts for the inbound consignment MVP: configure location/agreement, receive goods, view stock, sell through Sales/POS, return to supplier, process customer returns, calculate/approve/pay settlement, and report balances. Produce screen-level flows, permissions, validation rules, API/service contracts, acceptance scenarios, and UAT fixtures.

Exit evidence: testable functional spec with no unresolved critical UX or permission decisions.

## Phase 4 — Delivery slicing and migration safety

Prompt:

> Turn the approved inbound design into low-risk implementation slices for this Laravel/Livewire ERP. Define additive migrations, feature flags, backfill/default behavior, deployment order, rollback/disable strategy, observability, reconciliation queries, focused tests, and UAT. Ensure all non-consignment behavior remains unchanged.

Suggested implementation slices:

1. classification, agreements, permissions, and read-only reporting;
2. consignment receipt and custody ledger;
3. standard Sales consumption;
4. POS consumption;
5. customer/supplier returns;
6. settlement and payment;
7. valuation, operational, and reconciliation reports.

Exit evidence: proposal/design/spec/tasks ready for implementation.

## Phase 5 — Advanced capability exploration

Prompt:

> Evaluate expansion beyond the constrained MVP using production evidence. Explore multiple agreements per physical location, lot-level ownership, outbound consignment, bundles, serials, multi-business sharing, commissions, expiration, replenishment, and external partner statements. Compare schema evolution paths and migration cost from the MVP.

Exit evidence: prioritized roadmap and a decision on whether the stock key needs an ownership dimension.
