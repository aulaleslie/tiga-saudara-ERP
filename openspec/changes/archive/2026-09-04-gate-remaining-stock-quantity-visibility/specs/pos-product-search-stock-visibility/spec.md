## MODIFIED Requirements

### Requirement: POS keyword product search SHALL include out-of-stock matches
The POS product search endpoint used by `Cari Produk` modal SHALL return stock-managed products that match search terms within allowed sales-location scope, including products whose aggregated `available_qty` equals zero. Each result's numeric `available_qty` field SHALL be included only when the acting user holds `inventory.view_remaining_stock`; when the user lacks the permission, the field SHALL be omitted from the result entirely while the result itself remains present.

#### Scenario: Keyword search includes both in-stock and out-of-stock matches
- **WHEN** cashier searches a keyword that matches two products in allowed sales locations, one with `available_qty = 5` and one with `available_qty = 0`
- **THEN** both products SHALL be returned in `results`

#### Scenario: Products outside allowed sales-location scope remain excluded
- **WHEN** cashier searches a keyword that matches a product stocked only in disallowed locations
- **THEN** the product SHALL NOT be returned in `results` even if the catalog record exists

#### Scenario: Result includes available_qty for a permitted user
- **WHEN** a user holding `inventory.view_remaining_stock` searches and matches a stock-managed product
- **THEN** the result SHALL include its computed `available_qty`

#### Scenario: Result omits available_qty for an unpermitted user
- **WHEN** a user lacking `inventory.view_remaining_stock` searches and matches a stock-managed product
- **THEN** the result SHALL NOT include an `available_qty` field
- **AND** the result SHALL still be present in `results` with its other fields (name, price, stock state) intact
