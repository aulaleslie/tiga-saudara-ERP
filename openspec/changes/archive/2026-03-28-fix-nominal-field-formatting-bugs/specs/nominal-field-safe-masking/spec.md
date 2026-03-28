## ADDED Requirements

### Requirement: Safe masking of raw numeric values
The system SHALL always pass raw numeric values (not pre-formatted strings) to maskMoney's mask function to prevent decimal separator conflicts in locale-specific formatting.

#### Scenario: Edit page with populated price field
- **WHEN** product edit page loads with purchase_price = 50000 (from database)
- **AND** user focuses the price field
- **THEN** the visible input shows "50000" (raw, unformatted)
- **AND** maskMoney is in destroyed state

#### Scenario: Blur formatting with large number
- **WHEN** user is in focus mode viewing "50000" (raw)
- **AND** user blurs the field (clicks outside)
- **THEN** the visible input displays "Rp 50.000,00" (correctly formatted)
- **AND** the hidden input stores "50000" (raw)
- **AND** the displayed value is NOT corrupted to "0.5" or other incorrect value

#### Scenario: Blur formatting with decimal input
- **WHEN** user enters "1234.56" while field is raw
- **AND** user blurs the field
- **THEN** the visible input displays "Rp 1.234,56" (correctly formatted with comma decimal)
- **AND** the hidden input stores "1234.56" (raw with period decimal)

#### Scenario: Prevent toFixed() pre-formatting conflicts
- **WHEN** component blur handler extracts a raw value like 50000
- **AND** maskMoney's mask function is called
- **THEN** the raw number is passed directly without calling toFixed(2) first
- **AND** maskMoney applies its configured precision (2 decimals) using locale separators

### Requirement: Locale-aware formatting
The system SHALL respect the Indonesian locale (IDR) currency formatting rules: period (.) as thousands separator, comma (,) as decimal separator.

#### Scenario: Indonesian thousands separator
- **WHEN** a field is formatted with value 1000000
- **THEN** the display shows "Rp 1.000.000,00" (NOT "Rp 1,000,000.00")

#### Scenario: Indonesian decimal separator
- **WHEN** a field is formatted with value 1234.56
- **THEN** the display shows "Rp 1.234,56" (NOT "Rp 1.234.56")

### Requirement: No decimal separator confusion
The system SHALL NOT misinterpret JavaScript's native toFixed(2) period decimal (e.g., "50000.00") as a locale-specific thousands separator during maskMoney formatting.

#### Scenario: Prevent misinterpretation of period
- **WHEN** blur handler processes a field with raw value 50000
- **AND** maskMoney formats this value
- **THEN** maskMoney interprets the value as 50000 (not as 50.000 with period as thousands sep)
- **AND** the formatted result is "Rp 50.000,00" (with proper Indonesian formatting)
