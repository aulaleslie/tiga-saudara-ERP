## ADDED Requirements

### Requirement: Price Cell MUST Render Edit Trigger With Approval State
The POS sell UI SHALL render each cart line's price cell with an edit trigger button that reflects the current PRICE_OVERRIDE approval state.

#### Scenario: No pending PRICE_OVERRIDE approval renders edit button
- **WHEN** a cart line has no pending or approved PRICE_OVERRIDE approval request
- **THEN** the price cell MUST display the formatted unit price and a pencil edit button with class `js-price-edit`

#### Scenario: Pending PRICE_OVERRIDE approval renders Periksa button
- **WHEN** a cart line has a pending PRICE_OVERRIDE approval request
- **THEN** the price cell MUST display the formatted unit price and a "Periksa" button with `data-approval-pending` attribute set to the request ID
- **AND** the button MUST have class `js-price-edit`

#### Scenario: Approved PRICE_OVERRIDE approval renders Lanjutkan button
- **WHEN** a cart line has an approved PRICE_OVERRIDE approval request with an execution token
- **THEN** the price cell MUST display the formatted unit price and a "Lanjutkan" button showing the approved target price
- **AND** the button MUST have `data-approval-token` and `data-approved-price` attributes
- **AND** the button MUST have class `js-price-edit`

### Requirement: Price Edit Button Click MUST Open Price Override Modal
The POS sell UI SHALL open a price override modal when the user clicks the price edit button on a cart line that has no pending or approved approval state.

#### Scenario: Click edit button opens modal with current price context
- **WHEN** the user clicks a `js-price-edit` button that has no `data-approval-pending` or `data-approval-token` attribute
- **THEN** the system MUST open the "Ubah Harga" modal
- **AND** the modal MUST display the current unit price as read-only reference
- **AND** the modal MUST provide an input field for the new target price pre-filled with the current price
- **AND** the modal MUST allow values greater than or equal to 0

#### Scenario: Click Periksa button checks approval status
- **WHEN** the user clicks a `js-price-edit` button that has `data-approval-pending` attribute
- **THEN** the system MUST call `ApprovalManager.checkApproval` with the pending request ID
- **AND** the system MUST fetch a fresh cart snapshot and re-render

#### Scenario: Click Lanjutkan button applies approved price via wrapAction
- **WHEN** the user clicks a `js-price-edit` button that has `data-approval-token` attribute
- **THEN** the system MUST call `ApprovalManager.wrapAction` with action type `PRICE_OVERRIDE`, the approval token, and the approved price
- **AND** the action function MUST POST to `/pos/sell/cart/lines/{lineId}/price-override` with `unit_price` and `approval_token`

### Requirement: Price Override Modal Submit MUST Use ApprovalManager.wrapAction
The price override modal submission SHALL route through `ApprovalManager.wrapAction()` using an action type of `PRICE_OVERRIDE`, matching the established pattern for LINE_REMOVE, CART_CLEAR, and QTY_REDUCE.

#### Scenario: Privileged user submits price change via modal
- **WHEN** a user with `pos.overrides.price` permission submits a new price through the modal
- **THEN** the system MUST call `ApprovalManager.wrapAction` which invokes the action function
- **AND** the action function MUST POST to `/pos/sell/cart/lines/{lineId}/price-override` with the new `unit_price`
- **AND** the backend MUST apply the price immediately and return an updated cart snapshot
- **AND** the cart MUST re-render with the new price

#### Scenario: Non-privileged user submits price change via modal and triggers approval
- **WHEN** a user without `pos.overrides.price` permission submits a new price through the modal
- **THEN** the system MUST call `ApprovalManager.wrapAction` which attempts the action and receives `APPROVAL_REQUIRED`
- **AND** the `ApprovalManager` MUST create an approval request via `POST /pos/sell/approval-requests`
- **AND** the price edit button MUST transition to "Periksa" state with `data-approval-pending` attribute
- **AND** the system MUST fetch a fresh cart snapshot and re-render

### Requirement: Price Override Modal MUST Validate Input Before Submission
The price override modal SHALL enforce client-side validation before enabling the submission button.

#### Scenario: Empty or non-numeric input disables submit
- **WHEN** the target price input is empty or contains non-numeric characters
- **THEN** the submit button MUST be disabled
- **AND** an inline validation error MUST be displayed

#### Scenario: Negative input disables submit
- **WHEN** the target price input is a negative number
- **THEN** the submit button MUST be disabled
- **AND** an inline validation error MUST be displayed

#### Scenario: Zero input is valid
- **WHEN** the target price input is exactly 0
- **THEN** the submit button MUST be enabled
- **AND** no validation error MUST be displayed

#### Scenario: Same price as current disables submit
- **WHEN** the target price input equals the current unit price
- **THEN** the submit button MUST be disabled
- **AND** an inline message MUST indicate that the price is unchanged

### Requirement: Price Override Modal MUST Be An Extracted Blade Partial
The price override modal HTML SHALL be extracted to a separate Blade partial file consistent with the existing modal organization pattern.

#### Scenario: Modal partial exists at expected path
- **WHEN** the POS sell view is rendered
- **THEN** the view MUST include a Blade partial at `pos::sell.modals.price_override`
- **AND** the partial MUST contain a Bootstrap modal with id `pos-price-override-modal`
