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

### Requirement: Product average purchase price is synchronized globally
The system SHALL keep the same `average_purchase_price` for a product across all `product_prices` setting rows.

#### Scenario: Future purchase approval updates all setting price rows
- **WHEN** a future purchase or receiving approval changes a product average purchase price
- **THEN** the system SHALL update `average_purchase_price` to the same value for every available setting's `product_prices` row for that product
- **AND** it SHALL create missing product price rows needed for synchronization

#### Scenario: Sale reads setting-local product price row
- **WHEN** a future sale snapshots product cost
- **THEN** it SHALL read `average_purchase_price` from the sale's own setting product price row
- **AND** the value SHALL match the globally synchronized product average

### Requirement: Returns reverse historical cost correctly
The system SHALL reverse product cost from original cost snapshots when returns affect profit/loss.

#### Scenario: Sale return reverses original sale cost
- **WHEN** a completed sale return detail references an original sale detail
- **THEN** the sales cost reversal SHALL use the original sale detail `cost_unit_snapshot` multiplied by returned quantity
- **AND** it SHALL recognize the reversal in the return date period

#### Scenario: Purchase return affects later moving average
- **WHEN** a purchase return is present in the historical transaction replay
- **THEN** the replay SHALL reduce available stock quantity and stock value
- **AND** if original purchase cost cannot be resolved it SHALL reduce value using the running average at the return date

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
- **WHEN** the backfill encounters missing purchase history, future purchase fallback, non-stock-managed zero cost, duplicate product identity, negative stock, archived skipped documents, or missing product price rows
- **THEN** it SHALL include those categories in command output

