# pos-cart-management Specification

## Purpose
This specification defines the requirements for POS cart management, including item additions, quantity updates, and inventory validation.

## Requirements
### Requirement: Indonesian Cart Messages
Exception messages in POS cart operations must be in Bahasa Indonesia.

#### Scenario: Invalid Quantity
- **WHEN** Adding item with quantity less than 1
- **THEN** The system returns 'Kuantitas harus minimal 1.' instead of 'Quantity must be at least 1.'

#### Scenario: Stock Unavailable
- **WHEN** Requested quantity exceeds available stock
- **THEN** The system returns 'Kuantitas yang diminta melebihi stok tersedia untuk lokasi penjualan yang dikonfigurasi.'

### Requirement: Unified Transaction Record
The POS transaction finalization must persist exactly one `PosTransaction` record per checkout, regardless of how many settings provide stock for the line items.

#### Scenario: Cross-Tenant Sale Unification
- **WHEN** A checkout contains items from Setting A and Setting B.
- **THEN** Only one `PosTransaction` record is created, owned by the active session's Setting.
- **THEN** The transaction contains all lines from both settings.

### Requirement: Serial Handoff for Bundle Parent
The system MUST preserve a scanned serial number when a product requires bundle selection, and automatically append that serial number to the resulting cart line after the bundle is selected.

#### Scenario: Scan Serial for Bundle Parent
- **WHEN** user scans a serial number for a product that is a bundle parent
- **THEN** the system prompts for bundle selection while preserving the serial number in temporary state
- **AND** after the user selects a bundle, the serial number is automatically appended to the newly created bundle line in the cart.

#### Scenario: Continue Without Bundle (Normal)
- **WHEN** user chooses to "Continue Normal" for a bundle parent that was scanned by serial
- **THEN** the system adds the product without a bundle and automatically appends the serial number.

### Requirement: POS cart line targeting SHALL include bundle state
When adding or updating a POS cart line for a bundle-parent product, the system SHALL identify the target cart row by product and bundle state. A selected bundle id, a different selected bundle id, and no selected bundle MUST be treated as distinct line identities.

#### Scenario: Same product and same selected bundle merges
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and chooses Bundle A again
- **THEN** the system MUST target the existing Product A with Bundle A row
- **AND** the system MUST increment quantity or append the scanned serial on that row according to the product's serial tracking behavior

#### Scenario: Same product and different selected bundle does not merge
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and chooses Bundle B
- **THEN** the system MUST create or target a Product A with Bundle B row
- **AND** the system MUST NOT increment or append serials on the Product A with Bundle A row

#### Scenario: Same product without bundle does not merge into selected bundle
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and explicitly continues without a bundle
- **THEN** the system MUST create or target a Product A row without bundle metadata
- **AND** the system MUST NOT increment or append serials on the Product A with Bundle A row

#### Scenario: Bundle-aware rows coexist in one cart
- **WHEN** the cashier adds Product A with Bundle A, Product A with Bundle B, and Product A without a bundle
- **THEN** the cart snapshot MUST expose three distinct rows for the same parent product
- **AND** each row MUST retain its own quantity and assigned serial list

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

