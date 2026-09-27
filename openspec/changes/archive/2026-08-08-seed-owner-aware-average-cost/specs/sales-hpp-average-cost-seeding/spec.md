## MODIFIED Requirements

### Requirement: Seeding respects product-price cost buckets
For every stock-managed product, the command SHALL resolve the latest eligible imported HPP snapshot separately for the three sales-import source owners: Perdana, Top IT, and Tiga Nusa. It SHALL evaluate these owner sources in explicit priority order—Perdana, then Top IT, then Tiga Nusa—to select the first available shared baseline. It SHALL use the baseline only to fill target setting rows whose `average_purchase_price` is null or non-positive. After baseline filling, it SHALL apply Tiga Nusa's latest eligible HPP only to Tiga Nusa target rows and Top IT's latest eligible HPP only to Top IT target rows. It SHALL preserve a positive average purchase price for every other target setting.

#### Scenario: Perdana baseline fills every uninitialized business
- **WHEN** a product has an eligible Perdana snapshot and target businesses have missing, null, or zero average purchase prices
- **THEN** the command SHALL use the latest Perdana snapshot as the shared baseline
- **AND** it SHALL fill each uninitialized target business with that baseline
- **AND** it SHALL NOT overwrite another target business's positive average purchase price

#### Scenario: Top IT becomes baseline when Perdana is unavailable
- **WHEN** a product has no eligible Perdana snapshot and has an eligible Top IT snapshot
- **THEN** the command SHALL use the latest Top IT snapshot as the shared baseline for uninitialized target businesses

#### Scenario: Tiga Nusa becomes baseline when Perdana and Top IT are unavailable
- **WHEN** a product has no eligible Perdana or Top IT snapshot and has an eligible Tiga Nusa snapshot
- **THEN** the command SHALL use the latest Tiga Nusa snapshot as the shared baseline for uninitialized target businesses

#### Scenario: Special company retains its own latest HPP
- **WHEN** a product has a shared baseline and Top IT or Tiga Nusa has its own eligible latest HPP snapshot
- **THEN** the command SHALL set that special company's average purchase price to its own latest snapshot cost
- **AND** it SHALL NOT apply that special-company cost to another business with a positive average purchase price

#### Scenario: Positive non-special average remains isolated
- **WHEN** a non-special target business already has a positive average purchase price
- **THEN** the command SHALL preserve that value during baseline filling

### Requirement: Explicit write mode seeds only average purchase price
When run with `--write`, the command SHALL create or update target `product_prices` rows as required to seed average purchase price from the selected HPP baseline or special-company overlay. It SHALL preserve `last_purchase_price`, selling/tier prices, and tax metadata on existing rows. For a missing row with an eligible HPP baseline, it SHALL create the row without requiring a literal-purchase candidate, copying available same-product selling/tier/tax metadata using existing product-price normalization conventions; if no template exists, it SHALL use safe zero/null non-cost defaults.

#### Scenario: Write fills a zero existing average without changing other price data
- **WHEN** `--write` selects an HPP baseline for an existing target row with null or zero average purchase price
- **THEN** the command SHALL update only `average_purchase_price`
- **AND** it SHALL preserve `last_purchase_price`, `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, and `sale_tax_id`

#### Scenario: Write creates a missing target price row from HPP alone
- **WHEN** `--write` selects an HPP baseline for a target setting that has no `product_prices` row
- **THEN** the command SHALL create the missing row with the selected average purchase price
- **AND** it SHALL NOT require an eligible literal purchase candidate

#### Scenario: Write preserves a positive non-source average
- **WHEN** a target setting already has a positive average purchase price and is not receiving its own special-company overlay
- **THEN** the command SHALL NOT update that target row's average purchase price

### Requirement: Products without an eligible HPP baseline remain unresolved
The command SHALL leave a stock-managed product unchanged when it has no positive `HPP_SNAPSHOT_IMPORT` sale-detail snapshot in Perdana, Top IT, or Tiga Nusa. It SHALL report the product or target rows as unresolved/skipped in dry-run and write mode and SHALL NOT create a product-price row or invent an average purchase price for that product.

#### Scenario: No eligible source does not create a price row
- **WHEN** a stock-managed product has no eligible imported HPP snapshot in any prioritized bucket
- **THEN** the command SHALL NOT create or update its `product_prices` rows
- **AND** it SHALL report the product as unresolved or skipped

## REMOVED Requirements

### Requirement: Seeding reconciles last purchase price from literal purchase history

**Reason:** Reconciliation of `last_purchase_price` is owned exclusively by the purchase-import workflow. Command-level purchase reconciliation was removed to eliminate duplicate responsibility, reduce code complexity, and clarify that this command is average-cost-focused only.

**Migration:** Purchase-import continues to populate `last_purchase_price` on all `product_prices` rows as part of its purchase receipt workflow. No manual data remediation is required.

### Requirement: Perdana supplies missing default last purchase prices

**Reason:** This behavior conflated average-cost seeding with purchase-price reconciliation. Purchase-import owns `last_purchase_price` exclusively; this command operates on `average_purchase_price` only.

**Migration:** Use purchase-import to establish `last_purchase_price` across all target businesses. This command preserves any existing `last_purchase_price` values unchanged.
