## ADDED Requirements

### Requirement: POS keyword product search SHALL include out-of-stock matches
The POS product search endpoint used by `Cari Produk` modal SHALL return stock-managed products that match search terms within allowed sales-location scope, including products whose aggregated `available_qty` equals zero.

#### Scenario: Keyword search includes both in-stock and out-of-stock matches
- **WHEN** cashier searches a keyword that matches two products in allowed sales locations, one with `available_qty = 5` and one with `available_qty = 0`
- **THEN** both products SHALL be returned in `results`
- **AND** each result SHALL include its computed `available_qty`

#### Scenario: Products outside allowed sales-location scope remain excluded
- **WHEN** cashier searches a keyword that matches a product stocked only in disallowed locations
- **THEN** the product SHALL NOT be returned in `results` even if the catalog record exists

### Requirement: POS search modal SHALL present out-of-stock results as visible but non-selectable
The `Cari Produk` modal SHALL render out-of-stock products as disabled result cards that cannot be selected for add-to-cart and SHALL include explicit out-of-stock status text or watermark (`Stok Kosong`).

#### Scenario: Out-of-stock card is visible with status watermark
- **WHEN** search results include a product with `available_qty = 0`
- **THEN** the product card SHALL be rendered in the grid
- **AND** the card SHALL display `Stok Kosong` as visible out-of-stock status indicator

#### Scenario: Out-of-stock card cannot add product to cart
- **WHEN** cashier attempts to click or press Enter on an out-of-stock result card
- **THEN** the system SHALL NOT call add-to-cart for that product
- **AND** the cart snapshot SHALL remain unchanged

### Requirement: Barcode/conversion auto-select SHALL ignore out-of-stock exact matches
Search-response auto-select behavior for exact barcode or conversion-barcode matches SHALL only apply to results with `available_qty > 0`.

#### Scenario: Exact barcode match with zero stock is not auto-selected
- **WHEN** the query exactly matches a product barcode but that product has `available_qty = 0`
- **THEN** `meta.auto_select_product_id` SHALL be `null`
- **AND** the result SHALL remain visible in the modal as out-of-stock

#### Scenario: Exact conversion barcode match with zero stock is not auto-selected
- **WHEN** the query exactly matches a conversion barcode but the product has `available_qty = 0`
- **THEN** `meta.auto_select_product_id` SHALL be `null`
- **AND** the result SHALL remain visible in the modal as out-of-stock
