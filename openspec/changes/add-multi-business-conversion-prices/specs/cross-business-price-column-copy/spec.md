## ADDED Requirements

### Requirement: Conversion copying is scoped by conversion identity
Conversion price fields SHALL expose apply-to-all controls in edit mode only for valid numeric values changed from their loaded state, treating missing as distinct from zero. Copying SHALL target only the same conversion ID across all displayed businesses and SHALL NOT change other conversion columns, base price columns, factors, or enablement flags.

#### Scenario: Copy one conversion
- **WHEN** a user changes KOTAK price and activates its apply-to-all control
- **THEN** every displayed business's KOTAK field SHALL receive that numeric value
- **AND** other conversion and base price fields SHALL remain unchanged

#### Scenario: Copy into a missing cell
- **WHEN** a conversion price is copied into a business cell that was missing
- **THEN** that cell SHALL contain the explicit copied value and be treated as changed

#### Scenario: Copy zero
- **WHEN** a user enters zero into a missing cell
- **THEN** its apply-to-all control SHALL become available
- **AND** copying SHALL propagate explicit zero only within that conversion column

#### Scenario: Copy remains unsaved until Save
- **WHEN** the user copies a conversion price without saving
- **THEN** the database SHALL remain unchanged
- **AND** Batal SHALL restore original numeric values and missing states and hide copy controls

#### Scenario: Save copied values
- **WHEN** the user saves copied conversion prices
- **THEN** they SHALL pass through the combined authorization, validation, atomic save, and stale-state checks
