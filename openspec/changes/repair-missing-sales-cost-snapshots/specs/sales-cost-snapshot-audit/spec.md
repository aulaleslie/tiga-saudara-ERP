## ADDED Requirements

### Requirement: Audit command measures missing cost snapshots without mutation
The system SHALL provide a read-only console command that reports how many sale details lack a usable cost snapshot, and SHALL NOT write to any database row under any invocation.

#### Scenario: Audit performs no writes
- **WHEN** the audit command runs with any combination of options
- **THEN** it SHALL NOT modify `sale_details`, `products`, `product_prices`, or any other table
- **AND** it SHALL NOT expose a write or force option

#### Scenario: Audit reports the total gap
- **WHEN** the audit command completes
- **THEN** it SHALL report the total number of in-scope sale details
- **AND** it SHALL report the number whose cost snapshot is missing
- **AND** it SHALL report the number whose cost snapshot is present and usable

### Requirement: Missing cost snapshot is defined by cost value, not source label
The system SHALL define a missing cost snapshot as `cost_unit_snapshot` being less than or equal to zero or null, and SHALL NOT use `cost_snapshot_source` to decide whether a sale detail is missing a cost snapshot.

#### Scenario: Zero cost with a backfill source counts as missing
- **WHEN** a sale detail has `cost_unit_snapshot` equal to zero and `cost_snapshot_source` of `BACKFILL_ZERO_FALLBACK`
- **THEN** the audit SHALL count that sale detail as missing a cost snapshot

#### Scenario: Zero cost with a live-sale source counts as missing
- **WHEN** a sale detail has `cost_unit_snapshot` equal to zero and `cost_snapshot_source` of `MISSING_AVERAGE_PRICE` or `CURRENT_AVERAGE_PRICE`
- **THEN** the audit SHALL count that sale detail as missing a cost snapshot

#### Scenario: Null cost counts as missing
- **WHEN** a sale detail has a null `cost_unit_snapshot` and a null `cost_snapshot_source`
- **THEN** the audit SHALL count that sale detail as missing a cost snapshot

#### Scenario: Positive cost counts as covered
- **WHEN** a sale detail has a `cost_unit_snapshot` greater than zero
- **THEN** the audit SHALL count that sale detail as covered
- **AND** it SHALL count it as covered regardless of which source label the row carries

#### Scenario: Source label is reported as diagnostic only
- **WHEN** the audit reports its breakdowns
- **THEN** it SHALL include a breakdown of missing sale details by current `cost_snapshot_source`
- **AND** that breakdown SHALL be presented as diagnostic information
- **AND** the audit SHALL NOT filter the missing population by source label

### Requirement: Audit partitions the gap by purchase-history repairability
The system SHALL split sale details missing a cost snapshot according to whether their product has any eligible purchase history, so that rows a purchase-derived cost can resolve are distinguished from rows it cannot.

#### Scenario: Product with eligible purchase history is repairable
- **WHEN** a sale detail is missing a cost snapshot
- **AND** at least one eligible purchase exists for that product
- **THEN** the audit SHALL classify that sale detail as repairable

#### Scenario: Product without eligible purchase history is terminal
- **WHEN** a sale detail is missing a cost snapshot
- **AND** no eligible purchase exists for that product
- **THEN** the audit SHALL classify that sale detail as terminal
- **AND** the audit SHALL report terminal rows separately from repairable rows

#### Scenario: Eligibility matches the repair path
- **WHEN** the audit determines whether a product has eligible purchase history
- **THEN** it SHALL apply the same purchase-eligibility predicate the repair path uses to select an anchor purchase
- **AND** a sale detail classified as repairable SHALL be one the repair path can anchor

#### Scenario: Non-stock-managed lines are partitioned separately
- **WHEN** a sale detail is missing a cost snapshot
- **AND** its product has `stock_managed` set to false
- **THEN** the audit SHALL report that sale detail in a distinct non-stock-managed partition
- **AND** it SHALL NOT count that sale detail as repairable

### Requirement: Audit reports breakdown dimensions
The system SHALL report the missing-snapshot population across dimensions that identify where the gap is concentrated.

#### Scenario: Breakdown by setting
- **WHEN** the audit completes
- **THEN** it SHALL report missing sale detail counts grouped by the parent sale's `setting_id`

#### Scenario: Breakdown by sale period
- **WHEN** the audit completes
- **THEN** it SHALL report missing sale detail counts grouped by year and month of the parent sale's date

#### Scenario: Breakdown by product
- **WHEN** the audit completes
- **THEN** it SHALL report the products with the highest missing sale detail counts
- **AND** each reported product SHALL indicate whether it has eligible purchase history

#### Scenario: Distance histogram for repairable rows
- **WHEN** the audit completes
- **THEN** it SHALL report, for repairable sale details, the distribution of absolute day distance between the sale date and the nearest eligible purchase
- **AND** the distribution SHALL be grouped into bounded distance bands

### Requirement: Audit supports scoping and detail export
The system SHALL allow the audit to be narrowed to a subset of sales and to emit affected row identifiers for inspection.

#### Scenario: Scope filters restrict the audited population
- **WHEN** the audit command runs with a setting, product, start date, or end date filter
- **THEN** it SHALL count and report only sale details matching those filters

#### Scenario: Sale status scope excludes non-final sales
- **WHEN** the audit selects in-scope sale details
- **THEN** it SHALL include only sale details whose parent sale status is `Completed`, `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`
- **AND** it SHALL exclude draft and pending sales

#### Scenario: Detail export lists affected rows
- **WHEN** the audit command runs with the detail export option
- **THEN** it SHALL write the affected sale detail identifiers with their sale reference, sale date, setting, product, quantity, current cost snapshot, current source, and repairability classification to a file
- **AND** it SHALL NOT modify any database row while doing so
