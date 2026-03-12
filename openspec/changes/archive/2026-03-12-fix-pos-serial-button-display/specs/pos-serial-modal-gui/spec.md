## MODIFIED Requirements

### Requirement: Modal-Based Serial Input
The POS system MUST use an in-app modal for serial number entry and management instead of native browser prompts.

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

#### Scenario: Visual Feedback for Scanning
- **WHEN** a serial is successfully appended via the modal
- **THEN** a transient success message (for example, "Serial [X] ditambahkan") MUST be visible within the modal for a short duration.

#### Scenario: Remove Serial from Modal Chip
- **WHEN** the cashier clicks remove on a serial chip inside the serial modal
- **THEN** the system MUST call the serial delete endpoint for the currently active line and selected serial
- **AND** on success the cart snapshot and modal serial list MUST refresh to reflect the removal.

## ADDED Requirements

### Requirement: Visible Serial Action Affordance
Serial-required cart lines MUST provide a clearly visible control to open serial management even when Font Awesome assets are unavailable.

#### Scenario: Serial Action Control Remains Visible Without Font Awesome
- **WHEN** the POS sell page is rendered with Bootstrap Icons loaded and Font Awesome not loaded
- **THEN** each serial-required line MUST still display a visible serial action affordance (icon, text, or both) that cashiers can identify and click.
