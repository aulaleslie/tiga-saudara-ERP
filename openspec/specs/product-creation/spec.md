# product-creation Specification

## Purpose

This specification defines the requirements for the product creation workflow, ensuring that all user-provided thresholds and settings are correctly persisted to the database.

## Requirements

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

