## ADDED Requirements

### Requirement: Quantity Reduction Approval State MUST Persist Across Cart Re-render Cycles
The POS cart UI SHALL keep quantity-reduction approval controls visible and actionable for non-authorized users after approval request submission and after cart reload.

#### Scenario: Pending quantity-reduction request is visible immediately after submission
- **WHEN** a user without direct reduce permission submits a quantity-reduction approval request and the request is accepted
- **THEN** the cart row MUST render a `Periksa Persetujuan` control bound to the created approval request

#### Scenario: Pending quantity-reduction request remains visible after page refresh
- **WHEN** the cart is reloaded and the related approval request status is still `PENDING`
- **THEN** the cart row MUST render the same `Periksa Persetujuan` control from `line.pending_approvals`

### Requirement: Quantity Reduction Approval Rendering MUST Use Deterministic Snapshot Contract
The POS sell cart renderer SHALL consume cart refresh responses through `cart_snapshot` and SHALL resolve quantity-reduction approval state from deterministic approval metadata keys.

#### Scenario: Cart show response is parsed before rendering
- **WHEN** frontend requests `/pos/sell/cart` for a fresh snapshot
- **THEN** the renderer MUST receive `response.cart_snapshot` (not the wrapper object) before evaluating line approval states

#### Scenario: Mixed approval metadata sources remain render-compatible
- **WHEN** quantity-reduction approval state is derived from both transient client cache and server `pending_approvals`
- **THEN** the renderer MUST normalize request id, status, token, and approved quantity fields so pending and approved controls render correctly

### Requirement: Quantity Reduction Approval State Transitions MUST Match Supervised Cart Patterns
Quantity-reduction approval controls SHALL follow the same supervised-action transition semantics used by other cart actions.

#### Scenario: Approved request renders proceed state
- **WHEN** a quantity-reduction request status becomes `APPROVED`
- **THEN** the cart row MUST render a positive proceed control that carries approval token and approved quantity for execution

#### Scenario: Rejected or cancelled request clears follow-up control
- **WHEN** a quantity-reduction request status becomes `REJECTED` or `CANCELLED`
- **THEN** the cart row MUST stop rendering follow-up approval controls and MUST allow a fresh reduction request path
