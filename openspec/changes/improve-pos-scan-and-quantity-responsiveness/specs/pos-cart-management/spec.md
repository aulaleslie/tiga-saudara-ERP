# Spec Delta

## ADDED Requirements

### Requirement: POS quantity changes SHALL refresh the affected row and cart summary consistently
After a successful quantity change, the POS sell screen MUST display the authoritative quantity, line pricing, cart totals, approval state, and checkout availability. Unchanged cart rows MUST retain their current browser interaction state when the cart's row structure and identity have not changed.

#### Scenario: Quantity increases on an existing row
- **WHEN** a cashier increases one row's quantity and the server accepts it
- **THEN** that row and the cart totals MUST reflect the returned cart state
- **AND** unrelated rows MUST remain usable without being replaced

#### Scenario: Quantity reduction is approved
- **WHEN** an authorized quantity reduction completes
- **THEN** the reduced row, approval indicator, totals, and checkout controls MUST reflect the returned cart state

#### Scenario: Quantity mutation fails
- **WHEN** the server rejects a quantity change for stock, authorization, or another validation reason
- **THEN** the edited quantity MUST revert to the last accepted value
- **AND** no unconfirmed cart total MUST be displayed as accepted

#### Scenario: Cart structure changes during response
- **WHEN** the returned cart has a different set or order of row identities from the displayed cart
- **THEN** the screen MUST render the complete returned cart state so no row or total is stale

#### Scenario: Packed pricing or serial state changes
- **WHEN** a quantity change recalculates packed pricing or changes serial completeness
- **THEN** the affected row and checkout controls MUST show the authoritative returned values
