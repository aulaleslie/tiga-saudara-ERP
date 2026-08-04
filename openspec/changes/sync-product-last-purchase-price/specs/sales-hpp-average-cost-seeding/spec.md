## ADDED Requirements

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

## MODIFIED Requirements

### Requirement: Seeding respects product-price cost buckets
The command SHALL classify imported sale snapshots and target setting rows using CV Tiga Nusa Computer, CV Top IT Internusa, and Perdana as the named HPP source businesses. A Tiga Nusa or Top IT target setting SHALL receive its own latest imported HPP snapshot when available and otherwise Perdana's latest imported HPP snapshot. Perdana SHALL receive its own latest imported HPP snapshot. Every other target setting SHALL receive Perdana's latest imported HPP snapshot only. The command SHALL NOT use an arbitrary non-special business as an HPP default source.

#### Scenario: Special setting uses its isolated latest HPP
- **WHEN** a product has eligible imported HPP snapshots in the Tiga Nusa or Top IT setting
- **AND** the corresponding special setting is a target
- **THEN** the command SHALL seed that setting's average purchase price from its own setting's latest snapshot
- **AND** it SHALL NOT use Perdana while its own setting has a candidate

#### Scenario: Special setting falls back to Perdana
- **WHEN** a Tiga Nusa or Top IT target has no eligible imported HPP snapshot for a product in its own setting
- **AND** Perdana has an eligible imported HPP snapshot for the product
- **THEN** the command SHALL seed that target's average purchase price from Perdana's latest snapshot

#### Scenario: Other settings use Perdana's HPP value
- **WHEN** Perdana has an eligible imported HPP snapshot for a product
- **THEN** the command SHALL seed every non-Tiga-Nusa, non-Top-IT setting's product-price row from Perdana's latest snapshot
- **AND** it SHALL NOT seed those rows from another non-special setting's snapshot

### Requirement: Explicit write mode seeds average and literal last purchase prices
When run with `--write`, the command SHALL create or update target `product_prices` rows with `average_purchase_price` from the selected imported HPP snapshot and `last_purchase_price` from the independently selected literal purchase candidate. It SHALL preserve selling/tier prices and tax metadata. For a missing row, it SHALL copy available same-product selling and tax metadata using the existing product-price normalization conventions before setting both resolved purchase-price values.

#### Scenario: Write updates an existing product price without changing selling or tax data
- **WHEN** `--write` selects an imported HPP snapshot and a literal purchase candidate for a product with an existing target `product_prices` row
- **THEN** the command SHALL update `average_purchase_price` to the selected `cost_unit_snapshot`
- **AND** it SHALL update `last_purchase_price` to the selected literal purchase unit price
- **AND** it SHALL preserve `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, and `sale_tax_id`

#### Scenario: Write creates a missing target price row with both resolved values
- **WHEN** `--write` selects both an imported HPP snapshot and a literal purchase candidate for a product and a target setting has no `product_prices` row
- **THEN** the command SHALL create the missing row with the selected average and last purchase prices
- **AND** it SHALL preserve available same-product sales/tax metadata according to the product-price normalization conventions

#### Scenario: Products without an HPP candidate remain unchanged
- **WHEN** no eligible imported HPP snapshot exists for a product's target setting or applicable Perdana fallback
- **THEN** the command SHALL NOT create or update that target setting's product-price row
