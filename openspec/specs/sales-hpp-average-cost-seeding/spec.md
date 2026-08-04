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

### Requirement: Seeding reconciles last purchase price from literal purchase history
When run with `--write`, the command SHALL resolve `last_purchase_price` independently from literal purchase details for the same stock-managed product. An eligible literal purchase detail SHALL belong to a non-archived parent purchase with status `RECEIVED` or `RECEIVED PARTIALLY` and have a positive quantity. The selected candidate's unit price SHALL equal `(sub_total + product_discount_amount) / quantity`, retaining tax and excluding the discount effect.

#### Scenario: Latest eligible received purchase sets last purchase price
- **WHEN** an existing target `product_prices` row has eligible literal purchase details for the same product in its own setting
- **THEN** the command SHALL set `last_purchase_price` from the latest eligible candidate's tax-inclusive, discount-excluded unit price
- **AND** it SHALL select recency by approved receiving timestamp when available, then purchase date, then stable database identifiers

#### Scenario: Ineligible purchase activity does not set last purchase price
- **WHEN** a purchase detail belongs to an archived, non-received, or non-received-partially purchase, or has non-positive quantity
- **THEN** the command SHALL NOT select it as a last-purchase-price candidate

#### Scenario: Tax is retained and discount is excluded in the selected price
- **WHEN** the selected literal purchase detail has a tax-inclusive subtotal and a non-zero line discount
- **THEN** the command SHALL calculate last purchase price as the line subtotal plus the line discount divided by quantity
- **AND** it SHALL NOT subtract the line tax or leave the discount applied

### Requirement: Perdana supplies missing default last purchase prices
The command SHALL use Perdana's latest eligible literal purchase for a product when a target setting has no eligible literal purchase of its own. It SHALL not use another unrelated non-special setting as a default source.

#### Scenario: Own purchase takes precedence over Perdana
- **WHEN** a target setting and Perdana both have eligible literal purchases for the same product
- **THEN** the command SHALL set the target row's `last_purchase_price` from the target setting's latest eligible literal purchase

#### Scenario: Perdana supplies a target with no own purchase
- **WHEN** a target setting has no eligible literal purchase for a product
- **AND** Perdana has an eligible literal purchase for that product
- **THEN** the command SHALL set the target row's `last_purchase_price` from Perdana's latest eligible literal purchase

#### Scenario: Missing literal purchase source does not produce a zero price
- **WHEN** neither a target setting nor Perdana has an eligible literal purchase for a product
- **THEN** the command SHALL preserve an existing target row's `last_purchase_price`
- **AND** it SHALL NOT create a missing target row solely from an HPP snapshot

