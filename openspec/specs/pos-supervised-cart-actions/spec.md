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

### Requirement: Both row overrides MUST follow role-aware supervisory governance
The POS system SHALL apply valid `LINE_UNIT_PRICE_OVERRIDE` and `LINE_TOTAL_OVERRIDE` immediately for a user with `pos.overrides.price`, and SHALL require the existing request, supervisor decision, one-time token, and explicit confirmation flow for other users, unless the user is Super Admin.

#### Scenario: Restricted cashier requests either row override
- **WHEN** a cashier without direct override permission submits a valid unit price or row total
- **THEN** the system MUST create a line-scoped approval request and MUST NOT alter the row until approval is executed

#### Scenario: Direct or Super Admin execution bypasses approval
- **WHEN** a user with direct permission or Super Admin authority submits a valid row override
- **THEN** the system MUST apply it immediately and record the action in the approval audit trail

### Requirement: Approval requests MUST state which value they change
Each row-override request and supervisor review SHALL identify the action type, source value, requested value, delta, product and line identity, requester, and reason, comparing unit price with unit price and row total with row total.

#### Scenario: Supervisor review distinguishes both actions
- **WHEN** a supervisor reviews a pending row override
- **THEN** the queue MUST label unit prices and row totals according to the active action type

### Requirement: The current row total MUST be derived through the canonical totals calculator
The source final row total for approval, delta, and audit SHALL come from the canonical POS totals calculator using current discounts, taxes, quantity, customer, and cart context, in minor units, rather than quantity multiplied by unit price.

#### Scenario: Discounted row reports its canonical source total
- **WHEN** an override request is created for a discounted row
- **THEN** the source row total MUST equal the canonical post-discount total

### Requirement: Approval tokens MUST be action-specific and single-use
An approval token SHALL authorize only its exact action type, requester, POS session, target type, and target line; it MUST be consumed at most once.

#### Scenario: Cross-action token is rejected
- **WHEN** a unit-price token is submitted to the row-total endpoint
- **THEN** the system MUST reject it without changing the cart or consuming the token

### Requirement: Supervised execution MUST compare the submitted value against the approved value exactly
Supervised execution SHALL compare submitted unit price or row total to its approved minor-unit value and validate the exact session, line, requester, target type, and fingerprint; it MUST NOT resolve a product-ID fallback.

#### Scenario: Mismatched value remains retryable
- **WHEN** the submitted value differs from the approved value
- **THEN** the system MUST reject without mutation or token consumption and leave the token usable

### Requirement: Failed supervised execution MUST leave no partial effect
Validation, calculation, persistence, token, and audit failures MUST leave no successful audit, consumed token, or applied override; post-persistence failures MUST roll back and restore the exact pre-operation cart while the lock remains held.

#### Scenario: Persistence failure compensates safely
- **WHEN** token or audit persistence fails after cart persistence
- **THEN** the system MUST restore the pre-operation cart before releasing the lock

### Requirement: Every POS cart writer MUST serialize through a cart mutation lock
Every operation that persists, clears, replaces, or hydrates a POS cart SHALL acquire a setting-and-session keyed lock, including both overrides, all line and customer mutations, notes, staged payments, clear, checkout clear, and transaction load or reset.

#### Scenario: Competing mutation waits
- **WHEN** an override holds the cart lock and another writer targets the same cart
- **THEN** the competing writer MUST wait or receive the retryable busy error without changing the cart

### Requirement: Checkout MUST hold the cart mutation lock across its authoritative span
Checkout SHALL hold the lock from authoritative snapshot through posting and clearing so concurrent mutation cannot modify the cart being posted.

#### Scenario: Checkout clears the posted cart
- **WHEN** checkout completes posting
- **THEN** it MUST clear the same cart snapshot that was posted

### Requirement: The cart revision MUST be monotonic across clearing
Cart revisions SHALL advance across every write and survive clearing so a recreated cart cannot reuse an earlier revision or satisfy a stale compare-and-set.

#### Scenario: Recreated cart receives a new revision
- **WHEN** a cart is cleared and recreated for the same session
- **THEN** its revision MUST be greater than every previously issued revision

### Requirement: Execution MUST hold the cart mutation lock across the persistence boundary
One coordinator SHALL serve direct and supervised execution for both active actions and hold the lock through successful commit or completed compensation.

#### Scenario: Direct audit failure is compensated
- **WHEN** direct execution persists the cart but audit fails
- **THEN** the exact pre-operation cart MUST be restored before the lock is released

### Requirement: Successful mutation MUST precede successful audit
Successful audit records SHALL be written only after mutation and approval consumption succeed and SHALL carry the exact active action, session, line, values, reason, fingerprint, actors, timestamp, and result.

#### Scenario: Failed attempt has no success audit
- **WHEN** any override step fails
- **THEN** no successful-execution audit record MUST be written

### Requirement: Override approval state SHALL remain isolated by line and action
Pending and approved override requests SHALL appear only on their target line and be keyed by line and action; quantity and other controls MUST ignore them.

#### Scenario: Both active approvals coexist
- **WHEN** one line has pending unit-price and row-total requests
- **THEN** each control MUST display only its own request state

### Requirement: Relevant mutation MUST invalidate pending approvals for both active actions
Any relevant line or cart mutation SHALL invalidate pending or approved-but-unconsumed requests for both active row-override action types.

#### Scenario: Mutation invalidates both actions
- **WHEN** a relevant line change occurs with both requests pending
- **THEN** both requests MUST be invalidated and neither token may mutate the row

### Requirement: Retired override actions MUST NOT authorize new operations
Legacy `PRICE_OVERRIDE` and `TOTAL_PRICE_OVERRIDE` actions MUST NOT be created or authorize new operations, while historical records remain readable and read-only.

#### Scenario: Legacy history is non-actionable
- **WHEN** historical approval or audit records use a legacy action type
- **THEN** they MUST render without error and MUST NOT authorize mutation

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

