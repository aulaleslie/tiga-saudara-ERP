## MODIFIED Requirements

### Requirement: Warehouse stock valuation report access
The system SHALL provide a `Nilai stok gudang` report page under Reports > Produk for authorized users, scoped to the businesses selected through the shared report business scope (default: the user's active setting). The route SHALL enforce `inventoryValuationReports.access`.

#### Scenario: Authorized user opens report
- **WHEN** a user with `inventoryValuationReports.access` opens the warehouse stock valuation report route
- **THEN** the system SHALL render the `Nilai stok gudang` report page
- **AND** the page title SHALL include `(dalam IDR)`.

#### Scenario: Unauthorized user denied
- **WHEN** a user without `inventoryValuationReports.access` opens the warehouse stock valuation report route
- **THEN** the system SHALL deny access.

#### Scenario: Reports landing card is actionable
- **WHEN** a user with `inventoryValuationReports.access` views the Reports > Produk landing cards
- **THEN** the `Nilai stok gudang` card SHALL link to the warehouse stock valuation report route instead of rendering as a placeholder.

#### Scenario: Data is scoped to active setting
- **WHEN** the report renders or exports
- **THEN** warehouses, prices, and stock movements outside the selected businesses SHALL be excluded
- **AND** products and categories SHALL NOT be filtered by their owning business.

## ADDED Requirements

### Requirement: Warehouse stock valuation multi-business matrix
With several businesses selected, the report SHALL show one row per product with a Total group (quantity, value) and one group per business (quantity, average cost, value) using that business's average cost. Each business group SHALL expand to its selected warehouses. The grand total SHALL equal the sum of the business values. Exports SHALL match the screen.

#### Scenario: Per-business average cost
- **WHEN** a product's average cost is 100 in A and 120 in B, with 2 units in each
- **THEN** A's value SHALL be 200, B's 240, and the Total value 440
