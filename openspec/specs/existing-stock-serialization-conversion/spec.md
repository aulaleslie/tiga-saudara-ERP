# existing-stock-serialization-conversion Specification

## Purpose
TBD - created by archiving change convert-existing-stock-to-serialized. Update Purpose after archive.
## Requirements
### Requirement: Dedicated global conversion authorization
The system SHALL expose existing-stock serialization conversion only to authenticated users granted the dedicated `products.convert_existing_stock_to_serialized` permission, and SHALL enforce that permission on every conversion page, validation endpoint, and submission endpoint regardless of the active setting.

#### Scenario: Authorized user opens conversion
- **WHEN** a user with the dedicated permission opens the conversion flow
- **THEN** the system allows the user to search for eligible products across all settings

#### Scenario: Unauthorized request is rejected
- **WHEN** a user without the dedicated permission requests any conversion endpoint
- **THEN** the system rejects the request without disclosing or changing cross-business inventory

### Requirement: Eligible product selection
The system SHALL allow conversion only for an active, stock-managed product that does not require serial numbers, has positive available stock, has no existing serial-number records, has whole-number stock quantities, and has no active stock-affecting process that would make the conversion unsafe.

#### Scenario: Eligible product is selected
- **WHEN** an authorized user selects a non-serialized stock-managed product satisfying every eligibility rule
- **THEN** the system loads that product's complete stock across all settings and locations

#### Scenario: Ineligible product is rejected
- **WHEN** the selected product is already serialized, has fractional or inconsistent stock, has existing serial records, or participates in an active stock-affecting process
- **THEN** the system blocks conversion and explains the applicable reason in Bahasa Indonesia

### Requirement: Complete owner-level stock pools
The system SHALL aggregate the selected product's stock across all settings into capped owner-level pools separated by normal Non-PPN, normal PPN, broken Non-PPN, and broken PPN quantities. The displayed pool totals SHALL include every corresponding `product_stocks` quantity across the owner's locations.

#### Scenario: Stock is grouped without location input
- **WHEN** the conversion page loads an eligible product
- **THEN** the system displays each owner and its four applicable stock-pool totals without requiring the user to choose a location for each serial

#### Scenario: Broken stock is included
- **WHEN** any owner has positive broken PPN or broken Non-PPN quantity
- **THEN** the system requires serial numbers for those broken units as part of the same conversion

### Requirement: Scanner-driven serial capture
The system SHALL let the user select an owner, tax classification, and stock condition pool and add serial numbers using an Enter-terminated scanner flow. After each accepted scan, the system SHALL clear and refocus the scanner input, show the scanned serial, and update pool and overall progress.

#### Scenario: Scanner adds a serial
- **WHEN** the scanner enters a valid serial and emits Enter for a pool with remaining capacity
- **THEN** the system adds the serial to that pool, updates progress, and prepares the input for the next scan

#### Scenario: User removes an accidental scan
- **WHEN** the user removes a previously scanned serial before final submission
- **THEN** the system returns one unit of capacity to its pool and updates progress

### Requirement: Pool capacity and page-wide uniqueness
The system SHALL prevent a pool from accepting more serials than its available stock and SHALL reject a serial duplicated anywhere in the current conversion input. Conversion validation SHALL also reject any serial number already present anywhere in `product_serial_numbers`, regardless of product, owner, location, or status.

#### Scenario: Selected pool is full
- **WHEN** the user scans another serial after the selected pool reaches its available quantity
- **THEN** the system rejects the scan and instructs the user in Bahasa Indonesia to select an incomplete pool

#### Scenario: Serial is duplicated across pools
- **WHEN** a serial already scanned for one pool is scanned for another pool
- **THEN** the system rejects it and identifies that it has already been scanned

#### Scenario: Serial already exists in the system
- **WHEN** a scanned or submitted serial matches any existing serial-number record
- **THEN** the system rejects it even when the existing record belongs to another product or has SOLD or RETURNED status

### Requirement: Complete single submission
The system SHALL keep final submission unavailable until every owner, tax, and condition pool contains exactly the required number of serials. The system SHALL accept the conversion only as one request containing all serials for all available stock and SHALL NOT support partial conversion by owner, condition, tax classification, or location.

#### Scenario: One pool remains incomplete
- **WHEN** any required pool has fewer scanned serials than its available quantity
- **THEN** the final submission remains unavailable and the page identifies the incomplete pool

#### Scenario: Every pool is complete
- **WHEN** every pool contains exactly its required number of unique serials
- **THEN** the system enables one final confirmation for the entire product

### Requirement: Deterministic original-location allocation
The system SHALL assign submitted serials to the selected owner's original stock locations deterministically, following the normal/broken and PPN/Non-PPN capacities stored in each `product_stocks` row. The allocation SHALL preserve every original per-location bucket total even though locations are not selected during scanning.

#### Scenario: Owner stock spans locations
- **WHEN** a complete owner pool is submitted and its stock spans multiple locations
- **THEN** the system assigns serials in a stable location order until each original location bucket capacity is exactly filled

#### Scenario: Tax identity is assigned
- **WHEN** a serial is allocated from a PPN pool
- **THEN** the system assigns the active default tax ID, while a serial allocated from a Non-PPN pool receives no tax ID

#### Scenario: Broken identity is assigned
- **WHEN** a serial is allocated from a broken stock pool
- **THEN** the serial is created as broken at the allocated original location

### Requirement: Atomic locked conversion
The system SHALL perform the final conversion in one database transaction that authorizes the actor, locks the product and all of its stock rows, recomputes eligibility and pool quantities, validates the complete serial set, creates all serial identities and audit entries, and enables `serial_number_required` last. Any failure SHALL roll back the entire conversion.

#### Scenario: Successful conversion
- **WHEN** the submitted pools match the locked current stock and every serial passes validation
- **THEN** all serials and audit entries are committed and the product becomes serial-number-required in the same transaction

#### Scenario: Stock changes during scanning
- **WHEN** any locked stock bucket no longer matches the quantity used to prepare the submission
- **THEN** the system rejects the whole request in Bahasa Indonesia without creating serials or changing the product flag

#### Scenario: Failure occurs while creating serials
- **WHEN** any serial or audit write fails during final conversion
- **THEN** the system rolls back all writes and leaves the product non-serialized

### Requirement: Repeat submission protection
The system SHALL make completion naturally idempotent under the product lock: once a product has been converted, a repeated or concurrent submission SHALL NOT create additional serial records or repeat the conversion.

#### Scenario: Final request is submitted twice
- **WHEN** a second copy of a successful conversion request reaches the server
- **THEN** the system reports that the product is already serialized and creates no additional records

### Requirement: Generic product update guard
The system SHALL reject false-to-true changes to `serial_number_required` through generic product editing when the product has stock or serial dependencies, reserving stocked conversion for the dedicated workflow.

#### Scenario: Crafted generic update attempts conversion
- **WHEN** a request directly enables serial tracking for a product with existing stock through the normal product update endpoint
- **THEN** the system rejects the transition and directs the user to the dedicated conversion flow

### Requirement: Focused conversion audit
The system SHALL use existing inventory transaction and serial-history conventions to record the conversion actor, time, product, assigned location, owner/tax/condition classification, and a conversion reason without rewriting historical purchase, sale, dispatch, or return records.

#### Scenario: Audit records are committed
- **WHEN** conversion succeeds
- **THEN** each created serial and affected location can be traced to the authorized conversion operation

