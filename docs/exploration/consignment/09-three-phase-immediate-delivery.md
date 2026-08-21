# Three-Phase Immediate Delivery

Status: recommended compact delivery scope derived from the full consignment exploration.

## Target outcome

Deliver inbound supplier consignment without changing ordinary Purchase, Sales, POS, stock, or payment behavior for non-consignment transactions.

At the end of Phase 3, the company can:

1. receive supplier goods into consignment locations without creating a supplier bill;
2. sell those goods through normal Sales or POS using existing location sourcing;
3. trace serialized goods automatically to their supplier;
4. allocate non-serialized sold quantities manually to eligible suppliers;
5. convert approved allocations into payment-eligible supplier Purchases without receiving stock twice;
6. apply company/supplier PKP rules and retain the required tax audit data;
7. reconcile received, sold, billed, returned, and remaining consignment quantities.

## Phase 1 — Consignment custody and receiving

### Deliver

- Add `locations.is_consignment`.
- Prevent consignment receiving into standard locations.
- Prevent disabling the flag while dependent consignment activity exists.
- Create Consignment Receival header and lines.
- Use the familiar lifecycle:

```text
DRAFT -> WAITING_APPROVAL -> APPROVED/REJECTED
                                  |
                           Receiving PENDING
                                  |
                           APPROVED/REJECTED
```

- Mutate stock only when the receiving note is approved.
- Store supplier, product, base quantity, source location, cost snapshot, tax classification, and references.
- Link received serials durably to their approved consignment receiving detail.
- Maintain supplier-specific receipt balances for non-serialized products even though physical `product_stocks` remains aggregated.
- Ensure receipt creates no ordinary Purchase, payable, or payment eligibility.
- Add permissions and immutable approval/rejection audit data.

### Compromises

- One receiving note per consignment receival.
- No partial receiving; create another receival for later delivery.
- No consignment imports.
- No transfers into or out of consignment locations.
- No bundles.
- No agreement/contract module; cost and tax terms are snapshotted directly on the receival.
- No outbound consignment.

### Exit gate

Approved receiving must reconcile physical stock, supplier receipt balances, and serialized lineage atomically. Ordinary receiving into standard locations must pass regression tests unchanged.

Phase 1 may be deployed behind a disabled feature flag, but should not be operationally launched because billing is not yet available.

## Phase 2 — Sales detection and supplier allocation

### Deliver

- Detect billable consignment quantity only from persisted actual sale sources.
- Normal Sales respects the location selected at dispatch.
- POS preserves the existing configured location-priority allocation.
- Do not add a consignment-specific POS priority algorithm.
- Split mixed fulfillment: standard-location quantity remains ordinary; consignment-location quantity becomes billable.
- Treat final Sales dispatch and completed POS checkout as the initial qualifying sold events.
- Create a billing-confirmation workflow belonging to one supplier per confirmation.
- For serialized products, resolve the supplier from approved receiving lineage and make it non-editable.
- For non-serialized products, allow manual allocation against that supplier's receipt balance at the same source location.
- Atomically enforce:

```text
allocation <= sold-from-consignment but unbilled quantity
allocation <= supplier received but unbilled quantity
```

- Reserve quantities held by active confirmation drafts to prevent concurrent over-allocation.
- Exclude rejected, cancelled, voided, and returned-before-billing sales.
- Preserve source sale, dispatch/POS allocation, receiving, supplier, quantity, cost, and tax snapshots.

### Compromises

- One supplier per confirmation rather than a multi-supplier confirmation batch.
- Users allocate fungible non-serialized stock manually; no FIFO/LIFO automation.
- No automatic scheduled billing.
- No cross-location supplier pooling.
- No customer-payment-based trigger; actual dispatch/POS completion is treated as sold.
- No bundled-product allocation.

### Exit gate

Every eligible unit must be allocatable at most once, every serialized item must have unambiguous supplier lineage, and concurrent confirmations must not over-allocate either sold or received balances.

Phase 2 remains behind the feature flag until Purchase generation and tax controls in Phase 3 are ready.

## Phase 3 — Supplier Purchase, tax, returns, and release

### Deliver

- Approval of one supplier confirmation generates one supplier Purchase/bill.
- Mark it explicitly, for example:

```text
document_type = CONSIGNMENT_BILL
requires_receiving = false
```

- Make it payment-eligible through the current Purchase payment flow.
- Disable every receiving action for this Purchase type.
- Never mutate inventory during bill creation, approval, or payment.
- Link Purchase details to billing allocations, sold sources, and original consignment receipts.
- Preserve customer output VAT independently in Sales/POS.
- Support supplier-side PKP/non-PKP combinations, including VAT charged to a non-PKP company as non-creditable tax under the approved accounting policy.
- Store supplier invoice and Faktur Pajak number/date, actual sale date, confirmation date, tax period, DPP method/amount, statutory rate, VAT amount, tax-included state, and creditability.
- Prevent silent cross-period movement caused by delayed confirmation.
- Preserve current configurable Purchase tax normalization rather than hard-coding an effective VAT percentage.
- Before billing, customer returns reduce eligibility.
- After billing, require a linked Purchase Return/credit path; preserve the original allocation and tax audit trail.
- Add a minimum reconciliation report:
  - approved received quantity;
  - sold-from-consignment quantity;
  - allocated/billed quantity;
  - returned/adjusted quantity;
  - remaining supplier-owned quantity;
  - generated Purchase and payment status.
- Add focused regression coverage for ordinary Purchase, receiving, Sales dispatch, POS location priority, stock, serial, payment, and tax behavior.

### Compromises

- No automatic e-Faktur/Coretax submission; store and validate references only.
- No sophisticated tax-period closing module; use permissioned cross-period blocking/override with audit.
- No automated post-billing customer-return netting; use a linked controlled credit/Purchase Return.
- No advanced profitability, aging, commission, or agreement reports.
- No percentage commission or revenue-share terms; use fixed supplier unit cost captured at receival.
- No multi-currency support beyond existing application behavior.

### Release gate

Do not enable the feature for production operations until:

1. generated consignment Purchases cannot enter any receiving path;
2. ordinary Purchases remain unchanged;
3. one allocation cannot generate more than one active supplier bill;
4. Sales/POS output VAT remains independent from supplier-side VAT;
5. supplier/company PKP combinations and non-creditable VAT pass worked examples;
6. returns cannot silently delete billed history;
7. reconciliation totals balance;
8. the company's Indonesian tax adviser approves the documented invoice, Faktur Pajak, tax-period, return, and accounting treatment.

## Explicitly deferred beyond the urgent release

- outbound consignment;
- consignment agreements and term versioning;
- multiple receiving notes and partial receiving;
- stock transfers and ownership conversion;
- bundles;
- consignment imports;
- FIFO/LIFO automatic supplier selection;
- confirmation covering multiple suppliers;
- scheduled monthly settlement automation;
- commission/revenue-share pricing;
- advanced accounting automation;
- direct government tax-platform integration;
- advanced dashboards and forecasting.

## Existing-flow protection strategy

The implementation should be additive:

- new consignment documents and provenance tables;
- a nullable/explicit Purchase source type rather than changed meaning for all Purchases;
- dedicated services for consignment receiving, allocation, and Purchase generation;
- existing stock mutation services reused only at approved receiving;
- existing Sales/POS source allocations read without changing their selection behavior;
- explicit guards in domain services and routes, not only hidden buttons;
- all new behavior gated by `is_consignment`, consignment source identity, and permissions;
- feature flag disabled until the Phase 3 release gate passes;
- no historical stock rewrite or automatic backfill.

This boundary keeps ordinary workflows on their current code paths and introduces consignment only when a document or location is explicitly identified as consignment-related.
