# Decision Register

## Confirmed baseline

- This is inbound supplier consignment.
- Goods may be received and held without a supplier bill.
- The supplier bill/liability is triggered by sale of the item.
- Payment is a later, separate event governed by normal payment terms.
- Consignment receiving follows document approval and receiving-note approval.
- Consignment goods may enter only consignment-classified locations.
- Serialized ownership is derived from receiving lineage.
- Non-serialized sold quantity is manually allocated across eligible supplier receipt balances before billing.
- Normal Sales derives consignment eligibility from the location selected during dispatch.
- POS derives consignment eligibility from the existing configured sale-location priority order.
- Customer output VAT and supplier input VAT are separate events and records.
- Billing confirmation is an allocation/verification action; it does not freely choose the legal sale or tax date.
- Outbound consignment is not part of the currently described need.

## Current recommendations (provisional)

| ID | Recommendation | Reason | Revisit when |
|---|---|---|---|
| D-001 | Build inbound supplier consignment only | This is the confirmed business meaning | A separate outbound need is described later |
| D-002 | Reuse Location for physical custody | It is already the stock/serial/POS integration seam | Never; ownership remains a separate provenance concern |
| D-003 | Do not rely on `is_consignment` for ownership | It only identifies an eligible location; supplier ownership comes from receiving provenance | Never |
| D-004 | Allow multiple suppliers in a consignment location | This is required for manual allocation of fungible products | Never, unless operations later require physical segregation |
| D-005 | Preserve agreement provenance on events | Current location metadata can change | Never |
| D-006 | Separate custody receipt from ordinary supplier billing | The bill does not arise at receipt; it arises when the item is sold | Business rules prove otherwise |
| D-007 | Defer outbound consignment | It is a different business workflow | A separate outbound need is described |

## Critical unanswered questions

### Business and commercial

1. Which system event counts as “sold”: sale approval, dispatch, POS completion, or customer payment?
2. Is the supplier amount a fixed unit cost or calculated from the final selling price?
3. Does one confirmation create one Purchase per supplier, and may confirmation group multiple sales/dates?
4. Do discounts change the amount owed to the supplier?
5. Can terms vary by product, category, quantity tier, or date?
6. Who bears tax, customer returns, damage, loss, expiration, and shrinkage?

### Inventory

7. Must multiple suppliers share one physical warehouse/location?
8. May owned and consigned units of the same SKU mix physically?
9. Are serial items, fractional UOM, tax/non-tax buckets, and bundles required in MVP?
10. May consigned goods move between businesses or only locations of one business?
11. Can consigned goods be converted into purchased/owned inventory, and who approves it?

### Sales and returns

12. Should consignment locations be placed freely anywhere in the existing POS priority order, with no additional preference rule?
13. If a cart uses owned and consigned stock, may it remain one customer receipt?
14. How are bundle revenue, discount, and supplier obligation allocated to components?
15. What happens when a customer return occurs after the supplier was paid?

### Finance and reporting

16. Which accounting policy and journal entries are required?
17. Does the current deployment use the ERP journal as authoritative accounting?
18. Are settlements payable documents, purchase invoices, or a new statement type?
19. What operational and statutory reports are mandatory?
20. Is backdating allowed, and how are closed settlement periods protected?
21. Is each supplier PKP or non-PKP, and where is that status and effective history maintained?
22. For each product/supplier transaction, is VAT charged and, if so, is it creditable by the company?
23. Which date controls the supplier tax period under the contract and consistently applied accounting policy?
24. May supplier bills/Faktur Pajak be aggregated monthly, and what is the operational cutoff?

## Validation examples to obtain

Before specification, collect at least these real examples:

- one agreement/contract or representative term sheet;
- one inbound delivery with several SKUs;
- one normal sale and one discounted sale;
- one partial customer return after settlement;
- one unsold return to supplier;
- one damage/shrinkage case;
- one month-end supplier statement and payment;
- one mixed owned/consigned customer transaction, if the business expects it.

## Explicit non-decisions

- Exact database and class names are not finalized.
- Accounting entries are not finalized without the business's policy.
- Whether location uses a boolean, enum, or derived classification is not finalized.
- Whether Purchase documents are reused is not finalized.
- Multi-owner stock buckets and outbound consignment are not approved scope.
- Final Indonesian tax treatment requires confirmation by the company's qualified tax adviser using its contracts, supplier statuses, goods, and invoicing practice.
