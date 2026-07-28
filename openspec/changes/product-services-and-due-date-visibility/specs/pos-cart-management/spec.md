## ADDED Requirements

### Requirement: POS cart SHALL sell non-stock-managed products as services
The POS cart SHALL allow a non-stock-managed product with an active-setting price row to be added and its quantity increased without requiring positive available stock. It MUST retain existing availability, serial, and stock-allocation behavior for stock-managed products.

#### Scenario: Service is added without inventory
- **WHEN** cashier adds a non-stock-managed product whose available inventory is zero
- **THEN** the cart accepts the line with the requested positive quantity
- **AND** the line is marked as not stock managed for checkout processing

#### Scenario: Service quantity is increased without inventory cap
- **WHEN** cashier increases the quantity of a non-stock-managed product line
- **THEN** the cart accepts the positive quantity without comparing it to available inventory

#### Scenario: Inventory product remains availability-limited
- **WHEN** cashier adds or increases a stock-managed product beyond its available quantity
- **THEN** the cart rejects the mutation with the existing stock-availability protection

### Requirement: POS barcode scanning SHALL resolve non-stock-managed services
The POS barcode scan resolver SHALL resolve an exact barcode for a non-stock-managed product when it has an active-setting price row, without requiring stock in an allowed sales location. Serial-number scanning SHALL remain limited to stock-managed serial-tracked products.

#### Scenario: Service product barcode resolves
- **WHEN** cashier scans the exact barcode of a non-stock-managed product with an active-setting price row
- **THEN** the resolver returns that product for POS add-to-cart

#### Scenario: Inventory barcode remains stock-protected
- **WHEN** cashier scans the exact barcode of a stock-managed product with no stock in allowed sales locations
- **THEN** the resolver does not resolve it as an addable product
