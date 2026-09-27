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
The system SHALL provide a backfill command that fills sale detail cost snapshots by replaying historical product purchase, purchase return, and sale events in effective-date order using the sale's historical cost bucket.

#### Scenario: Backfill uses purchases up to sale date
- **WHEN** the backfill command evaluates a stock-managed sale detail
- **THEN** it SHALL calculate the sale cost from cumulative purchase cost and purchase quantity for the same `product_id` in the sale detail's historical cost bucket with effective dates less than or equal to the sale date
- **AND** it SHALL NOT use purchases whose effective dates are after the sale date unless no earlier purchase exists for the applicable fallback source

#### Scenario: Tiga Nusa backfill uses isolated bucket history
- **WHEN** the backfill command evaluates a stock-managed sale detail whose parent sale belongs to `CV TIGA NUSA COMPUTER`
- **THEN** it SHALL replay `CV TIGA NUSA COMPUTER` purchases, purchase returns, and sales for that product in the Tiga Nusa bucket
- **AND** it MUST NOT include `CV TOP IT INTERNUSA` or REST/global events in the Tiga Nusa running quantity or running average while that bucket has eligible purchase history

#### Scenario: Top IT backfill uses isolated bucket history
- **WHEN** the backfill command evaluates a stock-managed sale detail whose parent sale belongs to `CV TOP IT INTERNUSA`
- **THEN** it SHALL replay `CV TOP IT INTERNUSA` purchases, purchase returns, and sales for that product in the Top IT bucket
- **AND** it MUST NOT include `CV TIGA NUSA COMPUTER` or REST/global events in the Top IT running quantity or running average while that bucket has eligible purchase history

#### Scenario: Non-special settings use REST/global bucket history
- **WHEN** the backfill command evaluates a stock-managed sale detail whose parent sale belongs to a setting other than `CV TIGA NUSA COMPUTER` or `CV TOP IT INTERNUSA`
- **THEN** it SHALL replay purchases, purchase returns, and sales from all non-special settings for that product in the REST/global bucket
- **AND** it MUST NOT include `CV TIGA NUSA COMPUTER` or `CV TOP IT INTERNUSA` events in the REST/global running quantity or running average

#### Scenario: Purchase returns consume within their bucket
- **WHEN** the backfill command replays a completed purchase return detail
- **THEN** it SHALL classify the purchase return by its parent `purchase_returns.setting_id`
- **AND** it SHALL consume quantity and value only from that bucket's running state

#### Scenario: Special bucket falls back to REST/global purchase history
- **WHEN** a stock-managed sale detail belongs to `CV TIGA NUSA COMPUTER` or `CV TOP IT INTERNUSA`
- **AND** that special bucket has no eligible purchase event for the product
- **AND** the REST/global bucket has eligible purchase history for the product
- **THEN** the backfill command SHALL use the REST/global purchase history as the cost fallback for that special sale detail
- **AND** it SHALL report the existing future-purchase fallback warning when the selected REST/global fallback purchase is after the sale date

#### Scenario: Backfill uses future purchase fallback
- **WHEN** a stock-managed sale detail has no purchase event on or before the sale date in its historical cost bucket or applicable REST/global fallback source
- **AND** that bucket or fallback source has a later purchase event
- **THEN** the backfill command SHALL use the earliest later purchase average as the cost fallback
- **AND** it SHALL report a future-purchase fallback warning

#### Scenario: Backfill uses zero fallback without purchase history
- **WHEN** a stock-managed sale detail has no historical or future purchase event for its product in its historical cost bucket or applicable REST/global fallback source
- **THEN** the backfill command SHALL store zero cost
- **AND** it SHALL report a no-purchase-history warning

#### Scenario: Setting filter preserves exact write scope
- **WHEN** the backfill command runs with `--setting` for `CV TIGA NUSA COMPUTER` or `CV TOP IT INTERNUSA`
- **THEN** it SHALL only write or count sale details for that exact setting
- **AND** it SHALL replay the matching special bucket for those sale details

#### Scenario: Non-special setting filter uses REST/global context
- **WHEN** the backfill command runs with `--setting` for a setting other than `CV TIGA NUSA COMPUTER` or `CV TOP IT INTERNUSA`
- **THEN** it SHALL only write or count sale details for that exact setting
- **AND** it SHALL calculate those sale detail costs from the REST/global bucket context

#### Scenario: Backfill ignores import order
- **WHEN** purchases and sales were imported in bulk with creation timestamps that do not match transaction history
- **THEN** the backfill command SHALL order cost replay by purchase, receiving, sale, and return effective dates within each historical cost bucket
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
- **WHEN** purchase, purchase return, and sale events for the same product and historical cost bucket share the same effective timestamp
- **THEN** the backfill command SHALL process purchase or approved receipt events before purchase return events
- **AND** it SHALL process purchase return events before sale events
- **AND** repeated runs over unchanged source data SHALL produce the same snapshot values

#### Scenario: Negative stock does not poison later averages
- **WHEN** historical replay causes running product quantity in a historical cost bucket to become zero or negative
- **THEN** the backfill command SHALL NOT carry negative running inventory value into the next positive average calculation for that bucket
- **AND** the next valid purchase event in that bucket SHALL reseed the moving-average basis for later sale costs
- **AND** the command SHALL report a negative-stock warning for the affected product bucket timeline

#### Scenario: Date-filtered backfill replays prior state
- **WHEN** the backfill command runs with a start date or end date filter
- **THEN** it SHALL replay prior product events needed to establish opening running quantity and average cost for each relevant historical cost bucket
- **AND** it SHALL only write or count sale details that match the requested product, setting, and date filters

#### Scenario: Backfill source labels remain stable
- **WHEN** the backfill command writes a bucket-aware sale detail cost snapshot
- **THEN** it SHALL continue using the existing `BACKFILL_RUNNING_AVERAGE`, `BACKFILL_FUTURE_PURCHASE`, `BACKFILL_ZERO_FALLBACK`, or `NON_STOCK_ZERO` source labels as applicable
- **AND** it SHALL NOT require a new bucket-specific `cost_snapshot_source` value

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

### Requirement: Authoritative HPP import can overwrite imported sale snapshots
The system SHALL allow an explicit sales HPP snapshot import to overwrite sale detail cost snapshots for matched imported sales, while preserving existing live-sale snapshot and historical backfill behavior.

#### Scenario: HPP import source supersedes prior snapshot
- **WHEN** a sales HPP snapshot import matches an existing imported sale detail
- **THEN** the import SHALL overwrite `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` for that sale detail
- **AND** the resulting HPP used by reports SHALL come from the imported HPP snapshot values.

#### Scenario: Backfill behavior remains unchanged
- **WHEN** the historical backfill command runs without force mode
- **THEN** it SHALL continue to preserve existing cost snapshots according to the existing backfill rules
- **AND** it SHALL NOT change the authoritative overwrite behavior of the explicit sales HPP snapshot import.

