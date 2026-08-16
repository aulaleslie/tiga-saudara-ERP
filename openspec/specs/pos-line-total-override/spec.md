# pos-line-total-override Specification

## Purpose
Define authoritative, line-scoped final-total overrides for POS cart rows.

## Requirements
### Requirement: POS SHALL support an authoritative total for one selected cart row
The POS system SHALL allow a user to submit a non-negative final total for one mutable cart row through the `LINE_TOTAL_OVERRIDE` action. The submitted total MUST change only that row, remain authoritative to two monetary decimals, set pricing source `LINE_TOTAL_OVERRIDE`, and MUST NOT be recomputed from a rounded displayed unit price during the same edit.

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

### Requirement: POS row-total derivation SHALL follow established Sales and Purchase semantics
The requested POS row total SHALL be the final tax-inclusive row amount. The system MUST derive effective unit price, pre-tax amount, and tax from that total using existing monetary precision rules in minor units, without floating-point drift, for tax-included and tax-exclusive presentation.

#### Scenario: Non-PKP row carries no tax
- **WHEN** a non-PKP row receives an approved total
- **THEN** its pre-tax amount MUST equal its authoritative row total
- **AND** its tax amount MUST equal zero

### Requirement: Persisted override metadata SHALL NOT outlive the row state it describes
Canonical derived metadata persisted by a row override SHALL be refreshed or removed whenever the row's quantity, discount, tax context, or pricing source changes. A unit-price override SHALL retain its authoritative price and source with recomputed metadata; a row-total override SHALL revert to resolved standard pricing with every canonical field removed.

#### Scenario: Row-total override is invalidated by a row change
- **WHEN** a row carrying an applied row-total override has its quantity or discount changed
- **THEN** the row MUST revert to resolved standard pricing
- **AND** every canonical derived field MUST be removed

### Requirement: Row-total override SHALL target the billable row only
The row-total action SHALL be available on ordinary, packed, and billable bundle-parent rows. For a bundle, it MUST target the billable bundle-parent row, while linked non-billable component prices and subtotals remain zero.

#### Scenario: Bundle components remain non-billable
- **WHEN** a bundle parent row receives an approved total
- **THEN** only the billable parent row total MUST carry that amount
- **AND** component commercial price and subtotal MUST remain zero

### Requirement: Row-total override SHALL reconcile through checkout and owner splitting
An authoritative overridden row total SHALL be used by cart totals, payment validation, receipt display, tax calculation, draft snapshots, idempotency hashes, and checkout posting. Fulfilment splits MUST allocate amounts deterministically so owner allocations sum exactly to the authoritative row total.

#### Scenario: Split-owner row remains exact
- **WHEN** one overridden row is fulfilled from two or more stock owners
- **THEN** generated Sales allocations MUST receive deterministic shares
- **AND** the shares MUST sum exactly to the authoritative POS row total

### Requirement: Row-total override SHALL be line-bound, auditable, and stale-safe
The system SHALL record source total, requested total, row fingerprint, reason, requester, approver, and executor. Pending or approved-but-unconsumed approval MUST be invalidated when the target row's relevant state changes, and its token MUST be valid only for that requester, action, session, and line.

#### Scenario: Successful override is audited in minor units
- **WHEN** a row-total override completes successfully
- **THEN** the audit record MUST carry action type `LINE_TOTAL_OVERRIDE`
- **AND** it MUST carry source and requested totals in minor units plus the target line and fingerprint