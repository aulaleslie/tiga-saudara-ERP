## MODIFIED Requirements

### Requirement: Quantity reduction approval with spinner transformation
The system SHALL manage approval workflow for users attempting to reduce cart line quantity without required permission.

#### Scenario: Request quantity reduction approval
- **WHEN** user without can_reduce_quantity permission clicks minus button on quantity spinner
- **THEN** system triggers approval request workflow, minus button transforms to yellow "Periksa Persetujuan" button

#### Scenario: Check approval status
- **WHEN** user clicks "Periksa Persetujuan" button while approval is pending
- **THEN** system polls backend for approval status and displays current state to user

#### Scenario: Approval is granted for quantity reduction
- **WHEN** approver grants permission for quantity reduction
- **THEN** yellow "Periksa" button transforms to green "✓ {approvedQty}" button with approved quantity displayed

#### Scenario: Execute approved quantity reduction
- **WHEN** user clicks "✓ {approvedQty}" button
- **THEN** confirmation modal appears asking to confirm action; upon confirmation, quantity is reduced to approved value and cart is updated

#### Scenario: Cancel approved quantity reduction
- **WHEN** user clicks confirmation "Cancel" button instead of confirming quantity reduction
- **THEN** approval token is cleared, minus button returns to initial state, no quantity change occurs

#### Scenario: Quantity reduction approval rejected
- **WHEN** approver rejects quantity reduction request
- **THEN** minus button returns to normal state, user can retry request

### Requirement: Line deletion approval in Actions column
The system SHALL manage approval workflow for users attempting to delete cart line item without required permission.

#### Scenario: Request line deletion approval
- **WHEN** user without line deletion permission clicks "Hapus" button in Actions column
- **THEN** system triggers approval request workflow, button transforms to yellow "Periksa" button

#### Scenario: Check deletion approval status
- **WHEN** user clicks "Periksa" button while approval is pending
- **THEN** system polls backend for approval status and displays current state

#### Scenario: Approval is granted for line deletion
- **WHEN** approver grants permission for line deletion
- **THEN** yellow "Periksa" button transforms to green "Lanjutkan" button

#### Scenario: Execute approved line deletion
- **WHEN** user clicks "Lanjutkan" button
- **THEN** confirmation modal appears asking to confirm action; upon confirmation, line is deleted from cart and cart is updated

#### Scenario: Cancel approved line deletion
- **WHEN** user clicks confirmation "Cancel" button instead of confirming deletion
- **THEN** approval token is cleared, "Hapus" button returns to initial red state, no deletion occurs

#### Scenario: Line deletion approval rejected
- **WHEN** approver rejects line deletion request
- **THEN** button returns to red "Hapus" state, user can retry request

### Requirement: Approval flow maintains backward compatibility
The system SHALL ensure approval request/response structure and backend behavior remain unchanged.

#### Scenario: Approval API unchanged
- **WHEN** system processes approval requests for QTY_REDUCE or LINE_REMOVE actions
- **THEN** API contract at /pos/sell/approval-requests remains unchanged

#### Scenario: Approval state machine unchanged
- **WHEN** approval workflow transitions between pending → approved → executed
- **THEN** state transitions and token generation follow existing backend logic
