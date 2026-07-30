# product-list-price-columns Specification

## Purpose
TBD - created by archiving change product-list-complete-price-columns. Update Purpose after archive.
## Requirements
### Requirement: Product DataTable SHALL display all active-setting price fields

The product list DataTable SHALL display all price columns from the `product_prices` row matching the active `setting_id`. Specifically: last purchase price, average purchase price, sale price, tier 1 price (Partai Besar), and tier 2 price (Reseller).

#### Scenario: All five price columns are visible for authorized users
- **WHEN** a user with `products.view_prices` permission views the product list
- **THEN** the DataTable SHALL display columns: Beli Akhir, Beli Rata², Jual, Jual Partai, Jual Reseller
- **AND** each column SHALL show the formatted currency value from the `product_prices` row for the active `setting_id`

#### Scenario: Null price values display as dash
- **WHEN** a price column value is `null` for the active setting's `product_prices` row
- **THEN** the column SHALL display `-`
- **AND** the column SHALL NOT display a value from a different `setting_id`

#### Scenario: Missing product_prices row displays dashes for all price columns
- **WHEN** no `product_prices` row exists for the product and active `setting_id`
- **THEN** all five price columns SHALL display `-`

#### Scenario: Price columns hidden for unauthorized users
- **WHEN** a user without `products.view_prices` permission views the product list
- **THEN** the DataTable SHALL NOT display any of the five price columns

### Requirement: Product DataTable SHALL freeze product identity columns on horizontal scroll

The first two columns (product image and product code) SHALL remain visible when the user scrolls horizontally through the DataTable.

#### Scenario: Image and code columns stay visible during horizontal scroll
- **WHEN** the product DataTable has more columns than fit the viewport width
- **AND** the user scrolls horizontally to reveal price or stock columns
- **THEN** the product image column and product code column SHALL remain fixed on the left
- **AND** they SHALL NOT scroll out of view

### Requirement: Product DataTable SHALL freeze the header row on vertical scroll

The table header row SHALL remain visible when the user scrolls vertically through product rows.

#### Scenario: Header stays visible during vertical scroll
- **WHEN** the product DataTable contains more rows than fit in the scroll area
- **AND** the user scrolls down through the rows
- **THEN** the column header row SHALL remain fixed at the top of the table scroll area

### Requirement: Product DataTable price column headers SHALL use abbreviated labels

All price column headers SHALL use abbreviated labels for compactness.

#### Scenario: Abbreviated headers are displayed
- **WHEN** the product DataTable is rendered with price columns visible
- **THEN** the price column headers SHALL be: "Beli Akhir", "Beli Rata²", "Jual", "Jual Partai", "Jual Reseller"

