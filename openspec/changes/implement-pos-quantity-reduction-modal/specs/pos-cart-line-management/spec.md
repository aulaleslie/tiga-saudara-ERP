## MODIFIED Requirements

### Requirement: Quantity Change Event Handler
The system SHALL handle quantity input changes with validation that respects user privilege level.

#### Scenario: Privileged user reduces quantity directly
- **WHEN** a privileged user (with `can_reduce_quantity`) changes quantity from 10 to 5 via direct input
- **THEN** the system detects newQty < prevQty
- **THEN** the ApprovalManager.wrapAction() is called with action_type='QTY_REDUCE'
- **THEN** the existing approval workflow is triggered

#### Scenario: Privileged user increases quantity directly
- **WHEN** a privileged user changes quantity from 5 to 10 via direct input
- **THEN** the system detects newQty >= prevQty
- **THEN** the change is applied immediately without approval
- **THEN** a success message appears: "Qty berhasil diperbarui."

#### Scenario: Non-privileged user attempts direct quantity decrease
- **WHEN** a non-privileged user attempts to change quantity from 10 to 5 via direct input
- **THEN** the input field reverts to the previous value (10)
- **THEN** an error message displays: "Use the Reduce button to decrease quantity"
- **THEN** the change event handler does NOT call ApprovalManager.wrapAction()

#### Scenario: Non-privileged user successfully increases quantity
- **WHEN** a non-privileged user changes quantity from 5 to 10 via direct input
- **THEN** the change is applied immediately
- **THEN** a success message displays: "Qty berhasil diperbarui."
- **THEN** the input field's data-prev-qty attribute is updated to 10

#### Scenario: Non-privileged user submits reduction via modal
- **WHEN** a non-privileged user submits a reduction request via the Reduce button modal
- **THEN** the system calls ApprovalManager.wrapAction() with:
  - action_type: 'QTY_REDUCE'
  - target_type: 'pos_cart_line'
  - target_id: line_id
  - payload includes reason and new qty
- **THEN** the existing approval workflow processes the request
- **THEN** upon approval, the quantity is updated in the cart

### Requirement: Cart Line Rendering with Privilege Awareness
The system SHALL render cart lines with quantity controls appropriate to the user's privilege level.

#### Scenario: Serial line for privileged user
- **WHEN** rendering a serial-number-required line for a privileged user
- **THEN** the quantity cell displays the existing serial management UI with full quantity control (input + serial button)

#### Scenario: Serial line for non-privileged user
- **WHEN** rendering a serial-number-required line for a non-privileged user
- **THEN** the quantity cell displays:
  - Serial management UI (quantity display and serial button)
  - A "Reduce" button for requesting quantity reduction

#### Scenario: Non-serial line for privileged user
- **WHEN** rendering a non-serial line for a privileged user
- **THEN** the quantity cell displays an editable input field (current behavior)

#### Scenario: Non-serial line for non-privileged user
- **WHEN** rendering a non-serial line for a non-privileged user
- **THEN** the quantity cell displays:
  - An input field for incrementing/entering higher quantities
  - A "Reduce" button next to the input field
