# Recommended Domain Model

This is a conceptual model for further specification, not a final table design.

```text
Business (setting)                 Counterparty (initially Supplier)
       |                                      |
       | operates                             | legal owner / consignee
       v                                      v
    Location             Consignment Agreement/Account
       |                         |
       | physical custody        | supplier, terms, dates, status
       v                         v
 Product Stock <------- Consignment Receipt Lines
       |                         |
       | aggregate quantity       | supplier-owned balance
       |                         v
       +---------------- Billing Allocations
                                 v
                         Purchase / Supplier Bill
                                 |
                                 v
                         Payment / journal link
```

## Core concepts

### Location

Keep the current physical stock role. Add `is_consignment` to make eligible consignment locations discoverable and enforce it during receiving.

Do not redefine `locations.setting_id` as inventory owner. It should remain the operating/business context already assumed throughout the system.

### Consignment agreement/account

Minimum candidate fields:

- operating `setting_id`;
- optional/default `location_id` if terms are location-scoped;
- supplier identity;
- agreement/reference number;
- effective start/end dates and `ACTIVE|SUSPENDED|CLOSED` status;
- settlement basis: fixed unit cost, percentage/commission, or another explicitly scoped rule;
- settlement cadence and payment terms;
- tax treatment and currency if the application supports multiple currencies;
- created/approved/closed audit fields.

Terms should be versioned or snapshotted on movements/sale allocations. Editing an agreement must not reprice historical obligations.

### Consignment movement/provenance

Record business events that explain why consignment quantity changed. Candidate event types:

- `RECEIPT`;
- `TRANSFER_OUT` / `TRANSFER_IN`;
- `SALE`;
- `CUSTOMER_RETURN`;
- `RETURN_TO_OWNER`;
- `OWNERSHIP_CONVERSION`;
- `ADJUSTMENT_GAIN` / `ADJUSTMENT_LOSS`;
- `SETTLEMENT_INCLUDED` / reversal reference.

This record should link to existing received-note, dispatch/sale/POS, transfer, return, adjustment, inventory transaction, and settlement records rather than replacing them.

Receiving-line provenance is required because multiple suppliers' units can share one physical product/location stock bucket. Each approved receipt line must retain supplier, product, base quantity, remaining returnable quantity, remaining unbilled ownership quantity, tax context, cost terms, and source document links.

Serialized products resolve supplier ownership through the sold serial's approved receiving lineage. Non-serialized products use an explicit allocation confirmed by the user, bounded by both sold-but-unbilled quantity and each supplier receipt pool's unbilled balance.

### Supplier billing obligation and settlement

Selling a consigned item makes it eligible for supplier billing. A settlement statement or supplier bill groups eligible sold movements, calculates the amount from the cost captured for each movement, and supports approval, later payment, reversal, and audit. Billing and payment are separate events.

Suggested lifecycle:

```text
DRAFT -> CALCULATED -> APPROVED -> PARTIALLY_PAID -> PAID
   |          |            |
   +----------+------------+-> VOIDED/REVERSED (controlled)
```

Movement inclusion must be idempotent: one eligible movement cannot be settled twice. A customer return after settlement needs an explicit credit in a later statement or a controlled reversal—not silent mutation of a paid statement.

## Inbound lifecycle

```text
Agreement active
      |
Consignment receipt (no normal supplier payable yet)
      |
Stock available at dedicated consignment location
      |
Sale/POS dispatch consumes exact source provenance
      |
Supplier obligation becomes eligible
      |
Settlement statement -> approval -> payment
```

Key rule: physical receipt is not an ordinary purchase unless commercial ownership transfers at receipt. If the business currently uses Purchase for operational convenience, introduce a clearly distinct consignment document type whose financial posting is suppressed or specialized.

## Outbound lifecycle — not currently required

```text
Transfer owned stock to external consignment location
      |
Remain owned inventory, classified as consigned-out
      |
Consignee reports sale / acceptance
      |
Recognize sale, commission/receivable, and HPP
      |
Collect payment or return unsold goods
```

This is a different business case and is outside the confirmed requirement. It should not be included unless the user later describes goods owned by this business but held and sold by another party.

## Recommended hard constraints for the first release

1. Inbound supplier consignment only: receive now, create the supplier billing obligation when sold, and pay later through the normal payable process.
2. Multiple suppliers may share a consignment location; ownership remains separated by approved receipt provenance.
3. A location cannot switch between standard and consignment while any quantity, serial, open movement, or unsettled obligation exists.
4. Consignment receipt does not update ordinary purchase averages or create an ordinary payable.
5. Standard stock may not be transferred into a consignment location without an explicit ownership-conversion operation.
6. Consigned stock may only be sold through workflows that preserve its location/agreement provenance.
7. Negative stock is prohibited.
8. Customer returns restore original consignment provenance; supplier returns reduce held consignment without creating a customer-facing reversal.
9. Bundles require component-level provenance and allocation before they are allowed.
10. Serial-tracked items preserve agreement ownership on each serial event.
