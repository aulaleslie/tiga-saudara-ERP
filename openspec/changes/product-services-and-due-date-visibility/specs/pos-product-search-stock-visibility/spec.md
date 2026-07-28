## MODIFIED Requirements

### Requirement: POS keyword product search SHALL include stock-managed and service matches
The POS product search endpoint used by `Cari Produk` modal SHALL return active-setting priced products that match search terms within allowed sales-location scope. It SHALL return stock-managed products whose aggregated `available_qty` equals zero and non-stock-managed service products regardless of inventory quantity. Each result SHALL include its computed `available_qty` and `stock_managed` state.

#### Scenario: Keyword search includes both in-stock and out-of-stock inventory products
- **WHEN** cashier searches a keyword that matches two stock-managed products in allowed sales locations, one with `available_qty = 5` and one with `available_qty = 0`
- **THEN** both products SHALL be returned in `results`
- **AND** each result SHALL include its computed `available_qty` and `stock_managed` state

#### Scenario: Keyword search includes a non-stock-managed service
- **WHEN** cashier searches a keyword that matches a non-stock-managed product with an active-setting price row and `available_qty = 0`
- **THEN** the service product SHALL be returned in `results`
- **AND** its result SHALL identify it as not stock managed

#### Scenario: Products outside allowed sales-location scope remain excluded
- **WHEN** cashier searches a keyword that matches a stock-managed product stocked only in disallowed locations
- **THEN** the product SHALL NOT be returned in `results` even if the catalog record exists

### Requirement: POS search modal SHALL present services as selectable and out-of-stock inventory as non-selectable
The `Cari Produk` modal SHALL render stock-managed products with `available_qty = 0` as disabled result cards with explicit out-of-stock status. It SHALL render non-stock-managed service products as selectable result cards even when their `available_qty` is zero.

#### Scenario: Out-of-stock inventory card is visible with status watermark
- **WHEN** search results include a stock-managed product with `available_qty = 0`
- **THEN** the product card SHALL be rendered in the grid
- **AND** the card SHALL display `Stok Kosong` as visible out-of-stock status indicator
- **AND** the card SHALL not add the product to the cart

#### Scenario: Service card is selectable without inventory
- **WHEN** search results include a non-stock-managed service product with `available_qty = 0`
- **THEN** its card SHALL not be disabled or labelled `Stok Kosong`
- **AND** selecting it SHALL invoke add-to-cart

### Requirement: Exact barcode matching SHALL auto-select services and in-stock inventory only
Search-response auto-select behavior for exact product or conversion barcodes SHALL apply to non-stock-managed services and stock-managed products with `available_qty > 0`. It SHALL not auto-select an out-of-stock stock-managed product.

#### Scenario: Exact service barcode is auto-selected
- **WHEN** the query exactly matches the barcode of a non-stock-managed service product with an active-setting price row
- **THEN** `meta.auto_select_product_id` SHALL identify that service product

#### Scenario: Exact barcode match with zero stock is not auto-selected
- **WHEN** the query exactly matches a stock-managed product barcode but that product has `available_qty = 0`
- **THEN** `meta.auto_select_product_id` SHALL be `null`
- **AND** the result SHALL remain visible in the modal as out-of-stock
