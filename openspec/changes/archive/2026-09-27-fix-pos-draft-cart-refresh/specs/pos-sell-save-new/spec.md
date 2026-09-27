# Spec Delta

## ADDED Requirements

### Requirement: Successful save-and-new establishes authoritative empty-cart state
After successfully saving the current POS cart as a draft, the system SHALL return the resulting cart snapshot and the POS shell MUST render that snapshot as the authoritative state for the new transaction context. The rendered shell MUST show an empty cart, reset transaction-scoped customer, totals, note, and active-draft state, and keep actions that require cart contents disabled until the new cart becomes valid.

#### Scenario: Successful draft save refreshes the shell
- **WHEN** a user successfully saves a valid POS cart as a draft
- **THEN** the successful response MUST include the resulting empty cart snapshot
- **AND** the POS shell MUST render the cart as empty without requiring a separate cart-refresh request
- **AND** the shell MUST be ready for the next customer

#### Scenario: Empty-cart controls remain disabled after request cleanup
- **WHEN** the successful draft-save response has been rendered
- **THEN** the save-draft and checkout actions MUST remain disabled while the new cart is empty
- **AND** request cleanup MUST NOT override the control state derived from the empty cart snapshot

#### Scenario: Previous transaction responses cannot repaint the new context
- **WHEN** a transaction-scoped UI request from the saved cart is still pending when save-and-new succeeds
- **THEN** a later response from that request MUST NOT restore state from the previous transaction context

#### Scenario: Failed draft save preserves current cart state
- **WHEN** save-and-new fails
- **THEN** the POS shell MUST preserve the current cart and transaction-scoped UI state
- **AND** it MUST display the failure without rendering a new empty-cart snapshot
