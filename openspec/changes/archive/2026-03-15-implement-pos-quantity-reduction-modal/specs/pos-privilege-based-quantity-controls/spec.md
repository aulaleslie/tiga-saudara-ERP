## ADDED Requirements

### Requirement: Privilege-Based Quantity Control Rendering
The system SHALL render different quantity input controls based on the user's privilege level.

#### Scenario: Privileged user sees full quantity controls
- **WHEN** a user with `can_reduce_quantity` privilege views the POS cart
- **THEN** the quantity cell displays an editable input field that allows direct increment and decrement

#### Scenario: Non-privileged user sees restricted quantity controls
- **WHEN** a user without `can_reduce_quantity` privilege views the POS cart
- **THEN** the quantity cell displays:
  - An input field that allows manual entry of quantities >= current quantity
  - A "Reduce" button (with ↓ icon) for requesting reductions

### Requirement: Privilege Capability Availability
The system SHALL have a `can_reduce_quantity` capability that determines quantity control behavior.

#### Scenario: Capability is passed to frontend
- **WHEN** the POS sell page loads
- **THEN** the `roleCapabilities` or similar privilege data structure includes a `can_reduce_quantity` flag
- **THEN** this flag is available in the cart rendering logic

#### Scenario: Capability defaults to false for restrictive behavior
- **WHEN** a user's privilege data is missing the `can_reduce_quantity` flag
- **THEN** the system assumes non-privileged (false) and restricts quantity reduction

### Requirement: Quantity Control Consistency
The system SHALL ensure that quantity control appearance remains consistent throughout the user session.

#### Scenario: Controls do not change during session
- **WHEN** the cart is re-rendered (after adding items, updating quantities, etc.)
- **THEN** the quantity control type (privileged vs. non-privileged) remains the same for the user

#### Scenario: Multiple carts maintain their own control sets
- **WHEN** a user manages multiple cart lines
- **THEN** all lines show the same type of quantity controls based on the user's privilege level
