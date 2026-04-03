## MODIFIED Requirements

### Requirement: Canonical raw value contract for product nominal fields
The system SHALL preserve a canonical raw numeric value independently from display formatting for product nominal fields, including dynamic conversion price rows rendered through the stock-managed unit configuration flow.

#### Scenario: Raw value preserved on blur
- **WHEN** user edits a product nominal field to `50000`
- **AND** blur formatting runs
- **THEN** hidden/submitted raw value remains `50000`
- **AND** visible display is `RP 50.000,00`

#### Scenario: Decimal raw value preserved
- **WHEN** user enters raw `1234.56`
- **AND** blur formatting runs
- **THEN** raw value remains `1234.56`
- **AND** visible display is `RP 1.234,56`

#### Scenario: Conversion row raw value preserved after dynamic add
- **WHEN** user adds a conversion row in the stock-managed unit configuration table
- **AND** enters raw `65000` as the conversion price
- **AND** blur formatting runs
- **THEN** the conversion row's canonical submitted value remains `65000`
- **AND** visible display is `RP 65.000,00`

#### Scenario: Conversion row raw value preserved after validation round-trip
- **WHEN** user enters a conversion price in a stock-managed conversion row
- **AND** the form is redirected back with validation errors on another field
- **THEN** the conversion row SHALL still be populated from old input with the same canonical raw numeric value
- **AND** its visible display SHALL remain formatted using the deterministic RP profile
