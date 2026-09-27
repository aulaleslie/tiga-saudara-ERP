## MODIFIED Requirements

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
