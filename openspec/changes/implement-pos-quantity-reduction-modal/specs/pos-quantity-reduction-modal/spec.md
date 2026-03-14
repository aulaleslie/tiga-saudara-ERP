## ADDED Requirements

### Requirement: Quantity Reduction Modal Appearance
The system SHALL display a small "Reduce" button (with ↓ icon) next to the quantity input field for non-privileged users only.

#### Scenario: Non-privileged user sees reduce button
- **WHEN** a non-privileged user views the POS cart
- **THEN** the quantity cell displays a quantity input field and a "Reduce" button with a ↓ icon

#### Scenario: Privileged user does not see reduce button
- **WHEN** a privileged user (with reduce quantity privilege) views the POS cart
- **THEN** the quantity cell displays standard quantity controls without a separate reduce button

### Requirement: Quantity Reduction Modal Form
The system SHALL open a modal when the reduce button is clicked, capturing the desired quantity and optional reason.

#### Scenario: Modal opens with correct fields
- **WHEN** a non-privileged user clicks the reduce button
- **THEN** a modal titled "Reduce Quantity" appears with:
  - Current quantity displayed (read-only)
  - "New Qty" input field with max value = current quantity - 1
  - "Reason" textarea (optional, empty by default)
  - Cancel and Request Reduction buttons

#### Scenario: Modal shows correct max quantity
- **WHEN** the modal opens for an item with quantity 10
- **THEN** the New Qty input field has max="9"

### Requirement: Quantity Reduction Validation
The system SHALL validate the desired quantity before submission.

#### Scenario: Valid reduction input accepted
- **WHEN** user enters new quantity between 1 and (current - 1)
- **THEN** the Request Reduction button is enabled and submission is allowed

#### Scenario: Invalid reduction input rejected
- **WHEN** user attempts to enter new quantity >= current or < 1
- **THEN** an error message appears: "New quantity must be between 1 and [max]"
- **THEN** the Request Reduction button remains disabled until valid input is provided

### Requirement: Quantity Reduction Submission
The system SHALL process the reduction request through the approval workflow when submitted.

#### Scenario: Reduction request submitted successfully
- **WHEN** user fills valid new quantity and clicks "Request Reduction"
- **THEN** the system sends a QTY_REDUCE approval request via ApprovalManager with:
  - action_type: 'QTY_REDUCE'
  - target_type: 'pos_cart_line'
  - target_id: line_id
  - reason: (reason text or null if empty)
  - payload: { qty: new_qty }
- **THEN** the modal closes
- **THEN** a status message appears: "Reduction request submitted. Awaiting approval."

#### Scenario: Reduction request submission fails
- **WHEN** the submission fails due to network or server error
- **THEN** an error message displays the error reason
- **THEN** the modal remains open allowing the user to retry

### Requirement: Quantity Input Validation for Non-Privileged Users
The system SHALL prevent non-privileged users from directly entering quantities lower than the current quantity.

#### Scenario: Non-privileged user attempts to decrease quantity via input
- **WHEN** a non-privileged user changes the quantity input to a value < current quantity
- **THEN** the input reverts to the previous quantity value
- **THEN** a message appears: "Use the Reduce button to decrease quantity"

#### Scenario: Non-privileged user can increase quantity via input
- **WHEN** a non-privileged user changes the quantity input to a value > current quantity
- **THEN** the change is applied immediately without approval
- **THEN** a success message appears: "Qty updated successfully"

#### Scenario: Non-privileged user can maintain quantity via input
- **WHEN** a non-privileged user enters the same quantity as current
- **THEN** no action is taken and the input is not modified
