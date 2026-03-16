## ADDED Requirements

### Requirement: Dedicated Actions column in cart table
The system SHALL display a new "Aksi" (Actions) column in the cart table containing line item deletion controls.

#### Scenario: Actions column visible
- **WHEN** user views POS cart table
- **THEN** table shows new rightmost column header "Aksi" with delete button for each line item

#### Scenario: Delete button initial state
- **WHEN** no approval is required or pending for line deletion
- **THEN** "Hapus" button appears in red text, indicating destructive action

#### Scenario: Delete button with pending approval
- **WHEN** user requests line deletion without approval and request is pending
- **THEN** yellow "Periksa" button appears showing approval is waiting

#### Scenario: Delete button with approved deletion
- **WHEN** line deletion approval is granted
- **THEN** green "Lanjutkan" button appears allowing user to confirm and execute deletion

### Requirement: Line item deletion from Actions column
The system SHALL allow users to delete line items via the Actions column button.

#### Scenario: Delete without approval
- **WHEN** user with line deletion permission clicks "Hapus" button
- **THEN** confirmation modal appears and line is removed from cart upon confirmation

#### Scenario: Delete with approval required
- **WHEN** user without deletion permission clicks "Hapus" button
- **THEN** approval request workflow is triggered (see pos-cart-approval-workflow spec)

#### Scenario: Confirm approved deletion
- **WHEN** user with pending approval clicks "Lanjutkan" button
- **THEN** confirmation modal appears asking to confirm action, and line is removed upon confirmation

#### Scenario: Cancel approved deletion
- **WHEN** user with pending approval clicks confirmation "Cancel" button instead of confirming
- **THEN** approval is cancelled and "Hapus" button returns to initial state

### Requirement: Actions column visual separation
The system SHALL provide clear visual separation between quantity controls (Qty column) and line item deletion controls (Aksi column).

#### Scenario: Quantity and deletion are spatially separated
- **WHEN** user views cart line item
- **THEN** quantity spinner is in Qty column, delete button is in separate Aksi column to the right

#### Scenario: No visual confusion
- **WHEN** user hovers over or clicks delete button
- **THEN** action affects only line item deletion, not quantity
