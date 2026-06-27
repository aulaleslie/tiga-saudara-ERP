# sales-cost-snapshots Specification

## Purpose
TBD - created by archiving change add-sales-cost-snapshots-profit-loss. Update Purpose after archive.
## Requirements
### Requirement: Sale details persist product cost snapshots
The system SHALL persist product sales cost snapshots on sale detail rows so historical profit/loss reports do not depend on mutable product average purchase prices.

#### Scenario: Stock-managed sale detail snapshots current average cost
- **WHEN** a live standard sale or POS sale posts a stock-managed product line
- **THEN** the sale detail SHALL store the current average purchase price from the sale setting's `product_prices` row as `cost_unit_snapshot`
- **AND** the sale detail SHALL store `cost_total_snapshot` as line quantity multiplied by `cost_unit_snapshot`
- **AND** the sale detail SHALL record snapshot metadata identifying the live snapshot source and snapshot time

#### Scenario: Non-stock-managed sale detail receives zero cost
- **WHEN** a live standard sale or POS sale posts a non-stock-managed product or service line
- **THEN** the sale detail SHALL store zero cost snapshots
- **AND** the sale detail SHALL record a snapshot source indicating non-stock-managed zero cost

#### Scenario: Missing average price receives zero cost warning
- **WHEN** a stock-managed sale detail has no resolvable average purchase price
- **THEN** the system SHALL store zero cost for that detail
- **AND** the snapshot source or backfill warning SHALL identify the missing purchase-cost fallback

### Requirement: Historical backfill replays product cost by effective date
The system SHALL provide a backfill command that fills sale detail cost snapshots by replaying historical product purchase and sale events in effective-date order.

#### Scenario: Backfill uses purchases up to sale date
- **WHEN** the backfill command evaluates a stock-managed sale detail
- **THEN** it SHALL calculate the sale cost from cumulative purchase cost and purchase quantity for the same `product_id` with effective dates less than or equal to the sale date
- **AND** it SHALL NOT use purchases whose effective dates are after the sale date unless no earlier purchase exists

#### Scenario: Backfill uses future purchase fallback
- **WHEN** a stock-managed sale detail has no purchase event on or before the sale date but has a later purchase event
- **THEN** the backfill command SHALL use the earliest later purchase average as the cost fallback
- **AND** it SHALL report a future-purchase fallback warning

#### Scenario: Backfill uses zero fallback without purchase history
- **WHEN** a stock-managed sale detail has no historical or future purchase event for its product
- **THEN** the backfill command SHALL store zero cost
- **AND** it SHALL report a no-purchase-history warning

#### Scenario: Backfill ignores import order
- **WHEN** purchases and sales were imported in bulk with creation timestamps that do not match transaction history
- **THEN** the backfill command SHALL order cost replay by purchase, receiving, sale, and return effective dates
- **AND** it SHALL NOT calculate sale cost from database insertion order

#### Scenario: Backfill preserves existing snapshots by default
- **WHEN** the backfill command runs in write mode without force
- **THEN** it SHALL fill only sale details whose cost snapshots are null
- **AND** it SHALL leave existing cost snapshots unchanged

#### Scenario: Backfill can force recomputation
- **WHEN** the backfill command runs with force mode
- **THEN** it SHALL recompute matching sale detail cost snapshots
- **AND** repeated force runs over unchanged source data SHALL produce the same snapshot values

#### Scenario: Same-date replay uses deterministic event ordering
- **WHEN** purchase, purchase return, and sale events for the same product share the same effective timestamp
- **THEN** the backfill command SHALL process purchase or approved receipt events before purchase return events
- **AND** it SHALL process purchase return events before sale events
- **AND** repeated runs over unchanged source data SHALL produce the same snapshot values

#### Scenario: Negative stock does not poison later averages
- **WHEN** historical replay causes running product quantity to become zero or negative
- **THEN** the backfill command SHALL NOT carry negative running inventory value into the next positive average calculation
- **AND** the next valid purchase event SHALL reseed the moving-average basis for later sale costs
- **AND** the command SHALL report a negative-stock warning for the affected product timeline

#### Scenario: Date-filtered backfill replays prior state
- **WHEN** the backfill command runs with a start date or end date filter
- **THEN** it SHALL replay prior product events needed to establish opening running quantity and average cost
- **AND** it SHALL only write or count sale details that match the requested product, setting, and date filters

### Requirement: Purchase cost uses tax-exclusive DPP
The system SHALL calculate purchase average cost from tax-exclusive purchase DPP after line discount.

#### Scenario: Tax-included purchase line contributes DPP cost
- **WHEN** a purchase line includes product tax amount
- **THEN** the cost replay SHALL subtract product tax from the line subtotal before calculating unit purchase cost
- **AND** PPN/input tax SHALL NOT increase product average purchase cost

#### Scenario: Line discount reduces purchase cost
- **WHEN** a purchase line has product discount amount
- **THEN** the cost replay SHALL reduce line DPP by the discount before calculating unit purchase cost

#### Scenario: Purchase import DPP is respected
- **WHEN** purchase import stores tax-included unit price but tax-exclusive subtotal and tax amount are available
- **THEN** the cost replay SHALL calculate purchase cost from stored subtotal, tax amount, discount, and quantity
- **AND** it SHALL NOT rely on unit price alone

#### Scenario: Receipt-prorated purchase cost uses discounted DPP
- **WHEN** a purchase detail is converted into one or more approved receipt events
- **THEN** each receipt event SHALL prorate the purchase detail cost after tax and discount by received quantity over ordered quantity
- **AND** the total cost of receipt events SHALL NOT exceed the discounted tax-exclusive purchase detail cost

### Requirement: Backfill reports audit warnings and summaries
The system SHALL make the backfill command auditable before and after writes.

#### Scenario: Dry run reports planned work
- **WHEN** the backfill command runs without write mode
- **THEN** it SHALL not update database rows
- **AND** it SHALL report scanned sale detail counts, fillable snapshot counts, unchanged counts, and warning counts

#### Scenario: Write run reports applied work
- **WHEN** the backfill command runs in write mode
- **THEN** it SHALL report updated sale detail counts, created product price row counts, skipped row counts, and warning counts

#### Scenario: Warning categories are visible
- **WHEN** the backfill encounters missing purchase history, future purchase fallback, non-stock-managed zero cost, duplicate product identity, negative stock, archived skipped documents, missing product price rows, or suspicious unit costs
- **THEN** it SHALL include those categories in command output

#### Scenario: Suspicious unit costs are not silently written
- **WHEN** the backfill computes a stock-managed unit cost that is negative, non-finite, or greater than the configured maximum reasonable unit cost
- **THEN** the command SHALL report the row as suspicious with product ID, product code, sale detail ID, sale date, running quantity, running value, and computed unit cost
- **AND** it SHALL NOT write the suspicious computed unit cost as a valid `BACKFILL_RUNNING_AVERAGE` snapshot
- **AND** write mode SHALL continue processing remaining eligible rows

### Requirement: Historical backfill runs efficiently at production scale
The system SHALL execute historical cost backfill with bounded query amplification and memory usage suitable for production-sized sales history.

#### Scenario: Backfill avoids unnecessary model hydration
- **WHEN** the backfill command reads products, purchase details, purchase returns, received notes, sales, or sale details for replay
- **THEN** it SHALL select only fields needed for cost replay, filtering, warning output, and snapshot writes
- **AND** it SHALL avoid default eager-loaded relations that are not needed by the replay

#### Scenario: Backfill streams or chunks replay events
- **WHEN** the backfill command processes more than one product
- **THEN** it SHALL process replay events in product/date order using chunked or streamed reads
- **AND** it SHALL avoid issuing separate purchase, purchase-return, and sale timeline queries for every product when an equivalent batched event replay is available

#### Scenario: Backfill batches writes
- **WHEN** write mode computes multiple valid sale detail snapshots
- **THEN** the command SHALL persist snapshot updates in bounded batches or another efficient strategy
- **AND** it SHALL preserve force and non-force overwrite semantics

