## ADDED Requirements

### Requirement: Purchase tax policy applies to final line-total input
The purchase creation and edit cart SHALL retain the active setting's PKP tax policy and current `is_tax_included` state when a user supplies a final line total, then normalize the result into the existing persisted purchase-detail fields.

#### Scenario: PKP final line total preserves selected purchase tax
- **WHEN** a user supplies a final line total for a PKP purchase row with a resolved purchase tax
- **THEN** the cart SHALL retain that row's tax ID
- **AND** the persisted detail SHALL contain the compatible subtotal and product tax amount

#### Scenario: Non-PKP final line total persists no tax
- **WHEN** a user supplies a final line total for a non-PKP purchase row
- **THEN** the cart SHALL not expose tax state
- **AND** the persisted detail SHALL have a null tax ID and zero product tax amount

