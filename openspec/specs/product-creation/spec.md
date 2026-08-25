# product-creation Specification

## Purpose

This specification defines the requirements for the product creation workflow, ensuring that all user-provided thresholds and settings are correctly persisted to the database.
## Requirements

### Requirement: Quick-add product creation SHALL initialize prices for every business
When the shared product quick-add flow creates a product from Purchase or Sales, the system SHALL create one `product_prices` row for every business setting that exists at creation time. Every row SHALL receive the same submitted initial purchase and sale pricing values, subject to the product's purchased and sold flags, while normal product edits SHALL remain scoped to the current business.

#### Scenario: Purchase quick-add initializes every business price row
- **WHEN** a user creates a product through Purchase quick-add with valid pricing data
- **THEN** the system SHALL create a price row for that product in every existing business
- **AND** each row SHALL contain the same initial values derived from the submitted quick-add data

#### Scenario: Sales quick-add initializes every business price row
- **WHEN** a user creates a product through Sales quick-add with a valid positive sale price
- **THEN** the system SHALL create a price row for that product in every existing business
- **AND** each row SHALL contain the same base and derived tier sale prices

#### Scenario: Normal product edit remains business-scoped
- **WHEN** a user edits an existing product from the normal product edit page
- **THEN** price changes SHALL apply only to the current business
- **AND** price rows for other businesses SHALL remain unchanged

### Requirement: Low stock alert threshold SHALL be preserved during creation

The system SHALL preserve and persist the "Low Quantity Alert" (stock threshold) value provided by the user during the initial product creation process.

#### Scenario: User provides stock alert during creation
- **WHEN** a user fills out the "Tambah Produk" (Create Product) form
- **AND** enters a value in the "Peringatan Jumlah Stok" field
- **AND** submits the form
- **THEN** the system SHALL store the provided value in the `product_stock_alert` column of the `products` table
- **AND** the value SHALL default to `0` if no value is provided.

### Requirement: Product creation via quick-add MUST clear setting-scoped pricing

When a product is created using a quick-add flow, all persistent pricing metadata for the active setting (last purchase price, sale price, etc.) MUST be cleared from the modal view so that subsequent quick-add operations do not inherit pricing from the previously created item.

#### Scenario: Sale price is cleared after quick-add creation
- **WHEN** a user creates a product with a specific `sale_price` via quick-add
- **THEN** after the product is saved and the modal is ready for the next entry
- **AND** the `sale_price` input SHALL show 0 or be empty
- **AND** the visual RP formatting SHALL NOT show the previous price value.

### Requirement: Purchase-context product quick-add SHALL expose sale pricing after sellable is enabled

When a user opens the shared product quick-add modal from a purchase page, the modal SHALL allow the user to convert the new product from purchase-only to sellable without leaving the flow.

#### Scenario: Purchase quick-add reveals sale pricing when sellable is enabled
- **WHEN** a user opens product quick-add from purchase create or purchase edit
- **AND** the modal starts with `Saya Jual Barang Ini` unchecked
- **AND** the user enables `Saya Jual Barang Ini`
- **THEN** the modal SHALL display the selling-price controls for `Harga Jual`, `Harga Jual Partai Besar`, `Harga Jual Reseller`, and `Pajak Jual`
- **AND** the user SHALL be able to enter sale-pricing data before saving the product

#### Scenario: Purchase quick-add hides inactive sale pricing when sellable is disabled
- **WHEN** a user opens product quick-add from purchase create or purchase edit
- **AND** the user enables `Saya Jual Barang Ini`
- **AND** the user later disables `Saya Jual Barang Ini`
- **THEN** the sale-pricing controls SHALL return to their inactive state
- **AND** the modal SHALL NOT present the product as currently configured for sale

### Requirement: Product price visibility in DataTable SHALL use registered permission

The permission gate controlling price column visibility in the product DataTable SHALL use the centralized permission `products.view_prices` registered in `app/Config/Permissions.php`, replacing the unregistered `view_access_table_product` gate.

#### Scenario: Permission is registered in centralized config
- **WHEN** the permission seeder runs
- **THEN** the permission `products.view_prices` SHALL exist in the `permissions` table
- **AND** it SHALL be assigned to the Admin role automatically

#### Scenario: Old orphan permission is no longer referenced
- **WHEN** the product DataTable checks whether to show price columns
- **THEN** it SHALL use `Gate::allows('products.view_prices')`
- **AND** it SHALL NOT reference `view_access_table_product`

