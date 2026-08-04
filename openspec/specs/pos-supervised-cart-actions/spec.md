# pos-supervised-cart-actions Specification

## Purpose
TBD
## Requirements
### Requirement: Qty Column Controls MUST Use Compact Semantic Spinner Styling
The POS sell UI SHALL render quantity controls using a compact spinner composition for both privileged and non-privileged cart rows, with semantic directional styling for decrease and increase actions.

#### Scenario: Non-serial row renders compact spinner structure
- **WHEN** a non-serial cart row is rendered
- **THEN** the qty cell MUST render controls in compact order `[- or supervised slot][qty input][+]` with minimal inter-control spacing.

#### Scenario: Serial-required row renders compact spinner as top row
- **WHEN** a serial-required cart row is rendered
- **THEN** the qty cell MUST render the same compact spinner structure as the top row before serial-specific controls.

#### Scenario: Spinner action colors follow directional semantics
- **WHEN** the qty spinner renders idle action controls
- **THEN** the decrease control MUST use danger-outline styling and the increase control MUST use primary-outline styling while preserving existing button radius and size conventions.

### Requirement: Supervised Qty Slot MUST Preserve Existing Approval Semantics Under Compact Layout
For users without direct quantity-reduction permission, compact spinner rendering MUST NOT alter existing supervised approval slot behavior. The quantity-reduce slot MUST reflect only QTY_REDUCE approval requests for that line; approval requests of other action types (such as PRICE_OVERRIDE) for the same line MUST NOT change the quantity-reduce slot state.

#### Scenario: Pending supervised request keeps Periksa state in left slot
- **WHEN** a non-privileged row has a pending qty-reduction request
- **THEN** the left spinner slot MUST render `Periksa` bound to the active request while qty input and plus control remain aligned in the same compact row.

#### Scenario: Approved supervised request keeps proceed state in left slot
- **WHEN** a non-privileged row has an approved qty-reduction request
- **THEN** the left spinner slot MUST render approved proceed state with token/approved-qty context without changing the compact row order.

#### Scenario: Pending price override does not alter the quantity-reduce slot
- **WHEN** a non-privileged row has a pending or approved PRICE_OVERRIDE request but no QTY_REDUCE request
- **THEN** the quantity-reduce slot MUST render its normal reduce (−) control
- **AND** the quantity-reduce slot MUST NOT render `Periksa` or an approved proceed state

#### Scenario: Independent quantity and price approval states coexist on one line
- **WHEN** a non-privileged row has both a pending QTY_REDUCE request and a pending PRICE_OVERRIDE request
- **THEN** the quantity-reduce slot MUST reflect only the QTY_REDUCE request state
- **AND** the price control MUST reflect only the PRICE_OVERRIDE request state

### Requirement: Restricted Cart Mutations MUST Require Supervisory Approval For Non-Authorized Users
The POS system SHALL require supervisory approval before executing `clear cart`, `remove line`, or `reduce quantity` actions when the acting user lacks direct permission for the action, UNLESS the user has Super Admin role.

#### Scenario: Non-authorized user requests clear cart
- **WHEN** a Floor Staff or Cashier Staff user attempts to clear the cart without direct clear permission
- **THEN** the system MUST create an approval request and MUST NOT clear the cart immediately

#### Scenario: Non-authorized user requests line removal
- **WHEN** a Floor Staff or Cashier Staff user attempts to remove an item line without direct remove permission
- **THEN** the system MUST create an approval request and MUST NOT remove the line immediately

#### Scenario: Non-authorized user requests quantity reduction
- **WHEN** a Floor Staff or Cashier Staff user submits a lower quantity than current quantity without direct reduce permission
- **THEN** the system MUST create an approval request and MUST NOT apply the reduced quantity immediately

#### Scenario: Authorized manager executes directly
- **WHEN** a Store Manager user performs clear cart, remove line, or reduce quantity with required direct permission
- **THEN** the system MUST execute the action immediately without creating approval request

#### Scenario: Super Admin executes cart mutations directly without approval
- **WHEN** a Super Admin user performs clear cart, remove line, or reduce quantity
- **THEN** the system MUST execute the action immediately without creating an approval request
- **AND** the authorization MUST succeed based solely on Super Admin role

#### Scenario: Super Admin with Super Admin role does not require pos.cart.line.reduce permission
- **WHEN** a Super Admin user reduces quantity despite the role lacking the explicit `pos.cart.line.reduce` permission
- **THEN** the system MUST execute the quantity reduction immediately
- **AND** the user MUST NOT be prompted for supervisory approval

### Requirement: Approval Request State MUST Be Deterministic And Queryable
The system SHALL expose deterministic approval states so users can explicitly check whether a submitted request is still pending, approved, or rejected.

#### Scenario: Pending request remains actionable for re-check
- **WHEN** a user checks approval status for a request that is still pending
- **THEN** the response MUST return `pending`, MUST NOT return execution token, and MUST allow user to check again later

#### Scenario: Approved request issues execution token
- **WHEN** a supervisor approves a pending request and the requester checks status
- **THEN** the response MUST return `approved` and MUST include a one-time execution token for the requested action

#### Scenario: Rejected request closes without mutation
- **WHEN** a supervisor rejects a pending request and the requester checks status
- **THEN** the response MUST return `rejected` and MUST keep cart state unchanged

### Requirement: Approved Restricted Actions MUST Require Explicit Final Confirmation
The POS system SHALL require explicit user confirmation after approval before mutating the cart.

#### Scenario: User confirms approved action
- **WHEN** the request status is approved and the requester chooses `Lanjutkan`
- **THEN** the system MUST consume the execution token exactly once and MUST apply the approved mutation

#### Scenario: User cancels approved action
- **WHEN** the request status is approved and the requester chooses `Batalkan`
- **THEN** the system MUST NOT apply the mutation and MUST leave cart state unchanged

#### Scenario: Token replay is rejected
- **WHEN** a consumed execution token is reused
- **THEN** the system MUST reject the request and MUST NOT apply any cart mutation

### Requirement: Price Override MUST Follow Role-Aware Supervisory Governance
The POS system SHALL allow direct price override only for authorized manager-level users and SHALL require approval workflow for non-authorized users. The system SHALL accept zero as a valid override price.

#### Scenario: Non-authorized user requests price change
- **WHEN** a Floor Staff or Cashier Staff user attempts to alter sales price without direct override permission
- **THEN** the system MUST create approval request and MUST NOT apply new price until approval is confirmed and executed

#### Scenario: Authorized manager overrides price directly
- **WHEN** a Store Manager user with price override permission updates item sales price
- **THEN** the system MUST apply the new price immediately and MUST record audit metadata for actor and timestamp

#### Scenario: User sets price to zero
- **WHEN** a user submits a price override with unit_price equal to 0
- **THEN** the system MUST accept the value as valid and process it through the normal authorization flow
- **AND** the system MUST NOT reject the request with a validation error

#### Scenario: User submits negative price
- **WHEN** a user submits a price override with unit_price less than 0
- **THEN** the system MUST reject the request with a validation error

### Requirement: Supervisory Queue MUST Resolve Pending Requests
Users with supervisory approval permission SHALL be able to review, approve, and reject pending POS approval requests.

#### Scenario: Supervisor approves request
- **WHEN** a supervisor approves a pending request from queue
- **THEN** the request status MUST become approved and MUST be available for requester status check

#### Scenario: Supervisor rejects request
- **WHEN** a supervisor rejects a pending request from queue
- **THEN** the request status MUST become rejected and MUST prevent the requested mutation from executing

### Requirement: Authorized Users MUST Be Able To Clear Cart Regardless Of Transaction State
The POS system SHALL allow users with Super Admin authority or users with direct `pos.cart.clear` permission to execute the `clear cart` action even when an active transaction is loaded. This action SHALL unload the transaction (reverting status to DRAFT) while emptying the cart session.

#### Scenario: Super Admin clears cart with loaded transaction
- **WHEN** a Super Admin user attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST unload the transaction (status reverts to DRAFT), MUST clear the cart immediately, and MUST NOT return a `TRANSACTION_EMPTY_BLOCKED` error

#### Scenario: Authorized user clears cart with loaded transaction
- **WHEN** a user with direct `pos.cart.clear` permission attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST unload the transaction (status reverts to DRAFT), MUST clear the cart immediately, and MUST NOT return a `TRANSACTION_EMPTY_BLOCKED` error

#### Scenario: Non-authorized user is still blocked from clearing loaded transaction
- **WHEN** a user without `pos.cart.clear` permission attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST return a `TRANSACTION_EMPTY_BLOCKED` error and MUST NOT unload the transaction or clear the cart

### Requirement: Cart Clear Action UI MUST Maintain Label Consistency
The POS UI SHALL ensure that the "Kosongkan Keranjang" button maintains its intended label during and after action cycles, correctly resetting to its original text upon failure or completion.

#### Scenario: Cart clear action finishes or fails
- **WHEN** the cart clear action is triggered and subsequently completes or encounters an error
- **THEN** the system MUST restore the button label to "Kosongkan Keranjang"

### Requirement: Cart Snapshot MUST Expose Requested Unit Price From PRICE_OVERRIDE Approval Payloads
The cart snapshot builder SHALL extract `unit_price` from PRICE_OVERRIDE approval request payloads and expose it as `requested_unit_price` in the line's `pending_approvals` array.

#### Scenario: PRICE_OVERRIDE approval includes requested_unit_price in snapshot
- **WHEN** a cart line has a pending or approved PRICE_OVERRIDE approval request with `unit_price` in its request payload
- **THEN** the snapshot's `pending_approvals` entry for that line MUST include `requested_unit_price` with the value from the request payload

#### Scenario: Non-PRICE_OVERRIDE approvals remain unchanged
- **WHEN** a cart line has a pending QTY_REDUCE approval request
- **THEN** the snapshot's `pending_approvals` entry MUST include `requested_qty` as before
- **AND** MUST NOT include `requested_unit_price`

### Requirement: Finish-as-debt checkout MUST require supervisory approval for non-authorized users
The POS system SHALL treat finishing a transaction as debt as a supervised action that follows the same request → approve → token-consume flow as restricted cart mutations. When the acting user lacks direct permission for the action, the system MUST create an approval request and MUST NOT post the debt sale, UNLESS the user has Super Admin role.

#### Scenario: Non-authorized user requests debt checkout
- **WHEN** a Cashier Staff user attempts finish-as-debt without direct debt-checkout permission
- **THEN** the system MUST create an approval request of the debt-checkout action type and MUST NOT post the sale immediately

#### Scenario: Authorized user completes debt checkout directly
- **WHEN** a user holding the direct debt-checkout permission completes finish-as-debt
- **THEN** the system MUST post the debt sale immediately without creating an approval request

#### Scenario: Super Admin completes debt checkout without approval
- **WHEN** a Super Admin user completes finish-as-debt
- **THEN** the system MUST post the debt sale immediately based solely on Super Admin role, without creating an approval request

#### Scenario: Approved debt request issues execution token consumed at finalize
- **WHEN** a supervisor approves a pending debt-checkout request and the requester finalizes the debt checkout with the issued token
- **THEN** the system MUST validate and consume the one-time token for the debt-checkout action before posting the sale, and MUST reject finalize if the token is missing, expired, or for a different action

### Requirement: Cart-total overrides MUST follow role-aware supervisory governance
The POS system SHALL allow a user with direct `pos.overrides.total-price` permission to apply a cart-total override immediately and SHALL require the existing supervisory request, approval, one-time-token, and final-confirmation flow for a user who lacks that permission, unless the user has the Super Admin role.

#### Scenario: Non-authorized user requests a cart-total change
- **WHEN** a cashier without `pos.overrides.total-price` submits a valid target total
- **THEN** the system MUST create a `TOTAL_PRICE_OVERRIDE` approval request
- **AND** MUST NOT alter cart totals or row pricing until approval is confirmed and executed

#### Scenario: Authorized user applies a cart-total change
- **WHEN** a user with `pos.overrides.total-price` submits a valid target total
- **THEN** the system MUST apply the cart-total override without creating an approval request
- **AND** MUST record the direct action in the POS approval audit trail

#### Scenario: Supervisor approves a cart-total change
- **WHEN** a supervisor with `pos.supervisor.approval` and `pos.overrides.total-price` approves a pending `TOTAL_PRICE_OVERRIDE` request
- **THEN** the system MUST issue a one-time execution token for that request
- **AND** the requester MUST explicitly confirm before the cart is altered

### Requirement: Cart-total approval state SHALL be exposed independently of line actions
The cart snapshot SHALL expose pending or approved `TOTAL_PRICE_OVERRIDE` requests as cart-level approval state and MUST NOT attach that state to individual cart rows.

#### Scenario: Cart-total request coexists with a line-price request
- **WHEN** a cart has a pending total-price override and a pending line `PRICE_OVERRIDE`
- **THEN** the cart-level total-price control MUST reflect only the total-price request state
- **AND** the line price control MUST reflect only the line-price request state

