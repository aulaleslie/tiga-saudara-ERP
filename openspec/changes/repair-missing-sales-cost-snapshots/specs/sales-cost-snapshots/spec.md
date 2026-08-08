## ADDED Requirements

### Requirement: Nearest-purchase repair fills only zero-cost sale details
The system SHALL provide a repair path that assigns cost snapshots to sale details whose cost snapshot is missing, and SHALL restrict its writes to sale details whose `cost_unit_snapshot` is less than or equal to zero or null.

#### Scenario: Repair writes into a zero-cost sale detail
- **WHEN** the repair runs in write mode against a sale detail with `cost_unit_snapshot` equal to zero
- **AND** an anchor purchase is resolved for that sale detail
- **THEN** it SHALL write `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` for that sale detail

#### Scenario: Repair never overwrites a positive cost snapshot
- **WHEN** the repair evaluates a sale detail whose `cost_unit_snapshot` is greater than zero
- **THEN** it SHALL leave that sale detail unchanged
- **AND** it SHALL leave it unchanged regardless of which source label the row carries
- **AND** no command option SHALL permit overwriting a positive cost snapshot

#### Scenario: Repair exposes no force mode
- **WHEN** the repair command surface is inspected
- **THEN** it SHALL NOT offer a force option that widens writes beyond zero-cost sale details

#### Scenario: Repair defaults to dry run
- **WHEN** the repair command runs without an explicit write option
- **THEN** it SHALL NOT modify any database row
- **AND** it SHALL report the sale details it would have repaired and the source label each would receive

#### Scenario: Repair scope matches the audit scope
- **WHEN** the repair selects candidate sale details
- **THEN** it SHALL include only sale details whose parent sale status is `Completed`, `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`
- **AND** it SHALL honour setting, product, start date, and end date filters

### Requirement: Repair resolves an anchor purchase preferring cost current at sale time
The system SHALL select the cost for a repaired sale detail from a single eligible purchase of the same product, preferring the nearest purchase on or before the sale date over any later purchase.

#### Scenario: Prior purchase is preferred over later purchase
- **WHEN** a zero-cost sale detail's product has an eligible purchase on or before the sale date in the sale's cost bucket
- **AND** it also has an eligible purchase after the sale date
- **THEN** the repair SHALL anchor on the nearest purchase on or before the sale date
- **AND** it SHALL prefer that prior purchase even when the later purchase is nearer in absolute days

#### Scenario: Later purchase is used when no prior purchase exists
- **WHEN** a zero-cost sale detail's product has no eligible purchase on or before the sale date in the sale's cost bucket
- **AND** it has an eligible purchase after the sale date in that bucket
- **THEN** the repair SHALL anchor on the nearest purchase after the sale date
- **AND** it SHALL record a source label distinguishing the forward-costed anchor

#### Scenario: Cross-bucket purchase is the last anchor rung
- **WHEN** a zero-cost sale detail's product has no eligible purchase in the sale's own cost bucket
- **AND** an eligible purchase exists in another cost bucket
- **THEN** the repair SHALL anchor on the nearest such purchase, preferring one on or before the sale date
- **AND** it SHALL record a source label distinguishing the cross-bucket anchor

#### Scenario: Anchor cost uses receipt-aware landed cost
- **WHEN** the repair prices an anchor purchase detail
- **THEN** it SHALL calculate the unit cost using the same receipt-aware, tax-exclusive, discount-adjusted landed cost calculation the historical replay uses
- **AND** it SHALL prefer approved received-note quantities when they exist
- **AND** it SHALL exclude a `RECEIVED PARTIALLY` purchase that has no approved receipt

#### Scenario: Repaired total is derived from the unit cost
- **WHEN** the repair writes a cost snapshot
- **THEN** `cost_total_snapshot` SHALL equal the anchored unit cost multiplied by the sale detail quantity
- **AND** both values SHALL be expressed in the same base unit

### Requirement: Products without purchase history receive a terminal label
The system SHALL mark sale details whose product has no eligible purchase history so they are not re-examined as unresolved work, without asserting a fabricated cost.

#### Scenario: No purchase history yields a labeled zero
- **WHEN** the repair evaluates a zero-cost sale detail whose product has no eligible purchase
- **THEN** it SHALL leave `cost_unit_snapshot` and `cost_total_snapshot` at zero
- **AND** it SHALL write a distinct source label indicating that no purchase history exists

#### Scenario: Terminal rows are skipped on later runs
- **WHEN** the repair runs again over a sale detail already carrying the no-purchase-history source label
- **THEN** it SHALL skip that sale detail without re-resolving an anchor
- **AND** it SHALL report those rows as already adjudicated rather than as newly repaired

#### Scenario: Terminal labeling is not applied to covered rows
- **WHEN** a sale detail has a `cost_unit_snapshot` greater than zero
- **THEN** the repair SHALL NOT write the no-purchase-history label to that sale detail

### Requirement: Repair records anchor evidence and enforces a distance guard
The system SHALL make each repaired cost traceable to the purchase it was derived from, and SHALL refuse to anchor on evidence beyond a configured time distance.

#### Scenario: Anchor evidence is recorded
- **WHEN** the repair writes an anchored cost snapshot
- **THEN** it SHALL record the identifier of the anchor purchase detail
- **AND** it SHALL record the signed distance in days between the anchor purchase date and the sale date

#### Scenario: Distance guard fails closed to zero
- **WHEN** the repair runs with a maximum distance option
- **AND** the nearest eligible anchor purchase is further from the sale date than that maximum
- **THEN** the repair SHALL NOT write an anchored cost for that sale detail
- **AND** it SHALL leave the cost snapshot at zero
- **AND** it SHALL report the sale detail as skipped for exceeding the distance guard

#### Scenario: Repair reports outcomes by rung
- **WHEN** the repair completes
- **THEN** it SHALL report counts of sale details repaired from a prior purchase, from a later purchase, and from a cross-bucket purchase
- **AND** it SHALL report counts of sale details labeled as having no purchase history, skipped for exceeding the distance guard, and skipped as already covered

#### Scenario: Repair source labels are reversible
- **WHEN** a repair run has written anchored cost snapshots
- **THEN** each anchor rung SHALL use a source label distinct from the labels written by live sale posting, historical backfill, HPP snapshot import, and correction replay
- **AND** resetting the cost snapshots carrying a given repair label SHALL restore the affected sale details to their prior zero-cost state

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
- **THEN** it SHALL leave unchanged any sale detail whose `cost_snapshot_source` is `HPP_SNAPSHOT_IMPORT`
- **AND** it SHALL leave unchanged any sale detail whose `cost_snapshot_source` begins with `BACKFILL_`
- **AND** it SHALL leave unchanged any sale detail carrying a source label written by the nearest-purchase repair path
- **AND** it SHALL fill remaining eligible sale details

#### Scenario: Backfill does not revisit zero-cost rows it previously stamped
- **WHEN** the backfill command runs in write mode without force
- **AND** a sale detail carries `cost_snapshot_source` of `BACKFILL_ZERO_FALLBACK` with zero cost
- **THEN** the backfill command SHALL leave that sale detail unchanged
- **AND** resolving that zero-cost sale detail SHALL be the responsibility of the nearest-purchase repair path

#### Scenario: Backfill can force recomputation
- **WHEN** the backfill command runs with force mode
- **THEN** it SHALL recompute matching sale detail cost snapshots
- **AND** it SHALL continue to leave `HPP_SNAPSHOT_IMPORT` sale details unchanged
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
