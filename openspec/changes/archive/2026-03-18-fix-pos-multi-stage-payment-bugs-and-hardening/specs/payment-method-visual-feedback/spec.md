# payment-method-visual-feedback Specification

## Purpose
Defines requirements for visual indicators that make payment method selection state clear and visible to users in the multi-stage payment modal.

## ADDED Requirements

### Requirement: Payment method input has visible background
The payment method search input field SHALL display a visible background color to distinguish it from the page background and indicate that it is an interactive element.

#### Scenario: Input appears in normal state
- **WHEN** the payment method selection modal is displayed
- **THEN** the "Metode Pembayaran" input field SHALL display a white or light background (not transparent)

#### Scenario: Input is clearly visible against modal background
- **WHEN** user looks at the modal
- **THEN** the payment method input SHALL have sufficient contrast to be clearly distinguishable from the modal background

### Requirement: Payment method input shows focus state
When the payment method input receives keyboard focus, the system SHALL provide visual feedback that the field is active.

#### Scenario: Input shows focus indication
- **WHEN** user tabs to or clicks on the payment method input field
- **THEN** field SHALL display a focus ring or border highlight (e.g., blue outline)

### Requirement: Selected payment method is clearly displayed
Once a payment method is selected, the system SHALL display its name in the input field with clear visual confirmation.

#### Scenario: Selected method name appears in input
- **WHEN** user selects a payment method from the dropdown (e.g., "BRI")
- **THEN** the input field SHALL display "BRI" in a way that indicates it has been selected (not placeholder text)

#### Scenario: Dropdown closes after selection
- **WHEN** user selects a payment method
- **THEN** the dropdown list SHALL close and focus SHALL move to the amount input field
