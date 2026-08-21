# Confirmed Workflow

Status: clarified exploration baseline; exact state names and schema remain to be specified.

## 1. Location rule

Consignment stock may be received only into a consignment-enabled location.

For the currently known binary requirement, `locations.is_consignment` is easier to maintain than a location-type enum. It should mean only:

> This location is eligible to hold consigned inventory.

It must not identify the supplier or be used as the historical ownership record. Multiple suppliers may supply the same product into the same consignment location.

Changing `is_consignment` from true to false must be blocked while the location has consignment quantity, active serial ownership, pending receipts, unallocated sold quantity, draft confirmations, or unpaid consignment purchases.

## 2. Consignment receival lifecycle

The workflow mirrors the existing Purchase and Received Note separation:

```text
Consignment Receival
    DRAFT
      |
    submit
      v
WAITING_APPROVAL ----reject----> REJECTED
      |
    approve
      v
  APPROVED
      |
 create receiving note
      v
Receiving PENDING ----reject----> REJECTED (no stock mutation)
      |
    approve
      v
Receiving APPROVED (stock enters consignment location)
```

Approval of the consignment receival authorizes expected custody. Approval of its receiving note is the event that mutates physical stock and establishes supplier ownership provenance.

The initial document should capture supplier, products, expected quantities, agreed supplier unit cost or pricing rule, tax context, UOM, and relevant references. The approved receiving detail should become the immutable source for actual quantity and serial lineage.

No ordinary Purchase payable is created during this workflow.

## 3. Ownership after receiving

### Serialized product

Each received serial is durably linked to its approved consignment receiving detail. That chain determines:

```text
sold serial -> approved consignment receipt detail -> supplier + cost/tax terms
```

The billing confirmation may display the resolved supplier read-only. A user should not be able to redirect a serialized sale to another supplier.

### Non-serialized product

Physical stock remains aggregated in the existing product/location bucket, but supplier ownership is tracked as receipt pools.

Example after approved receiving:

| Product | Supplier | Received | Returned/adjusted | Already billed | Available to allocate |
|---|---:|---:|---:|---:|---:|
| Product A | Supplier A | 5 | 0 | 0 | 5 |
| Product A | Supplier B | 6 | 0 | 0 | 6 |

This supplier pool does not need to change normal stock visibility. It exists to validate ownership allocation and billing.

## 4. Billing-confirmation page

The page presents sold consignment items that have not yet been converted into supplier bills.

### Authoritative sales-location sourcing

Consignment eligibility is determined from the location that actually supplied the sold quantity, not merely from the product or sale header.

For normal Sales:

- dispatch continues to respect the location selected by the user;
- the persisted actual dispatch detail is the authoritative source allocation;
- only quantity dispatched from a location where `is_consignment = true` enters the consignment billing pool;
- quantity dispatched from a standard location remains an ordinary owned-stock sale.

For POS:

- POS continues to use the currently implemented sale-location configuration order;
- no consignment-specific priority algorithm is introduced;
- the source-location allocations persisted by checkout/finalization are authoritative;
- only the portion allocated from consignment locations enters the consignment billing pool.

This permits one sale line to use mixed sources. For example, if Product A quantity 7 is fulfilled as 2 from a standard location and 5 from a consignment location, only 5 appears for supplier allocation.

The billing page must never re-run current stock selection or the current POS priority order. Location configuration and stock may have changed after the sale, so billing must use the immutable source allocation captured at dispatch or checkout.

### Serialized section

- show sold serial, product, sale/POS reference, sold date, resolved supplier, receipt reference, supplier cost, and tax context;
- supplier is automatically derived and not editable;
- block confirmation if the serial has missing or ambiguous receiving lineage;
- exclude or reverse returned/voided sales according to the final sold-event rule.

### Non-serialized section

- show product, total sold quantity eligible for billing, quantities already allocated, and remaining quantity;
- identify the actual source consignment location so allocations use receipt pools from the same custody location;
- show eligible supplier receipt pools with received, returned/adjusted, billed, reserved-in-draft, and available quantities;
- user enters a quantity per supplier;
- require allocations to sum exactly to the quantity being confirmed.

Example for Product A sold quantity 7:

| Supplier | Available receipt balance | User allocation |
|---|---:|---:|
| Supplier A | 5 | 3 |
| Supplier B | 6 | 4 |
| **Total** | **11** | **7** |

Another valid allocation is A=5 and B=2. The choice is operational, but must be recorded immutably once approved.

### Atomic validation

On submission/approval, the system must lock and revalidate:

1. eligible sold quantity actually sourced from consignment locations, minus prior approved allocations and sale returns;
2. each supplier receipt pool's approved received quantity at the applicable source location, minus supplier returns, adjustments, prior approved billing allocations, and active reservations;
3. serial lineage and current sold/returned status;
4. product/UOM base-quantity conversion;
5. selected company's PKP/non-PKP rules;
6. source records have not changed since preview.

The operation must reject over-allocation rather than silently substituting another supplier.

## 5. Conversion to Purchase without receiving again

Approval of billing confirmation should generate one Purchase per supplier represented in the confirmation. A single customer sale may therefore contribute lines to several supplier Purchases.

The generated Purchase represents the supplier bill/payable, not a new physical receipt:

- it links every Purchase detail back to approved billing allocations and original consignment receipts;
- it does not create a Received Note or mutate stock;
- it is immediately payment-eligible after the required billing approval;
- it snapshots supplier cost, tax, discount allocation if applicable, and source sold quantities;
- it carries an explicit source/type such as `CONSIGNMENT_BILL` so normal Purchase code cannot accidentally receive it;
- its reference and supplier invoice/reference follow current Purchase conventions where possible.

Using an ordinary Purchase with status `RECEIVED` may integrate quickly with current payments, but current Purchase costing and reporting often assume approved receiving-note provenance. This must be audited before choosing the exact status. The safe choices to compare during design are:

1. generated Purchase marked `CONSIGNMENT_BILL`, payment-eligible, with receiving actions disabled; or
2. a dedicated consignment bill that feeds the existing Purchase Payment/payables layer.

The first option likely offers faster integration, provided every stock, costing, report, return, and correction path explicitly respects the no-receipt source type.

## 6. PKP, non-PKP, and tax behavior

Customer output VAT and supplier input VAT are separate transactions:

```text
Company -> Customer: Sales/POS output VAT
Supplier -> Company: supplier invoice and possible input VAT
```

Billing allocation must not recalculate or change the customer sale's tax. Supplier-side treatment depends on company PKP status, supplier PKP status, the goods and transaction, the applicable DPP method, and input-tax creditability.

### Non-PKP company

- the company does not treat supplier VAT as creditable input VAT;
- a PKP supplier may nevertheless charge VAT on a taxable supply;
- any non-creditable VAT must remain represented in the supplier bill and be posted according to the approved accounting policy, commonly as part of acquisition/HPP cost rather than a creditable input-tax balance;
- the system must not force every non-PKP-company supplier bill to zero VAT.

### PKP company

- billing confirmation requires the applicable supplier-side tax decision for every allocation line;
- generated Purchase preserves tax ID, rate/snapshot, tax-included choice, pre-tax amount, and tax amount;
- lines grouped into one supplier Purchase must share a valid company/supplier tax context;
- tax calculations must use the same normalization rules as ordinary Purchase creation;
- approval must fail if required tax configuration is unavailable or unresolved.

A PKP company can receive a bill from a non-PKP supplier or for a transaction without creditable input VAT. The consignment design must not assume that company PKP status makes every supplier line taxable or creditable.

Receiving-time tax bucket treatment also needs an explicit decision. Because the supplier bill occurs only after sale, the system must define whether consignment custody enters `quantity_tax`, `quantity_non_tax`, or a separately identified consignment provenance bucket before billing. This affects POS source-tax behavior and cannot safely be inferred after the sale.

Billing confirmation must preserve actual sale/dispatch date, allocation-confirmation date, supplier invoice date, Faktur Pajak date/number, and tax period separately. A delayed confirmation must not silently move the underlying event into a later tax month.

## 7. Reversals and returns

- Rejected receiving notes create no stock or ownership pool.
- Supplier return before sale reduces the appropriate receipt pool and physical stock.
- Customer return before billing reduces sold eligibility and restores the original serial or non-serial consignment state.
- Customer return after confirmation but before Purchase creation removes/reverses the draft allocation.
- Customer return after Purchase creation requires a linked Purchase Return, credit, or next-period settlement adjustment; it must not delete the original bill lineage.
- Voided/cancelled sales follow the same allocation reversal principle.

## 8. Core equations

For a non-serialized product and company:

```text
billable sold quantity
= qualifying quantity actually dispatched/allocated from consignment locations
- customer-returned/voided quantity
- quantity in approved billing allocations
- quantity reserved by active confirmation drafts (for concurrency control)
```

For each supplier receipt pool:

```text
allocatable supplier quantity
= approved received quantity
- returned-to-supplier quantity
- ownership adjustments/losses
- approved billing allocations
- active draft reservations
```

Every approved confirmation must satisfy:

```text
sum(supplier allocations) = quantity being billed
```

and every allocation must be less than or equal to its supplier pool's allocatable quantity.

## 9. Next design questions

1. Which event makes a sale eligible: approval, dispatch, POS completion, or customer payment?
2. Does billing confirmation itself require draft/submit/approve/reject states?
3. Does approval generate the Purchase immediately, or does a separate action generate it?
4. How should a generated no-receipt Purchase be represented without breaking current Purchase reports and payment eligibility?
5. Is supplier cost fixed at consignment receival, editable at billing confirmation, or governed by an agreement?
6. For PKP companies, how is supplier PKP/non-PKP status represented and validated?
7. Which tax/non-tax stock bucket receives consignment goods before the bill exists?
8. May one confirmation combine sales from multiple dates and locations?
9. How are bundle sales allocated to supplier-owned components?
10. What is the reservation expiry/cancellation rule for draft non-serial allocations?
11. How are supplier PKP status, tax-invoice identity, DPP method, and input-tax creditability represented?
12. What cross-period rule applies when sale, confirmation, supplier invoice, and Faktur Pajak dates differ?

## 10. Confirmed sourcing decisions

1. Normal Sales uses the user-selected dispatch location and its persisted actual dispatch provenance.
2. POS uses the existing configured sale-location priority order without a new consignment override.
3. Mixed standard/consignment fulfillment is split by actual source quantity for billing eligibility.
4. Historical billing eligibility uses persisted sale-source provenance, never today's location flag, priority order, or stock balance.
