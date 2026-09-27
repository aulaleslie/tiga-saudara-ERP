## MODIFIED Requirements

### Requirement: Price Points browser SHALL express stock quantity in product units matching the product list's denomination format
When a product has a base unit, the displayed stock quantity SHALL be denominated the same way the Product list page denominates stock quantities (`Modules\Product\DataTables\ProductDataTable::formatQuantityValue`): using the single largest available unit conversion plus a base-unit remainder, falling back to a bare base-unit quantity when no conversions exist. This denominated value SHALL only be computed into the data supplied to the view, and the "Stok" element SHALL only be rendered, when the acting user holds `inventory.view_remaining_stock`; for users without the permission, the field SHALL be omitted and no "Stok" element SHALL be rendered.

#### Scenario: Product with unit conversions shows denominated quantity using the largest conversion
- **WHEN** a permitted user views a product with a base unit "Pcs", conversions "Box" (factor 12) and "Karton" (factor 144), and `available_qty` of 150
- **THEN** the displayed stock text SHALL be `"1 Karton 6 Pcs"` (150 ÷ 144 = 1 remainder 6), using only the largest-factor conversion, not an intermediate one

#### Scenario: Product with a single unit conversion shows denominated quantity
- **WHEN** a permitted user views a product with a base unit "Pcs", one conversion "Box" (factor 12), and `available_qty` of 29
- **THEN** the displayed stock text SHALL be `"2 Box 5 Pcs"`

#### Scenario: Product with no unit conversions shows quantity in its base unit only
- **WHEN** a permitted user views a product with a base unit "Pcs" and no conversions, with `available_qty` of 17
- **THEN** the displayed stock text SHALL be `"17 Pcs"`

#### Scenario: Product with no base unit falls back to a bare number
- **WHEN** a permitted user views a product with no base unit configured, with `available_qty` of 17
- **THEN** the displayed stock text SHALL be `"17"` with no unit suffix

#### Scenario: Denominated quantity formatting applies to out-of-stock and in-stock states alike
- **WHEN** a permitted user views a stock-managed product with a base unit and conversions and `available_qty` of 0
- **THEN** the displayed stock text SHALL still use the denominated format (e.g. `"0 Box 0 Pcs"` or the base-unit equivalent), styled per the out-of-stock red/bold treatment

#### Scenario: Non-stock-managed products are unaffected by denomination formatting
- **WHEN** a permitted user views a product with `stock_managed = false`
- **THEN** the stock field SHALL continue to display `-`, not a denominated quantity

#### Scenario: Stok element and quantity data omitted for unpermitted user
- **WHEN** a user lacking `inventory.view_remaining_stock` views the Price Points browser
- **THEN** the product data supplied to the view SHALL NOT include `available_qty` or a formatted/denominated quantity string
- **AND** the card SHALL NOT render a "Stok" label, value, or placeholder in that position, regardless of the product's stock-managed status
