## ADDED Requirements

### Requirement: Serial Handoff for Bundle Parent
The system MUST preserve a scanned serial number when a product requires bundle selection, and automatically append that serial number to the resulting cart line after the bundle is selected.

#### Scenario: Scan Serial for Bundle Parent
- **WHEN** user scans a serial number for a product that is a bundle parent
- **THEN** the system prompts for bundle selection while preserving the serial number in temporary state
- **AND** after the user selects a bundle, the serial number is automatically appended to the newly created bundle line in the cart.

#### Scenario: Continue Without Bundle (Normal)
- **WHEN** user chooses to "Continue Normal" for a bundle parent that was scanned by serial
- **THEN** the system adds the product without a bundle and automatically appends the serial number.
