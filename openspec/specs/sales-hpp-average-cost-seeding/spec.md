# sales-hpp-average-cost-seeding Specification

## Purpose
TBD - created by archiving change seed-average-cost-from-sales-hpp. Update Purpose after archive.
## Requirements
### Requirement: Operator can preview average-cost seeding from imported sales HPP
The system SHALL provide the `product:seed-average-cost-from-sales-hpp` Artisan command in dry-run mode by default. It SHALL derive candidate current average costs only from stock-managed products' positive `HPP_SNAPSHOT_IMPORT` sale-detail snapshots and SHALL report considered products, skipped products, target buckets, selected source sale dates, product-price rows to create, rows to update, and rows already unchanged without writing `product_prices`.

#### Scenario: Dry run does not mutate product prices
- **WHEN** an operator runs `product:seed-average-cost-from-sales-hpp` without `--write`
- **THEN** the command SHALL calculate and report prospective average-purchase-price changes
- **AND** it SHALL NOT create or update any `product_prices` row

#### Scenario: Non-authoritative or unusable snapshots are ignored
- **WHEN** a sale detail has a snapshot source other than `HPP_SNAPSHOT_IMPORT`, has a non-positive snapshot cost, or belongs to a non-stock-managed product
- **THEN** the command SHALL NOT use that detail to seed a product average purchase price
- **AND** it SHALL report the product or bucket as skipped when no eligible candidate remains

### Requirement: Seeding chooses the latest imported HPP snapshot deterministically
For each product and cost bucket, the command SHALL select the eligible imported HPP snapshot whose parent sale has the latest sale date. When candidates have the same sale date, it SHALL break ties by descending sale ID and then descending sale-detail ID.

#### Scenario: Latest sale date wins regardless of import processing order
- **WHEN** two successful HPP-import snapshots for the same product and bucket have different parent sale dates
- **THEN** the command SHALL seed the average purchase price from the snapshot belonging to the later sale date
- **AND** it SHALL NOT select a later-created or later-imported snapshot solely because it was processed later

#### Scenario: Same-date candidates use a stable tie breaker
- **WHEN** eligible imported HPP snapshots for one product and bucket have the same parent sale date
- **THEN** the command SHALL select the candidate with the greater sale ID
- **AND** when the sale IDs are equal, it SHALL select the candidate with the greater sale-detail ID

### Requirement: Seeding respects product-price cost buckets
The command SHALL classify candidate sale snapshots and target setting rows into Tiga Nusa, Top IT, and REST/global buckets using the established product-purchase-price-normalization bucket rules. A target setting SHALL receive its own bucket's latest imported HPP when available; a special setting with no own-bucket candidate SHALL receive REST/global when available; every non-special setting SHALL receive REST/global only.

#### Scenario: Special setting uses its isolated latest HPP
- **WHEN** a product has eligible imported HPP snapshots in the Tiga Nusa or Top IT bucket
- **AND** the corresponding special setting is a target
- **THEN** the command SHALL seed that setting's average purchase price from its own bucket's latest snapshot
- **AND** it SHALL NOT use REST/global while its own bucket has a candidate

#### Scenario: Special setting falls back to REST/global
- **WHEN** a special setting has no eligible imported HPP snapshot for a product in its own bucket
- **AND** the product has an eligible REST/global imported HPP snapshot
- **THEN** the command SHALL seed that special setting's average purchase price from the REST/global latest snapshot

#### Scenario: Non-special settings share the REST/global value
- **WHEN** a product has an eligible REST/global imported HPP snapshot
- **THEN** the command SHALL seed every non-special setting's product-price row from that REST/global latest snapshot
- **AND** it SHALL NOT seed those rows from a special-company snapshot

### Requirement: Explicit write mode seeds only average purchase price
When run with `--write`, the command SHALL create or update the target `product_prices` rows and set only `average_purchase_price` to the selected imported HPP snapshot value. It SHALL preserve existing `last_purchase_price`, selling/tier prices, and tax metadata. For a missing row, it SHALL copy available same-product selling and tax metadata using the existing product-price normalization conventions before setting the average price.

#### Scenario: Write updates an existing product price without changing other price data
- **WHEN** `--write` selects an imported HPP snapshot for a product with an existing target `product_prices` row
- **THEN** the command SHALL update that row's `average_purchase_price` to the selected `cost_unit_snapshot`
- **AND** it SHALL preserve `last_purchase_price`, `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, and `sale_tax_id`

#### Scenario: Write creates a missing target price row
- **WHEN** `--write` selects an imported HPP snapshot for a product and a target setting has no `product_prices` row
- **THEN** the command SHALL create the missing row with the selected `average_purchase_price`
- **AND** it SHALL preserve available same-product sales/tax metadata according to the product-price normalization conventions

#### Scenario: Products without a bucket candidate remain unchanged
- **WHEN** no eligible imported HPP snapshot exists for a product's target bucket or applicable REST/global fallback
- **THEN** the command SHALL NOT create or update that target setting's product-price row

