## ADDED Requirements

### Requirement: Low stock alert threshold SHALL be preserved during creation
The system SHALL preserve and persist the "Low Quantity Alert" (stock threshold) value provided by the user during the initial product creation process.

#### Scenario: User provides stock alert during creation
- **WHEN** a user fills out the "Tambah Produk" (Create Product) form
- **AND** enters a value in the "Peringatan Jumlah Stok" field
- **AND** submits the form
- **THEN** the system SHALL store the provided value in the `product_stock_alert` column of the `products` table
- **AND** the value SHALL default to `0` if no value is provided.
