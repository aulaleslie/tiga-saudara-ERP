# product-last-purchase-cost-fill Specification

## Purpose
TBD - created by archiving change fill-average-cost-from-last-purchase-price. Update Purpose after archive.
## Requirements
### Requirement: Operator can preview the fill without writing

The system SHALL provide the `product:fill-average-cost-from-last-purchase-price` Artisan command in dry-run mode by default. It SHALL report the number of rows considered, filled from the row's own last purchase price, filled from a donor owner, left unchanged, and left unresolved, without writing `product_prices`.

#### Scenario: Dry run does not mutate product prices

- **WHEN** an operator runs `product:fill-average-cost-from-last-purchase-price` without `--write`
- **THEN** the command SHALL calculate and report prospective changes
- **AND** it SHALL NOT update any `product_prices` row

#### Scenario: Write mode applies the reported changes

- **WHEN** an operator runs the command with `--write`
- **THEN** the command SHALL apply the same changes it reports in dry-run mode for unchanged input data
- **AND** it SHALL report the applied counts under a write-mode heading

### Requirement: Only rows with no known average cost are eligible

The command SHALL treat a `product_prices` row as eligible only when its `average_purchase_price` is null or not positive. A null average SHALL be treated identically to a zero average, because both mean no cost is known.

#### Scenario: Null average is eligible

- **WHEN** a price row has `average_purchase_price` of null
- **THEN** the command SHALL treat the row as eligible for filling

#### Scenario: Zero average is eligible

- **WHEN** a price row has `average_purchase_price` of zero
- **THEN** the command SHALL treat the row as eligible for filling

#### Scenario: Positive average is never overwritten

- **WHEN** a price row has a positive `average_purchase_price`
- **THEN** the command SHALL leave the row unchanged and count it as unchanged
- **AND** no command flag SHALL cause that value to be overwritten

### Requirement: The row's own last purchase price is preferred

For an eligible row whose `last_purchase_price` is positive, the command SHALL set `average_purchase_price` to that same row's `last_purchase_price` and SHALL NOT consult any other owner's row.

#### Scenario: Same-owner fill takes precedence over any donor

- **WHEN** an eligible row has a positive `last_purchase_price` and other owners also hold positive last purchase prices for the same product
- **THEN** the command SHALL set the average purchase price from the row's own last purchase price
- **AND** it SHALL NOT read a donor row for that target row

#### Scenario: Same-owner fill leaves last purchase price untouched

- **WHEN** the command fills an eligible row from that row's own last purchase price
- **THEN** it SHALL write only `average_purchase_price`
- **AND** it SHALL leave `last_purchase_price` at its existing value

### Requirement: A donor owner supplies the cost when the row has none

For an eligible row whose own `last_purchase_price` is null or not positive, the command SHALL select a donor `product_prices` row for the same product, belonging to a different setting, whose `last_purchase_price` is positive. It SHALL set both `average_purchase_price` and `last_purchase_price` on the target row to the donor's `last_purchase_price`.

#### Scenario: Donor fills both cost fields

- **WHEN** an eligible row has no positive last purchase price of its own and a donor row is available
- **THEN** the command SHALL set the target row's `average_purchase_price` to the donor's `last_purchase_price`
- **AND** it SHALL set the target row's `last_purchase_price` to the same donor value
- **AND** it SHALL count the row as filled from a donor

#### Scenario: Row with no donor anywhere is left unresolved

- **WHEN** an eligible row has no positive last purchase price of its own and no other setting holds a positive last purchase price for that product
- **THEN** the command SHALL leave the row unchanged
- **AND** it SHALL count the row as unresolved

#### Scenario: Donor rows are never modified

- **WHEN** the command fills a target row from a donor row
- **THEN** it SHALL NOT write any field on the donor row

### Requirement: Donor selection follows the established owner ladder

When more than one donor row is available, the command SHALL select the donor by owner priority in the order Perdana, then Top IT, then Tiga Nusa, matching the baseline ordering used by `product:seed-average-cost-from-sales-hpp`. Owners outside those three buckets SHALL rank after all three. Ties within the same rank SHALL be broken by ascending `setting_id`.

#### Scenario: Perdana outranks other donors

- **WHEN** donor rows exist for Perdana and for Top IT
- **THEN** the command SHALL select the Perdana donor

#### Scenario: Top IT is selected when Perdana has no positive value

- **WHEN** no Perdana donor holds a positive last purchase price and a Top IT donor does
- **THEN** the command SHALL select the Top IT donor

#### Scenario: Tiga Nusa is selected when Perdana and Top IT have no positive value

- **WHEN** neither Perdana nor Top IT holds a positive last purchase price and Tiga Nusa does
- **THEN** the command SHALL select the Tiga Nusa donor

#### Scenario: Unranked owners are a last resort with a stable tie breaker

- **WHEN** none of Perdana, Top IT, or Tiga Nusa holds a positive last purchase price and two owners outside those buckets do
- **THEN** the command SHALL select the donor with the lower `setting_id`
- **AND** repeated runs over unchanged data SHALL select the same donor

### Requirement: The command never creates price rows

The command SHALL operate only on existing `product_prices` rows. It SHALL NOT create a row for a product and setting combination that has none, even when a donor cost is available for that product.

#### Scenario: Missing price row is not created

- **WHEN** a product has no `product_prices` row for a given setting and another setting holds a positive last purchase price for that product
- **THEN** the command SHALL NOT create a row for the setting that has none

### Requirement: Repeated runs are idempotent

Running the command twice with `--write` over unchanged data SHALL produce no further writes on the second run.

#### Scenario: Second write run reports no fills

- **WHEN** an operator runs the command with `--write` twice in succession with no intervening data change
- **THEN** the second run SHALL report zero rows filled from an own last purchase price and zero rows filled from a donor
- **AND** rows resolved by the first run SHALL be counted as unchanged

