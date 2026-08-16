## ADDED Requirements

### Requirement: Both row overrides MUST follow role-aware supervisory governance
The POS system SHALL apply a valid `LINE_UNIT_PRICE_OVERRIDE` or `LINE_TOTAL_OVERRIDE` immediately for a user with the supported direct permission, and SHALL require the existing request, supervisor decision, one-time token, and explicit cashier confirmation flow for a user without that permission, unless the user has the Super Admin role.

#### Scenario: Restricted cashier requests a unit-price change
- **WHEN** a cashier without direct override permission submits a valid unit price for a selected row
- **THEN** the system MUST create a line-scoped `LINE_UNIT_PRICE_OVERRIDE` approval request
- **AND** MUST NOT alter the row until approval is confirmed and executed

#### Scenario: Restricted cashier requests a row-total change
- **WHEN** a cashier without direct override permission submits a valid total for a selected row
- **THEN** the system MUST create a line-scoped `LINE_TOTAL_OVERRIDE` approval request
- **AND** MUST NOT alter the row until approval is confirmed and executed

#### Scenario: Direct permission holder changes either value
- **WHEN** a user with direct override permission submits a valid unit price or a valid row total
- **THEN** the system MUST apply the value without creating an approval request
- **AND** MUST record the direct action in the POS approval audit trail

#### Scenario: Super Admin changes either value
- **WHEN** a Super Admin submits a valid unit price or row total without the explicit direct permission
- **THEN** the system MUST apply the value immediately based on Super Admin role
- **AND** MUST NOT require supervisory approval

### Requirement: Approval requests MUST state which value they change
Each approval request SHALL identify unambiguously whether it changes the row unit price or the row total, and supervisor review SHALL display the authoritative source value, the requested value, the delta, the product and row identity, the requester, and the reason.

#### Scenario: Supervisor review distinguishes the two actions
- **WHEN** a supervisor reviews a pending override request
- **THEN** the queue MUST label a `LINE_UNIT_PRICE_OVERRIDE` request's values as unit prices
- **AND** MUST label a `LINE_TOTAL_OVERRIDE` request's values as row totals
- **AND** MUST display the source value, requested value, delta, product and row, requester, and reason

#### Scenario: Delta compares like with like
- **WHEN** a request is created for a row carrying a row discount
- **THEN** a `LINE_UNIT_PRICE_OVERRIDE` delta MUST compare the current unit price against the requested unit price
- **AND** a `LINE_TOTAL_OVERRIDE` delta MUST compare the current final row total against the requested row total

### Requirement: The current row total MUST be derived through the canonical totals calculator
The source final row total used for approval creation, delta display, and audit SHALL be derived through the canonical POS totals calculator using current discounts, taxes, quantity, customer, and cart context. It MUST NOT be computed as quantity multiplied by unit price when a row discount exists. Monetary values MUST be stored in minor units, and one shared server-side builder MUST produce approval payloads for both creation and execution.

#### Scenario: Discounted row reports a correct source total
- **WHEN** an override request is created for a row that carries a row discount
- **THEN** the recorded source row total MUST equal the canonical post-discount row total
- **AND** MUST NOT equal quantity multiplied by unit price

### Requirement: Approval tokens MUST be action-specific and single-use
A one-time token SHALL authorize only the exact action type it was issued for, for the same requester, POS session, target type, and target line. A unit-price token MUST NOT authorize a row-total change, and a row-total token MUST NOT authorize a unit-price change.

#### Scenario: Cross-action token is rejected
- **WHEN** a token issued for `LINE_UNIT_PRICE_OVERRIDE` is submitted to the row-total endpoint
- **THEN** the system MUST reject the request
- **AND** MUST NOT change the cart, consume the token, or record a successful audit

#### Scenario: Replay after success fails
- **WHEN** a token is submitted again after it has successfully executed
- **THEN** the system MUST reject the request
- **AND** MUST NOT change the cart a second time

#### Scenario: Exactly one concurrent consumption succeeds
- **WHEN** two requests attempt to consume the same token concurrently
- **THEN** exactly one MUST succeed
- **AND** the other MUST fail without changing the cart or recording a successful audit

### Requirement: Supervised execution MUST compare the submitted value against the approved value exactly
Before applying a supervised override the system SHALL compare the submitted requested value against the approved value in minor units. A submitted unit price MUST exactly equal the approved `requested_unit_price_minor`, and a submitted row total MUST exactly equal the approved `requested_total_minor`. The system MUST NOT silently replace an incorrect submitted value with the approved value.

#### Scenario: Mismatched requested value is rejected without consumption
- **WHEN** a requester confirms an approved override but submits a value different from the approved value
- **THEN** the system MUST reject the request
- **AND** MUST NOT change the cart, consume the token, or record a successful audit
- **AND** the token MUST remain usable for a correct subsequent request

#### Scenario: Execution validates full approval context
- **WHEN** a supervised override is executed
- **THEN** the system MUST validate the exact active action type, a target type of `pos_cart_line`, the POS session ID, the exact line ID, the requester ID, a non-empty fingerprint, and a fingerprint match against the current authoritative line and cart context
- **AND** MUST reject the request when any of these does not match

#### Scenario: Approval resolves only the exact line
- **WHEN** an approved override is executed against a target that is not an exact cart line ID
- **THEN** the system MUST reject the request
- **AND** MUST NOT resolve the target through a product ID fallback

### Requirement: Failed supervised execution MUST leave no partial effect
Failed validation, failed calculation, and failed cart persistence MUST NOT change the cart, consume a token, or record a successful audit. Failed token or audit persistence MUST NOT leave the cart override applied.

#### Scenario: Validation failure leaves everything unchanged
- **WHEN** validation fails during supervised execution
- **THEN** the cart MUST remain unchanged
- **AND** the token MUST remain unconsumed
- **AND** no successful audit record MUST exist

#### Scenario: Cart persistence failure leaves the token usable
- **WHEN** persisting the cart fails during supervised execution
- **THEN** the token MUST remain unconsumed
- **AND** no successful audit record MUST exist

#### Scenario: Token or audit failure restores the cart and rolls back
- **WHEN** token consumption or audit persistence fails after the cart has been persisted
- **THEN** the token and request database transaction MUST roll back
- **AND** the system MUST restore the exact pre-operation business content of the cart before returning failure
- **AND** the restoration MUST occur while the cart mutation lock is still held

#### Scenario: Restoration runs even when rollback fails
- **WHEN** the database rollback itself fails during unwinding of a failed override
- **THEN** the system MUST still attempt to restore the cart
- **AND** MUST NOT leave the override applied because rollback failed

#### Scenario: Unconfirmed restoration is escalated
- **WHEN** the cart cannot be restored and verified after a failed override
- **THEN** the system MUST log a critical structured error identifying the setting, POS session, action, and both failures
- **AND** MUST raise a distinct compensation-failed error rather than reporting success
- **AND** the original failure MUST remain attached as the root cause

#### Scenario: Restoration advances the generation rather than rewinding it
- **WHEN** the cart is restored during compensation
- **THEN** the restored cart MUST carry the original business content
- **AND** the monotonic generation MUST advance rather than return to its earlier value

#### Scenario: Successful execution reports the persisted cart
- **WHEN** an override completes successfully
- **THEN** the audit payload and the returned cart MUST reflect the stored cart including its persisted revision
- **AND** MUST NOT carry the pre-persistence revision

#### Scenario: Retry succeeds after a rejected attempt
- **WHEN** an attempt is rejected because of incorrect context and the requester retries with correct context
- **THEN** the retry MUST succeed
- **AND** MUST consume the token exactly once

### Requirement: Every POS cart writer MUST serialize through a cart mutation lock
Every operation that persists, clears, replaces, or hydrates a POS cart SHALL acquire a cart mutation lock keyed by setting and POS session before reading or writing that cart. The guard set is defined by whether the operation writes the cart, not by whether the write is considered relevant to approval validity, because exact snapshot compensation would otherwise erase an unrelated concurrent write.

The guarded operations MUST include unit-price and row-total overrides, quantity changes, line removal, serial assignment, customer changes, discount changes, note updates, staged-payment state changes that write the cart, cart clear including checkout clear, and transaction load, hydrate, and reset operations.

#### Scenario: Competing mutation cannot enter during an override
- **WHEN** an override is executing while another request attempts a quantity, customer, serial, removal, discount, note, or clear mutation on the same session cart
- **THEN** the competing mutation MUST wait for the lock rather than modifying the cart concurrently

#### Scenario: Unrelated concurrent write is preserved across compensation
- **WHEN** a note update is attempted on the same cart while an override is executing, and the override then fails after persistence and compensates
- **THEN** the note update MUST wait for the override's lock
- **AND** the note update MUST be applied after the lock is released
- **AND** the note update MUST NOT be erased by the compensation snapshot restore

#### Scenario: Lock acquisition timeout is retryable and inert
- **WHEN** a cart mutation cannot acquire the lock within its bounded timeout
- **THEN** the system MUST return a retryable POS error
- **AND** MUST NOT change the cart, consume a token, or record a successful audit

#### Scenario: Lock is always released
- **WHEN** a guarded cart mutation completes, fails, or throws
- **THEN** the cart mutation lock MUST be released

#### Scenario: Lock ownership outlives the guarded operation
- **WHEN** a guarded cart mutation runs longer than a short lock lease
- **THEN** the holder MUST retain exclusive ownership for the whole operation
- **AND** no competing writer MUST be able to acquire the lock while the operation is still running

#### Scenario: Re-entrance spans collaborating services in one scope
- **WHEN** one service holds the lock for a cart and calls another service that guards the same cart within the same request or job
- **THEN** the nested call MUST proceed without self-deadlock
- **AND** releasing the nested scope MUST NOT release the outer holder's lock

#### Scenario: A fresh execution scope cannot inherit ownership
- **WHEN** a new request or job begins in a process where an earlier scope held a cart lock
- **THEN** the new scope MUST NOT bypass acquisition
- **AND** it MUST receive the retryable busy error while the lock is genuinely held elsewhere

#### Scenario: Owning one cart grants no bypass for another
- **WHEN** a scope holds the lock for one cart and attempts to guard a different cart that is locked elsewhere
- **THEN** the attempt MUST NOT bypass acquisition

### Requirement: Checkout MUST hold the cart mutation lock across its authoritative span
Checkout SHALL hold the cart mutation lock from authoritative snapshot capture through posting and cart clearing, so the cart it posts cannot change underneath it. Concurrent cart mutation SHALL receive the retryable busy error rather than modifying the cart being posted.

#### Scenario: Concurrent mutation during checkout is rejected
- **WHEN** a cashier attempts any cart mutation while a checkout is posting that cart
- **THEN** the mutation MUST receive the retryable busy error
- **AND** MUST NOT modify the cart being posted

#### Scenario: Checkout clears exactly what it posted
- **WHEN** checkout completes posting and clears the cart
- **THEN** the cleared cart MUST be the same cart that was posted
- **AND** no partially mutated cart MUST survive containing already-posted lines

### Requirement: The cart revision MUST be monotonic across clearing
The cart SHALL expose a revision advanced by every write, and that counter SHALL survive cart clearing. A cart created after a clear MUST NOT reuse a revision previously issued for the same setting and POS session.

#### Scenario: A recreated cart never reuses an earlier revision
- **WHEN** a cart is cleared and a new cart is then created for the same setting and session
- **THEN** the new cart's revision MUST be strictly greater than any revision previously issued

#### Scenario: A stale revision cannot match a cart created after a clear
- **WHEN** an operation holding a revision captured before a clear attempts a compare-and-set against a cart created after that clear
- **THEN** the comparison MUST NOT match
- **AND** the newly created cart MUST NOT be cleared

### Requirement: Execution MUST hold the cart mutation lock across the persistence boundary
One execution coordinator SHALL serve both direct and supervised paths and both active action types. The cart mutation lock SHALL remain held from before the cart is read until either successful commit or completed compensation, so compensation restores the exact pre-operation cart rather than a partial field revert.

#### Scenario: Direct execution compensates on audit failure
- **WHEN** direct execution persists the cart and the direct audit write then fails
- **THEN** the system MUST restore the exact pre-operation cart before releasing the lock
- **AND** MUST NOT leave a changed cart without its required audit

#### Scenario: Lock is held through compensation
- **WHEN** compensation runs after a post-persistence failure
- **THEN** the cart mutation lock MUST still be held during restoration
- **AND** the lock MUST NOT be released until the cart has been restored

### Requirement: Successful mutation MUST precede successful audit
A successful-execution audit record SHALL NOT be created or updated before the cart mutation and the approval consumption have both completed successfully. Both direct and supervised execution SHALL record the exact active action type, session and line ID, source and requested values in minor units, reason, fingerprint, requester, direct authorizer or supervisor, execution timestamp, and successful result.

#### Scenario: Audit reflects only successful mutations
- **WHEN** an override attempt fails at any step
- **THEN** no successful-execution audit record MUST be written for that attempt

### Requirement: Override approval state SHALL remain isolated by line and action
The cart snapshot SHALL expose pending or approved override requests only in the target line's approval collection, keyed by both line and action type. Quantity, removal, and other line-action controls MUST ignore override request state.

#### Scenario: Quantity and override approvals coexist
- **WHEN** one row has both a quantity-reduction request and an override request
- **THEN** each control MUST display only its own approval state
- **AND** the requests MUST retain distinct IDs and execution tokens

#### Scenario: Both override actions coexist on one row
- **WHEN** one row has both a pending unit-price request and a pending row-total request
- **THEN** each control MUST display only its own approval state
- **AND** the two requests MUST retain distinct IDs and execution tokens

#### Scenario: Request on another row remains isolated
- **WHEN** one row has a pending override request
- **THEN** no other row's controls MUST display that request
- **AND** the cart-level summary MUST NOT display it as cart-wide approval state

### Requirement: Relevant mutation MUST invalidate pending approvals for both active actions
Any relevant line or cart mutation SHALL invalidate pending or approved-but-unconsumed requests for both `LINE_UNIT_PRICE_OVERRIDE` and `LINE_TOTAL_OVERRIDE`.

#### Scenario: Mutation invalidates both action types
- **WHEN** a relevant change is made to a row that has pending requests for both override actions
- **THEN** both requests MUST be invalidated
- **AND** neither token MUST alter the changed row

### Requirement: Retired override actions MUST NOT authorize new operations
The legacy `PRICE_OVERRIDE` and `TOTAL_PRICE_OVERRIDE` action types SHALL NOT be created for new requests and MUST NOT authorize any new operation. Historical records of those types MUST remain readable and MUST render read-only.

#### Scenario: Legacy action cannot authorize a mutation
- **WHEN** a token or request carrying a legacy `PRICE_OVERRIDE` or `TOTAL_PRICE_OVERRIDE` action type is submitted to an active override endpoint
- **THEN** the system MUST reject it
- **AND** MUST NOT change the cart

#### Scenario: Historical records remain readable
- **WHEN** approval history containing legacy action types is displayed
- **THEN** the records MUST render without error
- **AND** MUST NOT be presented as actionable cart state

## REMOVED Requirements

### Requirement: Price Override MUST Follow Role-Aware Supervisory Governance
**Reason**: The ambiguous `PRICE_OVERRIDE` action is retired for new requests and replaced by two explicit action types under the same supervised lifecycle.

**Migration**: Use `LINE_UNIT_PRICE_OVERRIDE` and `LINE_TOTAL_OVERRIDE` with the established lifecycle; preserve historical `PRICE_OVERRIDE` records for audit.

### Requirement: Cart Snapshot MUST Expose Requested Unit Price From PRICE_OVERRIDE Approval Payloads
**Reason**: Active approval payloads are keyed by two explicit action types rather than the ambiguous legacy type.

**Migration**: Expose `requested_unit_price` for `LINE_UNIT_PRICE_OVERRIDE` and `requested_line_total` for `LINE_TOTAL_OVERRIDE`, while retaining tolerant historical rendering for old records.

### Requirement: Cart-total overrides MUST follow role-aware supervisory governance
**Reason**: New cart-wide total mutations and approvals are retired.

**Migration**: Use line-scoped row overrides; retain historical `TOTAL_PRICE_OVERRIDE` decisions as read-only audit data.

### Requirement: Cart-total approval state SHALL be exposed independently of line actions
**Reason**: There is no supported cart-total action after this change.

**Migration**: Display new override approval state only on its target row, keyed by line and action, and omit cart-level total approval state from active cart snapshots.
