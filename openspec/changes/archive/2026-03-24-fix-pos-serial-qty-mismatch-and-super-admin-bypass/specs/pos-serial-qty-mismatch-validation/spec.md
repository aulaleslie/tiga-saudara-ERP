# pos-serial-qty-mismatch-validation Specification

## ADDED Requirements

### Requirement: Serial Assignments MUST Be Preserved When Cart Line Quantity Changes
The POS cart system SHALL preserve assigned serial numbers when the user modifies the quantity of a serial-required line, allowing the user to resolve mismatches manually rather than forcing re-entry.

#### Scenario: Reducing quantity preserves assigned serials
- **WHEN** a user reduces the quantity of a serial-required cart line from 2 to 1 with 2 assigned serials
- **THEN** the assigned serials MUST remain in the cart snapshot and NOT be cleared
- **AND** the cart state MUST contain `assigned_serials: [SN-001, SN-002]` with `qty: 1`

#### Scenario: Increasing quantity preserves assigned serials
- **WHEN** a user increases the quantity of a serial-required cart line from 1 to 3 with 1 assigned serial
- **THEN** the assigned serial MUST be preserved
- **AND** the cart state MUST contain `assigned_serials: [SN-001]` with `qty: 3`

### Requirement: Save and Checkout Operations MUST Block When Serial Count Does Not Match Quantity
The POS checkout flow SHALL validate that assigned serial count equals the sale quantity for all serial-required items before allowing save or checkout operations.

#### Scenario: Save is blocked when serials exceed quantity
- **WHEN** a cart line has serial_number_required=true with qty=1 and assigned_serials=[SN-001, SN-002]
- **THEN** the Save Draft button MUST be disabled
- **AND** the Checkout button MUST be disabled
- **AND** an error message MUST be displayed indicating the mismatch

#### Scenario: Save is blocked when quantity exceeds assigned serials
- **WHEN** a cart line has serial_number_required=true with qty=3 and assigned_serials=[SN-001]
- **THEN** the Save Draft button MUST be disabled
- **AND** the Checkout button MUST be disabled

#### Scenario: Save is enabled when counts match
- **WHEN** a cart line has serial_number_required=true with qty=2 and assigned_serials=[SN-001, SN-002]
- **THEN** the Save Draft button MUST be enabled
- **AND** the Checkout button MUST be enabled (assuming other guards pass)

### Requirement: User MUST Be Able To Resolve Serial-Quantity Mismatch Through Manual Adjustment
The POS UI SHALL provide clear affordances for the user to resolve mismatches by either removing excess serials or adjusting the quantity.

#### Scenario: User removes serial to match reduced quantity
- **WHEN** a line has qty=1 and assigned_serials=[SN-001, SN-002] (mismatch displayed)
- **THEN** the user can click the delete icon on SN-002 to remove it
- **AND** upon removal, assigned_serials becomes [SN-001]
- **AND** the save and checkout buttons MUST become enabled

#### Scenario: User increases quantity to match assigned serials
- **WHEN** a line has qty=1 and assigned_serials=[SN-001, SN-002] (mismatch displayed)
- **THEN** the user can click the [+] button to increase qty to 2
- **AND** the save and checkout buttons MUST become enabled

