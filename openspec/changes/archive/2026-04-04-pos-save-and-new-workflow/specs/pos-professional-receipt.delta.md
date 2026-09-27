# pos-professional-receipt (Delta)

## ADDED Requirements

### Requirement: Bolder Font Legibility for Thermal Printers
The receipt layout CSS SHALL be updated to use bolder font weight for global body text and headers to improve legibility on physical thermal printers.

#### Scenario: Bolder body text
- **WHEN** the thermal receipt is rendered
- **THEN** it MUST use a `font-weight` of at least 600 or equivalent bold style for most text elements
- **AND** headers SHOULD be even bolder if feasible (e.g., `font-weight: 700+`)
- **AND** the goal is to make the code/numbers stand out clearly for reading
