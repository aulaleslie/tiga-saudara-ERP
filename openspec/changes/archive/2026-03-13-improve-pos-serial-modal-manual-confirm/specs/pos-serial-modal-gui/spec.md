## MODIFIED Requirements

### Requirement: Modal-Based Serial Input
The POS system MUST use an in-app modal for serial number entry and management instead of native browser prompts, and MUST support both keyboard/scanner submit and explicit click submit for manual entry.

#### Scenario: Opening Serial Entry
- **WHEN** the cashier clicks the serial action control on a serial-required cart line
- **THEN** a Bootstrap modal MUST appear on screen
- **AND** the primary input field inside the modal MUST be automatically focused.

#### Scenario: Opening Serial Entry Uses Correct Product Context
- **WHEN** the cashier opens serial modal from a cart line
- **THEN** the modal product label MUST match the product name shown on that selected line.

#### Scenario: Rapid Scanning with Automatic Append
- **WHEN** the cashier scans a serial number (simulated by Enter/Scanner input) into the modal's input field
- **THEN** the system MUST call the `/serials/append` API
- **AND** upon success, the input field MUST be cleared and re-focused
- **AND** the modal MUST remain OPEN to allow the next scan.

#### Scenario: Manual Entry with Explicit Confirm Control
- **WHEN** the cashier types a serial number in the modal input and clicks the explicit confirm control (for example, `Masukkan`)
- **THEN** the system MUST call the `/serials/append` API for the currently active serial line
- **AND** upon success, the input field MUST be cleared and re-focused
- **AND** the modal MUST remain OPEN to allow additional serial input.

#### Scenario: Visual Feedback for Serial Append
- **WHEN** a serial is successfully appended via Enter/Scanner input or explicit confirm click
- **THEN** a transient success message (for example, "Serial [X] ditambahkan") MUST be visible within the modal for a short duration.

#### Scenario: Close Action Does Not Submit Pending Input
- **WHEN** the cashier clicks a modal close action (for example, `Tutup`, header close, or other dismiss control) while the input contains an unsent serial value
- **THEN** the modal MUST close without calling the `/serials/append` API for that unsent value
- **AND** the unsent input value MUST NOT be added to the cart line.

#### Scenario: Remove Serial from Modal Chip
- **WHEN** the cashier clicks remove on a serial chip inside the serial modal
- **THEN** the system MUST call the serial delete endpoint for the currently active line and selected serial
- **AND** on success the cart snapshot and modal serial list MUST refresh to reflect the removal.
