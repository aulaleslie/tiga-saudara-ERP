# price-point-stock-visibility Specification

## Purpose

The Price Points browser provides visibility into product stock availability and unit denomination alongside pricing, matching the POS product search's visual language for distinguishing in-stock, out-of-stock, and non-stock-managed products. Stock quantities are displayed using the same unit conversion format as the product list.

## Requirements

### Requirement: Price Points browser SHALL compute location-scoped available quantity per product
The `/price-points` product browser SHALL compute each product's `available_qty` using the same location-scoping mechanism as POS product search: the sum of `product_stocks.quantity` restricted to location IDs returned by `SalesLocationResolver::resolveLocationIds($settingId)` for the active setting.

#### Scenario: Product with stock in allowed locations shows summed quantity
- **WHEN** a product has `product_stocks` rows totaling 8 units across locations allowed for the active setting
- **THEN** the browser SHALL display `available_qty` of 8 for that product

#### Scenario: Stock in disallowed locations is excluded from the total
- **WHEN** a product has stock in a location not returned by `SalesLocationResolver::resolveLocationIds($settingId)`
- **THEN** that location's quantity SHALL NOT be included in the displayed `available_qty`

#### Scenario: Product with no stock rows shows zero, not null
- **WHEN** a product has no matching `product_stocks` rows in allowed locations
- **THEN** the browser SHALL display `available_qty` of 0

### Requirement: Price Points browser SHALL visually distinguish in-stock, out-of-stock, and non-stock-managed products
Each product card SHALL render one of three stock states, matching POS product search's visual language: normal (in stock), disabled/out-of-stock with a "Stok Kosong" badge, or non-stock-managed with a "Service" badge.

#### Scenario: In-stock, stock-managed product renders normally
- **WHEN** a stock-managed product has `available_qty > 0`
- **THEN** the card SHALL render with normal (non-disabled) styling
- **AND** the card SHALL display the formatted `available_qty` (see unit-denomination requirement below)

#### Scenario: Out-of-stock, stock-managed product renders as disabled with badge
- **WHEN** a stock-managed product has `available_qty <= 0`
- **THEN** the card SHALL render with a visually disabled treatment (reduced opacity/greyscale)
- **AND** the card SHALL display a "Stok Kosong" badge
- **AND** the stock quantity text SHALL be styled to indicate zero/out-of-stock (red/bold)

#### Scenario: Non-stock-managed product renders with Service badge, not disabled
- **WHEN** a product has `stock_managed = false`
- **THEN** the card SHALL NOT render with the disabled/out-of-stock treatment regardless of `available_qty`
- **AND** the card SHALL display a "Service" badge
- **AND** the stock field SHALL display `-` instead of a quantity

### Requirement: Price Points browser SHALL express stock quantity in product units matching the product list's denomination format
When a product has a base unit, the displayed stock quantity SHALL be denominated the same way the Product list page denominates stock quantities (`Modules\Product\DataTables\ProductDataTable::formatQuantityValue`): using the single largest available unit conversion plus a base-unit remainder, falling back to a bare base-unit quantity when no conversions exist.

#### Scenario: Product with unit conversions shows denominated quantity using the largest conversion
- **WHEN** a product has a base unit "Pcs", conversions "Box" (factor 12) and "Karton" (factor 144), and `available_qty` of 150
- **THEN** the displayed stock text SHALL be `"1 Karton 6 Pcs"` (150 ÷ 144 = 1 remainder 6), using only the largest-factor conversion, not an intermediate one

#### Scenario: Product with a single unit conversion shows denominated quantity
- **WHEN** a product has a base unit "Pcs", one conversion "Box" (factor 12), and `available_qty` of 29
- **THEN** the displayed stock text SHALL be `"2 Box 5 Pcs"`

#### Scenario: Product with no unit conversions shows quantity in its base unit only
- **WHEN** a product has a base unit "Pcs" and no conversions, with `available_qty` of 17
- **THEN** the displayed stock text SHALL be `"17 Pcs"`

#### Scenario: Product with no base unit falls back to a bare number
- **WHEN** a product has no base unit configured, with `available_qty` of 17
- **THEN** the displayed stock text SHALL be `"17"` with no unit suffix

#### Scenario: Denominated quantity formatting applies to out-of-stock and in-stock states alike
- **WHEN** a stock-managed product with a base unit and conversions has `available_qty` of 0
- **THEN** the displayed stock text SHALL still use the denominated format (e.g. `"0 Box 0 Pcs"` or the base-unit equivalent), styled per the out-of-stock red/bold treatment

#### Scenario: Non-stock-managed products are unaffected by denomination formatting
- **WHEN** a product has `stock_managed = false`
- **THEN** the stock field SHALL continue to display `-`, not a denominated quantity

### Requirement: Stock computation and display SHALL NOT alter tier-price resolution or product search behavior
Adding stock visibility to Price Points SHALL NOT change which products match a search term, how products are paginated, or how contextual tier pricing is resolved for a selected customer.

#### Scenario: Tier price resolution unchanged
- **WHEN** a customer with tier `WHOLESALER` is selected and a product has a `tier_1_price`
- **THEN** the displayed price SHALL be resolved exactly as before this change (via `resolveContextualPrice`), unaffected by the product's stock state

#### Scenario: Out-of-stock products remain visible in search results
- **WHEN** a search term matches a product with `available_qty = 0`
- **THEN** the product SHALL still appear in the results (not filtered out), rendered in its disabled/out-of-stock state
