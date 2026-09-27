## ADDED Requirements

### Requirement: Packed line merge and re-pack on repeated scans

The system SHALL compute a packed line's merge key from product, tax, and customer tier (+ bundle if applicable), excluding the conversion ID and blended unit price. Repeated additions of the same product+tier SHALL coalesce into a single line whose total quantity is re-packed from scratch. The system SHALL NOT price an incremental quantity in isolation and add it to an existing packed line. A base/product-search add and a box-scan add of the same product+tier coalesce into one PACKED line and re-pack the combined base quantity.

Note: This relies on the invariant of one box-conversion per product. If that ever changes, the merge key must be revisited.

#### Scenario: Scanning the same box twice coalesces into one re-packed line
- **WHEN** a cashier scans the same box barcode twice (factor 5)
- **THEN** a single line with quantity 10 exists
- **AND** the line total is computed by re-packing 10 base units, not by adding two independent 5-unit prices

#### Scenario: Product-search add and box-scan add coalesce into one re-packed line
- **WHEN** a cashier adds a product via search (qty 5) and then scans the same product's box barcode (factor 5)
- **THEN** a single line with quantity 10 exists
- **AND** the line total is computed by re-packing 10 base units, dropping the conversion-vs-search distinction

#### Scenario: Merge key ignores the blended price
- **WHEN** two packed additions of the same product+tier produce different blended unit prices at their intermediate quantities
- **THEN** they still share a merge key and coalesce into one line

### Requirement: Snapshot merge-key parity for packed lines

When persisting or reloading a POS transaction, the system SHALL compute the packed-line merge key using the same fields as the cart service (product, tax, tier; excluding conversion and blended price) so that reloaded lines coalesce consistently with the cart.

#### Scenario: Reloaded packed line coalesces consistently
- **WHEN** a persisted transaction with a packed line is reloaded into the cart
- **THEN** the reloaded line's merge key matches the cart service's computed merge key for the same product+tier
