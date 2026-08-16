## ADDED Requirements

### Requirement: POS SHALL support an authoritative total for one selected cart row
The POS system SHALL allow a user to submit a non-negative final total for one mutable cart row through the `LINE_TOTAL_OVERRIDE` action. The submitted total MUST change only that row, MUST remain authoritative to two monetary decimals, MUST set an explicit pricing source of `LINE_TOTAL_OVERRIDE`, and MUST NOT be recomputed from a rounded displayed unit price during the same edit.

#### Scenario: Non-divisible row total remains exact
- **WHEN** a user submits Rp10.000 as the total of a row with quantity 3
- **THEN** that row's authoritative total MUST remain Rp10.000
- **AND** the displayed effective unit price MUST be a rounded value derived from the authoritative total
- **AND** no other row total MUST change

#### Scenario: Zero row total is accepted
- **WHEN** a user submits zero as the total of a mutable row
- **THEN** the system MUST accept it through the applicable authorization flow
- **AND** the selected row total MUST become zero

#### Scenario: Invalid row total is rejected
- **WHEN** a user submits a blank, nonnumeric, or negative row total
- **THEN** the system MUST reject the request
- **AND** all cart monetary values MUST remain unchanged

### Requirement: The requested row total SHALL be authoritative after row discount and before bill-level adjustment
The requested total SHALL represent the final row amount after any row discount and before bill-level adjustments. The system MUST reverse-derive row gross and row discount metadata from that total in minor units so the requested net is reproduced exactly, and MUST apply bill-level discounts only after the row's authoritative net.

#### Scenario: Fixed discount reverses exactly
- **WHEN** a row carrying a fixed row discount of Rp1.000 receives a requested total of Rp10.000
- **THEN** the derived row gross MUST be Rp11.000
- **AND** the recorded row discount MUST be Rp1.000
- **AND** the final row net MUST be exactly Rp10.000

#### Scenario: Percentage discount reverses exactly
- **WHEN** a row carrying a 10% row discount receives a requested total of Rp10.000
- **THEN** the derived row gross MUST be Rp11.111
- **AND** the recorded row discount MUST be Rp1.111
- **AND** the final row net MUST be exactly Rp10.000

#### Scenario: Out-of-range percentage discount is rejected
- **WHEN** a row-total override is attempted on a row whose percentage discount is negative or is greater than or equal to 100
- **THEN** the system MUST reject the request
- **AND** MUST NOT change the cart, consume a token, or record a successful audit

#### Scenario: Bill discount applies after the row net
- **WHEN** a cart carrying a bill-level discount contains a row with an authoritative overridden total
- **THEN** the row's authoritative net MUST be used as the bill-discount base
- **AND** the bill discount MUST be applied after that net rather than before it

### Requirement: POS row-total derivation SHALL follow established Sales and Purchase semantics
The requested POS row total SHALL be the final tax-inclusive row amount. The system MUST derive effective unit price, pre-tax amount, and tax from that total using the existing monetary precision rules in minor units, without floating-point drift, regardless of whether the active tax presentation is tax-included or tax-exclusive.

#### Scenario: PKP row reconciles exactly
- **WHEN** a taxable PKP row receives an approved total that does not divide evenly by quantity
- **THEN** the row total MUST equal the approved amount exactly
- **AND** the row pre-tax amount plus tax MUST equal that total exactly

#### Scenario: Non-PKP row carries no tax
- **WHEN** a non-PKP row receives an approved total
- **THEN** its pre-tax amount MUST equal its authoritative row total
- **AND** its tax amount MUST equal zero

#### Scenario: Derived unit price does not overwrite the authoritative total
- **WHEN** a row's derived effective unit price is rounded for display
- **THEN** the authoritative final row total MUST remain unchanged by that rounding
- **AND** no consumer MUST recompute the row total as rounded unit price multiplied by quantity

### Requirement: Persisted override metadata SHALL NOT outlive the row state it describes
Canonical derived metadata persisted by a row override SHALL be refreshed or removed whenever the row's quantity, row discount, applicable tax context, or pricing source changes. A unit-price override SHALL retain its authoritative price and source and have its metadata recomputed; a row-total override SHALL revert to resolved standard pricing with every canonical field removed. All canonical fields MUST be cleared together.

#### Scenario: Unit-price override metadata follows a quantity change
- **WHEN** a row carrying an applied unit-price override has its quantity changed
- **THEN** the row MUST retain the authoritative unit price and its pricing source
- **AND** the persisted derived metadata MUST reflect the new quantity

#### Scenario: Unit-price override metadata follows a discount change
- **WHEN** a row carrying an applied unit-price override has its row discount changed
- **THEN** the persisted derived metadata MUST reflect the new discount
- **AND** the discount MUST be applied exactly once

#### Scenario: Row-total override is invalidated by a row change
- **WHEN** a row carrying an applied row-total override has its quantity or discount changed
- **THEN** the row MUST revert to resolved standard pricing
- **AND** every canonical derived field MUST be removed

#### Scenario: Invalidation restores each line kind to its own standard price
- **WHEN** a row-total override is invalidated on a bundle-parent row
- **THEN** the row MUST be restored to the selected bundle's authoritative sale price under a bundle pricing source
- **AND** its bundle identity, component snapshots, and informational allocations MUST remain unchanged

#### Scenario: Invalidation re-runs packed pricing for a packed row
- **WHEN** a row-total override is invalidated on a packed row
- **THEN** packed pricing MUST be re-run for the current quantity and customer tier
- **AND** the row MUST retain its packed pricing source

#### Scenario: Invalidation restores tier and ordinary rows to their resolved price
- **WHEN** a row-total override is invalidated on a customer-tier row or an ordinary row
- **THEN** the row MUST be restored to its resolved standard price under the corresponding pricing source

#### Scenario: Repricing away from an override removes its metadata
- **WHEN** customer repricing replaces an applied override with a standard pricing source
- **THEN** every canonical derived field MUST be removed

#### Scenario: Merging rows discards pre-merge metadata
- **WHEN** two rows are merged and their quantities are summed
- **THEN** the merged row MUST NOT retain canonical metadata computed for a pre-merge quantity

#### Scenario: Snapshots and drafts carry no stale metadata
- **WHEN** a cart snapshot or draft is produced after a row with an applied override has changed
- **THEN** it MUST NOT report a total derived from the superseded row state

### Requirement: Row-total override SHALL target the billable row only
The row-total action SHALL be available on ordinary, packed, and billable bundle-parent rows. For a bundle, it MUST target the billable bundle-parent row, and linked non-billable component prices and subtotals MUST remain zero.

#### Scenario: Bundle components remain non-billable
- **WHEN** a bundle parent row receives an approved total
- **THEN** only the billable parent row total MUST carry that amount
- **AND** component commercial price and subtotal MUST remain zero
- **AND** component informational allocations and fulfilment snapshots MUST remain unchanged

#### Scenario: Bundle parent residual remains exact
- **WHEN** a bundle-parent row with informational component allocations receives an approved total
- **THEN** the billable parent residual MUST reconcile exactly to the authoritative row total in minor units

### Requirement: Row-total override SHALL reconcile through checkout and owner splitting
An authoritative overridden row total SHALL be used by cart totals, payment validation, receipt display, tax calculation, draft snapshots, idempotency hashes, and checkout posting. When fulfilment splits one row across owners, its amount MUST be allocated deterministically so the generated owner allocations sum exactly to the authoritative row total.

#### Scenario: Split-owner row remains exact
- **WHEN** one overridden row is fulfilled from two or more stock owners
- **THEN** each generated Sales allocation MUST receive a deterministic share of that row total
- **AND** the shares MUST sum exactly to the authoritative POS row total
- **AND** allocation tax classification MUST continue to follow each source owner setting

#### Scenario: Bundle parent override remains commercial authority
- **WHEN** a billable bundle-parent row receives an approved total and is finalized
- **THEN** checkout MUST post that total through the bundle parent commercial allocations
- **AND** bundle components MUST remain non-billable

#### Scenario: Idempotent replay preserves row total
- **WHEN** finalize is retried with the same authoritative overridden row snapshot
- **THEN** the existing idempotency behavior MUST return the same posting outcome
- **AND** MUST NOT recompute the total from the displayed effective unit price

### Requirement: Row-total override SHALL be line-bound, auditable, and stale-safe
The system SHALL record the source total, requested total, row fingerprint, reason, requester, approver, and executor. Pending or approved-but-unconsumed approval MUST be invalidated when the target row's relevant state changes, and its token MUST be valid only for that requester, action, session, and line.

#### Scenario: Successful override is audited in minor units
- **WHEN** a row-total override completes successfully
- **THEN** the audit record MUST carry action type `LINE_TOTAL_OVERRIDE`
- **AND** MUST carry the session ID, line ID, source total in minor units, requested total in minor units, reason, fingerprint, requester, authorizer, and execution timestamp

#### Scenario: Relevant row mutation invalidates approval
- **WHEN** product, quantity, bundle composition, serial assignment, customer tier, tax context, discount, conversion, or price changes after a row-total request is created
- **THEN** the system MUST invalidate that unconsumed request
- **AND** its token MUST NOT alter the changed row

#### Scenario: Unrelated row does not consume approval
- **WHEN** an approved token for one line is submitted against another line
- **THEN** the system MUST reject the token
- **AND** neither line MUST change

#### Scenario: Approved unchanged request executes once
- **WHEN** the requester confirms an approved row-total request while its fingerprint still matches
- **THEN** the system MUST consume the one-time token
- **AND** apply exactly the approved requested total
- **AND** record requester, approver, executor, reason, source total, and target total
