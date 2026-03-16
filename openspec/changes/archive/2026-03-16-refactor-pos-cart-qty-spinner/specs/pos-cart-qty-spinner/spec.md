## ADDED Requirements

### Requirement: Quantity spinner control
The system SHALL render quantity input as a spinner with increment (+) and decrement (-) buttons instead of a plain numeric input field.

#### Scenario: Display spinner for non-serial item
- **WHEN** user views cart with non-serial line item
- **THEN** quantity cell displays: `[-] [input field] [+]` with styled buttons

#### Scenario: Display spinner for serial item
- **WHEN** user views cart with serial line item
- **THEN** quantity cell displays spinner at top, followed by serial management controls below

#### Scenario: Increment quantity
- **WHEN** user clicks + button
- **THEN** quantity input value increases by 1 and quantity is updated server-side

#### Scenario: Decrement quantity with permission
- **WHEN** user with can_reduce_quantity permission clicks - button
- **THEN** quantity input value decreases by 1 and quantity is updated server-side

#### Scenario: Decrement quantity without permission
- **WHEN** user without can_reduce_quantity permission clicks - button
- **THEN** approval request flow is triggered (see pos-cart-approval-workflow spec)

### Requirement: Spinner approval state transformation
The system SHALL transform the minus button when quantity reduction approval is pending or approved.

#### Scenario: Approval pending state
- **WHEN** user requests quantity reduction without approval and request is pending
- **THEN** minus button is hidden/replaced by yellow "Periksa Persetujuan" button showing request status

#### Scenario: Approval approved state
- **WHEN** quantity reduction approval is granted by approver
- **THEN** minus button is hidden/replaced by green "✓ {approvedQty}" button allowing user to confirm execution

#### Scenario: Approval rejected or cancelled
- **WHEN** approval request is rejected or cancelled
- **THEN** minus button returns to normal state and user can retry

### Requirement: Direct quantity input editing
The system SHALL allow direct input editing of quantity value in spinner input field.

#### Scenario: Edit quantity directly
- **WHEN** user clicks in input field and types new quantity value
- **THEN** quantity is updated when user presses Enter or loses focus

#### Scenario: Validation on direct edit
- **WHEN** user attempts to set quantity below 1 or above max allowed
- **THEN** input is rejected or normalized to valid range (minimum 1)
