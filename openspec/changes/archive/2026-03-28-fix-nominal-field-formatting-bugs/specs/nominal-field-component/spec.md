## MODIFIED Requirements

### Requirement: Nominal field component initialization
The system SHALL initialize nominal fields with consistent formatting behavior across all value states (empty, zero, and populated) on both create and edit pages.

**Previous behavior**: Empty fields showed blank, populated fields on edit sometimes corrupted on blur.

**New behavior**: All fields show formatted currency immediately, format correctly on blur regardless of initial value.

#### Scenario: Create page with empty value
- **WHEN** product create page loads with no initial price value
- **THEN** the field displays "0,00" (formatted)
- **AND** maskMoney is active and configured

#### Scenario: Edit page with populated value
- **WHEN** product edit page loads with price = 50000
- **THEN** the field displays "Rp 50.000,00" (formatted) on load
- **AND** on focus, shows "50000" (raw)
- **AND** on blur, shows "Rp 50.000,00" again (correctly re-formatted)
- **AND** the value is NOT corrupted

#### Scenario: User input with blur formatting
- **WHEN** user focuses a field and types "1500"
- **AND** user blurs the field
- **THEN** the field displays "Rp 1.500,00" (formatted)
- **AND** the hidden input stores "1500" (raw)

### Requirement: maskMoney lifecycle management
The system SHALL manage maskMoney activation, destruction, and reapplication consistently without state loss or corruption.

**Previous behavior**: Some initialization paths called toFixed(2) before masking, causing decimal separator confusion. Empty fields skipped initialization entirely.

**New behavior**: Always initialize with raw numbers, let maskMoney handle all formatting, never pre-format strings before masking.

#### Scenario: Initialization with raw value
- **WHEN** component script initializes a field
- **THEN** raw numeric value is stored in both hidden and visible inputs
- **AND** maskMoney is configured (but not yet active for empty fields)
- **AND** if field has value, maskMoney is activated and formats the display

#### Scenario: Focus destroys mask safely
- **WHEN** user focuses a formatted field like "Rp 50.000,00"
- **THEN** maskMoney's destroy is called
- **AND** visible input shows "50000" (raw)
- **AND** no errors or corruption occurs

#### Scenario: Blur reapplies mask correctly
- **WHEN** user blurs a field containing raw value "50000"
- **THEN** maskMoney is reconfigured
- **AND** maskMoney('mask') is called with the raw number
- **AND** the result displays "Rp 50.000,00" (correctly formatted)
- **AND** hidden input syncs to "50000" (raw)

### Requirement: Hidden and visible input synchronization
The system SHALL keep hidden and visible inputs in sync, with hidden always storing raw numeric values for form submission.

#### Scenario: Sync on blur
- **WHEN** user blurs a field
- **THEN** the hidden input is updated with the raw numeric value
- **AND** the visible input displays formatted currency
- **AND** both are consistent (same raw value, different display)

#### Scenario: Sync on keyup
- **WHEN** user types in the visible field while in focus mode (raw input)
- **AND** keyup event fires
- **THEN** the hidden input is updated with the entered raw value
- **AND** form submission uses the hidden input's raw value
