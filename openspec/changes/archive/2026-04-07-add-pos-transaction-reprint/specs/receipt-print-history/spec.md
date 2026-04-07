## ADDED Requirements

### Requirement: Receipt template displays print history summary
The receipt template SHALL display a summary of print history showing the total count of prints/reprints and information about the last person who printed.

#### Scenario: Print history displays for first print
- **WHEN** a receipt is viewed for the first time (logged as PRINT)
- **THEN** the receipt displays: "Printed 1 time. Last printed by [cashier name] at [timestamp]"

#### Scenario: Print history updates on reprint
- **WHEN** a receipt is reprinted (logged as REPRINT)
- **THEN** the receipt displays: "Printed [N] times. Last printed by [repriter name] at [new timestamp]"

#### Scenario: Print history visible when printing
- **WHEN** a user prints the receipt to physical printer or PDF
- **THEN** the print history summary is included in the printed output

#### Scenario: Print history displays in UI view
- **WHEN** a user views a receipt in the browser
- **THEN** the print history summary is displayed on-screen

#### Scenario: Print history includes user information
- **WHEN** viewing print history
- **THEN** the system SHALL display:
  - Total count of all print/reprint events
  - Name of the user who printed most recently
  - Exact timestamp of the last print action (formatted as YYYY-MM-DD HH:MM:SS)

### Requirement: Print history placement on receipt
Print history information SHALL be positioned in the receipt template in a way that remains visible both on-screen and when printed, serving as an audit trail.

#### Scenario: Print history appears after business header
- **WHEN** rendering a receipt
- **THEN** the print history summary appears after the business header section and before the line items section

#### Scenario: Print history clearly delineated
- **WHEN** viewing a receipt with print history
- **THEN** the print history section is visually separated from other sections with dividers or spacing
