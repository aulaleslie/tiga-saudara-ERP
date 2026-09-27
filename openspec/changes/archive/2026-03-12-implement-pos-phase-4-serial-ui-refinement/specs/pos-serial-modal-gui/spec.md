## ADDED Requirements

### Requirement: Modal-Based Serial Input
The POS system must use an in-app modal for serial number entry instead of native browser prompts.

#### Scenario: Opening Serial Entry
- **WHEN** the cashier clicks the "+ Serial" button on a cart line
- **THEN** a Bootstrap modal must appear on screen
- **AND** the primary input field inside the modal must be automatically focused.

#### Scenario: Rapid Scanning with Automatic Append
- **WHEN** the cashier scans a serial number (simulated by Enter/Scanner input) into the modal's input field
- **THEN** the system must call the `/serials/append` API
- **AND** upon success, the input field must be cleared and re-focused
- **AND** the modal must remain OPEN to allow the next scan.

#### Scenario: Visual Feedback for Scanning
- **WHEN** a serial is successfully appended via the modal
- **THEN** a transient success message (e.g., "Serial [X] ditambahkan") should be visible within the modal for a short duration.

### Requirement: Improved Serial Chip Layout
Serial tags (chips) in the cart line must be visually optimized for space and clarity.

#### Scenario: Chip Alignment
- **WHEN** multiple serials are attached to a line item
- **THEN** they should be displayed in a wrapped flex container
- **AND** they must be vertically aligned with the quantity controls.
