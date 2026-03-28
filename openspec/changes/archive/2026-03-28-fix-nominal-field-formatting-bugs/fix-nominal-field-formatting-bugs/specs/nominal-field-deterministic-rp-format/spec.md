## ADDED Requirements

### Requirement: Deterministic RP display format for product nominal fields
The system SHALL format product nominal fields using a fixed profile: prefix `RP `, thousands separator `.`, decimal separator `,`, and two decimal digits.

#### Scenario: Formatting large integer input
- **WHEN** user enters raw `50000` in a product nominal field and blurs
- **THEN** the visible field shows `RP 50.000,00`
- **AND** the formatted result does not vary by browser/system locale

#### Scenario: Formatting edit value after change
- **WHEN** edit field starts at raw `60000`
- **AND** user focuses field, edits to `65000`, and blurs
- **THEN** visible value becomes `RP 65.000,00`
- **AND** NOT `0.6`, `0.65`, or other decimal-shifted values

### Requirement: Canonical raw value contract for product nominal fields
The system SHALL preserve a canonical raw numeric value independently from display formatting.

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

### Requirement: Product nominal formatting shall be independent from DB/system currency configuration
The system SHALL NOT depend on dynamic DB currency symbol/separator values or system-locale formatting APIs for product nominal display/output consistency.

#### Scenario: DB currency configuration differs from RP profile
- **WHEN** DB currency configuration uses different symbol or separators
- **THEN** product nominal fields still display using `RP `, `.`, `,`
- **AND** behavior remains identical across create/edit pages

#### Scenario: Host locale differs from Indonesian formatting
- **WHEN** application runs on a host/browser with non-Indonesian locale defaults
- **THEN** product nominal display still follows fixed `RP X.XXX,YY` output
- **AND** no locale-dependent separator swapping occurs
