# Solution Options

## First distinguish the business modes

### Inbound consignment

A supplier owns goods physically held by one of our businesses. We sell them and later owe the supplier according to agreed terms.

### Outbound consignment

Our business owns goods physically held by an agent, reseller, customer, or external site. Revenue/payable recognition happens after the consignee reports a sale or accepts ownership.

These modes share custody and provenance concepts, but their operational documents and accounting are mirrored. Supporting both in the first release materially increases scope.

## Option A — location boolean only

Add `locations.is_consignment` and let existing purchase, transfer, stock, and sale flows operate against that location.

Advantages:

- smallest schema/UI change;
- immediate visual and query-level segregation;
- reuses almost all inventory behavior.

Limits:

- cannot identify owner/counterparty or agreement;
- cannot support two consignors at one location;
- cannot calculate settlement reliably;
- changing the flag can reinterpret historical stock;
- risks including supplier-owned goods in owned inventory valuation and HPP;
- does not distinguish inbound from outbound.

Use only for a prototype or if “consignment” is merely an operational label with no financial workflow.

## Option B — typed location plus one agreement per location

Classify the location and associate it with one active consignment agreement. All stock at that location belongs to that agreement until sold, returned, or explicitly converted.

Advantages:

- quick integration with current location stock buckets;
- clear ownership without redesigning `product_stocks`;
- supports receipt, sales consumption, returns, and settlement;
- dedicated locations make reporting and operational controls understandable.

Limits:

- requires separate locations for each owner/agreement;
- identical SKU stock cannot be pooled across agreements;
- location transfers require strict rules;
- agreement changes need effective dating rather than overwriting history.

This was a possible constrained shape, but it no longer fits the clarified need because multiple suppliers' units may share a consignment location.

## Option C — ownership dimension on every stock bucket

Extend stock identity from `(product, location, tax/broken)` to include ownership/agreement or create a stock-lot/stock-position layer with that dimension.

Advantages:

- multiple consignors can share a physical location;
- exact lot-level costing and settlement;
- flexible inbound/outbound and ownership conversion;
- strongest audit trail.

Limits:

- broad changes across every stock query, lock, movement, serial, POS allocation, return, import, and report;
- higher migration and regression risk;
- current assumptions about one product/location stock row must be revisited.

Use as a later evolution when shared physical storage or multi-agreement pooling is a demonstrated requirement.

## Option D — parallel consignment subsystem

Create separate consignment stock tables and workflows, integrating only at sale time.

Advantages:

- isolates special rules;
- avoids immediately changing standard stock queries.

Limits:

- duplicates inventory, serial, transfer, return, and reporting logic;
- creates two sources of stock truth;
- POS availability and concurrency become difficult;
- long-term integration cost is high.

Not recommended for this ERP.

## Comparison

| Criterion | A: Boolean | B: Agreement/location | C: Ownership bucket | D: Parallel system |
|---|---:|---:|---:|---:|
| Delivery speed | Highest | High | Low | Medium initially |
| Financial correctness | Low | Good for constrained MVP | Highest | Variable |
| Reuse of current system | Highest | High | Medium | Low |
| Multi-owner same location | No | No | Yes | Yes |
| Auditability | Low | Good | Highest | Fragmented |
| Regression risk | Hidden/high | Controlled | High | High long-term |
| Recommended | Location classification only | No longer sufficient | Yes, narrowly applied to consignment provenance | No |

## Location classification decision

For the currently known binary requirement, `is_consignment` is simpler to validate, index, display, and maintain. Use `location_type` only if other mutually exclusive location kinds are actually planned. In either design, location classification is an admission rule—not supplier ownership provenance.

The recommended production approach is now a narrow form of Option C: keep the existing aggregate `product_stocks` bucket for physical availability, while adding supplier/receipt ownership provenance specifically for consignment receipt and billing allocation. A system-wide rewrite of every stock bucket is not required for the initial workflow.
