# nominal-field-formatter Specification

## Purpose
TBD - created by archiving change fix-nominal-field-formatting-consistency. Update Purpose after archive.
## Requirements
### Requirement: Reusable nominal field component for currency/numeric inputs
The system SHALL provide a reusable Blade component `<x-nominal-field>` that handles currency and numeric field formatting consistently across all forms. This component encapsulates the jQuery maskMoney lifecycle (configuration, focus/blur behavior, form submission extraction) and automatically integrates currency settings from the database.

#### Scenario: Component displays formatted value on page load
- **WHEN** a page loads with an edit form containing nominal fields
- **THEN** each field displays the currency-formatted value (e.g., "Rp 1.000.000,00")

#### Scenario: Field shows raw number on focus
- **WHEN** user clicks focus into a nominal field
- **THEN** the field displays the raw numeric value (e.g., "1000000") with all formatting removed
- **AND** the entire value is selected for immediate editing

#### Scenario: Field returns to formatted display on blur
- **WHEN** user leaves/blurs a nominal field
- **THEN** the field returns to currency-formatted display (e.g., "Rp 1.000.000,00")
- **AND** any raw input is validated and formatted to 2 decimal places

#### Scenario: Raw numeric value submitted on form submission
- **WHEN** user submits a form containing nominal fields
- **THEN** all nominal field values are extracted as raw numeric values (no currency symbols or separators) before transmission
- **AND** the server receives plain decimal numbers suitable for database storage

#### Scenario: Component respects disabled state
- **WHEN** a nominal field is marked as disabled
- **THEN** the field displays formatted value but cannot be edited
- **AND** a hidden mirror input is created to ensure the value submits correctly

#### Scenario: Component integrates currency settings
- **WHEN** the component is rendered
- **THEN** it automatically reads currency symbol, decimal separator, and thousand separator from `settings()->currency->*` database settings
- **AND** it applies these settings to all formatting operations

#### Scenario: Component provides null-safe fallback
- **WHEN** currency settings are unavailable or null
- **THEN** the component uses safe fallback defaults (symbol: 'Rp ', thousands: '.', decimal: ',')
- **AND** no fatal errors occur

#### Scenario: Component validates numeric input
- **WHEN** user types a non-numeric character in a nominal field (except decimal/thousand separators)
- **THEN** the character is ignored or stripped during formatting
- **AND** only valid numeric characters are retained

### Requirement: Nominal field behavior consistency across product create and edit
The product pricing fields (Harga Beli, Harga Jual, Harga Jual Partai Besar, Harga Jual Reseller) and conversion table prices SHALL exhibit identical formatting behavior on both create and edit pages, eliminating the previous inconsistency where edit page showed formatted values on focus.

#### Scenario: Create page shows raw on focus
- **WHEN** user opens the product create page and focuses a price field
- **THEN** the field displays raw number without currency formatting

#### Scenario: Edit page shows raw on focus
- **WHEN** user opens the product edit page and focuses a price field
- **THEN** the field displays raw number without currency formatting (FIXED behavior)
- **AND** this behavior is identical to create page

#### Scenario: Conversion table pricing field behaves identically
- **WHEN** user focuses a price field in the Konversi Unit table
- **THEN** the field displays raw number without formatting
- **AND** the field is independent and doesn't interfere with other row conversions

### Requirement: Conversion table pricing reliability
The conversion table price inputs in unit-configuration SHALL have reliable focus/blur behavior without competing JavaScript frameworks causing state loss or unexpected reformatting.

#### Scenario: Conversion price field survives Livewire re-renders
- **WHEN** Livewire updates other conversion table rows (unit selection, quantity change, etc.)
- **THEN** the price field formatting state is preserved
- **AND** focus/blur behavior remains consistent across all updates

#### Scenario: Multiple conversion rows maintain independent state
- **WHEN** user edits price in one conversion row
- **THEN** that row's formatting behavior is independent
- **AND** other rows are not affected by that row's focus/blur events

#### Scenario: Hidden price input syncs with display input
- **WHEN** user updates a visible price field
- **THEN** the hidden storage input (for form submission) is automatically updated
- **AND** both inputs remain in sync throughout the user's interaction

