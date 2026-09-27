## ADDED Requirements

### Requirement: POS SHALL support an authoritative unit price for one selected cart row
The POS system SHALL allow a user to submit a non-negative unit price for one mutable cart row through the `LINE_UNIT_PRICE_OVERRIDE` action. The submitted unit price MUST become that row's authoritative gross unit price, MUST change only that row, and MUST set an explicit pricing source of `LINE_UNIT_PRICE_OVERRIDE`.

#### Scenario: Unit price becomes authoritative
- **WHEN** a user submits Rp9.000 as the unit price of a row with quantity 4 and no discount
- **THEN** that row's authoritative unit price MUST become Rp9.000
- **AND** the row gross MUST be Rp36.000
- **AND** the row pricing source MUST be `LINE_UNIT_PRICE_OVERRIDE`
- **AND** no other row MUST change

#### Scenario: Zero unit price is accepted
- **WHEN** a user submits zero as the unit price of a mutable row
- **THEN** the system MUST accept it through the applicable authorization flow
- **AND** the selected row unit price MUST become zero

#### Scenario: Invalid unit price is rejected
- **WHEN** a user submits a blank, nonnumeric, or negative unit price
- **THEN** the system MUST reject the request
- **AND** all cart monetary values MUST remain unchanged

### Requirement: Unit-price override SHALL recalculate the row through the canonical totals path
After the authoritative unit price is set, the system SHALL recalculate row gross, row discount, tax, subtotal, and the final row total through the canonical POS totals calculator in minor units. The existing row discount MUST be applied exactly once, and tax MUST follow the existing POS inclusive-tax rules.

#### Scenario: Row discount applies once after unit-price override
- **WHEN** a row with a fixed row discount receives an approved unit price
- **THEN** the row gross MUST be the requested unit price multiplied by effective quantity
- **AND** the row discount MUST be subtracted exactly once
- **AND** the final row total MUST be derived deterministically from that net

#### Scenario: Percentage discount applies once after unit-price override
- **WHEN** a row with a percentage row discount receives an approved unit price
- **THEN** the discount MUST be computed from the recalculated gross
- **AND** the final row total MUST equal gross minus that discount

#### Scenario: Non-PKP row carries no tax
- **WHEN** a non-PKP row receives an approved unit price
- **THEN** its tax amount MUST equal zero
- **AND** its pre-tax amount MUST equal its authoritative row total

#### Scenario: Conversion factor is honoured
- **WHEN** a row using a unit conversion receives an approved unit price
- **THEN** row gross MUST be derived from the requested unit price and the effective converted quantity
- **AND** the conversion identity MUST remain unchanged

### Requirement: Unit-price override SHALL target the billable row only
The unit-price action SHALL be available on ordinary, packed, and billable bundle-parent rows. For a bundle, it MUST target the billable bundle-parent row, and linked non-billable component prices and subtotals MUST remain zero.

#### Scenario: Bundle parent accepts unit-price override
- **WHEN** a billable bundle-parent row receives an approved unit price
- **THEN** the parent row MUST carry the authoritative unit price
- **AND** component commercial price and subtotal MUST remain zero
- **AND** component informational allocations and fulfilment snapshots MUST remain unchanged

### Requirement: Unit-price override SHALL be line-bound, auditable, and stale-safe
The system SHALL record the source unit price, requested unit price, row fingerprint, reason, requester, approver, and executor for each successful unit-price override. Pending or approved-but-unconsumed approval MUST be invalidated when the target row's relevant state changes.

#### Scenario: Successful override is audited in minor units
- **WHEN** a unit-price override completes successfully
- **THEN** the audit record MUST carry action type `LINE_UNIT_PRICE_OVERRIDE`
- **AND** MUST carry the session ID, line ID, source unit price in minor units, requested unit price in minor units, reason, fingerprint, requester, authorizer, and execution timestamp

#### Scenario: Relevant row mutation invalidates approval
- **WHEN** product, quantity, bundle composition, serial assignment, customer tier, tax context, discount, conversion, or price changes after a unit-price request is created
- **THEN** the system MUST invalidate that unconsumed request
- **AND** its token MUST NOT alter the changed row

### Requirement: Unit-price override SHALL reconcile through checkout and owner splitting
An authoritative overridden unit price SHALL be used by cart totals, payment validation, receipt display, tax calculation, draft snapshots, idempotency hashes, and checkout posting, rather than being recomputed by a later consumer.

#### Scenario: Draft round-trip preserves the override
- **WHEN** a cart containing a unit-price override is saved as a draft and reloaded
- **THEN** the reloaded row MUST carry the same authoritative unit price and pricing source
- **AND** the canonical hash MUST reflect that value

#### Scenario: Split-owner row remains exact
- **WHEN** a row with an overridden unit price is fulfilled from two or more stock owners
- **THEN** each generated Sales allocation MUST receive a deterministic share of that row total
- **AND** the shares MUST sum exactly to the authoritative POS row total
- **AND** allocation tax classification MUST continue to follow each source owner setting
