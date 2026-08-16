# pos-line-unit-price-override Specification

## Purpose
Define authoritative, line-scoped unit-price overrides for POS cart rows.

## Requirements
### Requirement: POS SHALL support an authoritative unit price for one selected cart row
The POS system SHALL allow a user to submit a non-negative unit price for one mutable cart row through the `LINE_UNIT_PRICE_OVERRIDE` action. The submitted unit price MUST become that row's authoritative gross unit price, MUST change only that row, and MUST set pricing source `LINE_UNIT_PRICE_OVERRIDE`.

#### Scenario: Unit price becomes authoritative
- **WHEN** a user submits Rp9.000 as the unit price of a row with quantity 4 and no discount
- **THEN** that row's authoritative unit price MUST become Rp9.000
- **AND** the row gross MUST be Rp36.000
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
After the authoritative unit price is set, the system SHALL recalculate row gross, row discount, tax, subtotal, and final row total through the canonical POS totals calculator in minor units. Existing row discounts MUST be applied exactly once and tax MUST follow existing POS inclusive-tax rules.

#### Scenario: Row discount applies once
- **WHEN** a row with a fixed row discount receives an approved unit price
- **THEN** the row gross MUST be the requested unit price multiplied by effective quantity
- **AND** the row discount MUST be subtracted exactly once

### Requirement: Unit-price override SHALL target the billable row only
The unit-price action SHALL be available on ordinary, packed, and billable bundle-parent rows. For a bundle, it MUST target the billable bundle-parent row, while linked non-billable component prices and subtotals remain zero.

#### Scenario: Bundle parent accepts unit-price override
- **WHEN** a billable bundle-parent row receives an approved unit price
- **THEN** the parent row MUST carry the authoritative unit price
- **AND** component commercial price and subtotal MUST remain zero

### Requirement: Unit-price override SHALL be line-bound, auditable, and stale-safe
The system SHALL record source unit price, requested unit price, row fingerprint, reason, requester, approver, and executor for each successful unit-price override. Pending or approved-but-unconsumed approval MUST be invalidated when the target row's relevant state changes.

#### Scenario: Successful override is audited in minor units
- **WHEN** a unit-price override completes successfully
- **THEN** the audit record MUST carry action type `LINE_UNIT_PRICE_OVERRIDE`
- **AND** it MUST carry source and requested unit prices in minor units plus the target line and fingerprint

### Requirement: Unit-price override SHALL reconcile through checkout and owner splitting
An authoritative overridden unit price SHALL be used by cart totals, payment validation, receipt display, tax calculation, draft snapshots, idempotency hashes, and checkout posting, rather than being recomputed by a later consumer.

#### Scenario: Draft round-trip preserves the override
- **WHEN** a cart containing a unit-price override is saved as a draft and reloaded
- **THEN** the reloaded row MUST carry the same authoritative unit price and pricing source
- **AND** the canonical hash MUST reflect that value