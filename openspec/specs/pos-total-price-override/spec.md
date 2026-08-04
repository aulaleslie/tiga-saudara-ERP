# pos-total-price-override Specification

## Purpose
TBD - created by archiving change pos-total-price-override. Update Purpose after archive.
## Requirements
### Requirement: POS SHALL support exact cart-total overrides
The POS system SHALL allow an authorized user to request a non-negative target total for a non-empty, mutable cart. The target MAY be greater than or less than the cart's current grand total. The system MUST NOT directly persist an independently writable grand total; it MUST resolve the target into cart-row amounts.

#### Scenario: Authorized user increases a cart total
- **WHEN** a user with direct total-price-override permission requests a target total greater than the current cart total
- **THEN** the system MUST apply the target through cart-row allocation without an approval request
- **AND** the resulting cart grand total MUST equal the requested target exactly

#### Scenario: User lowers a cart total to zero
- **WHEN** a user requests a target total of zero for a non-empty mutable cart
- **THEN** the system MUST accept the request through the applicable authorization flow
- **AND** the resulting cart grand total MUST equal zero

#### Scenario: Negative target is rejected
- **WHEN** a user submits a target total below zero
- **THEN** the system MUST reject the request
- **AND** the cart MUST remain unchanged

### Requirement: Approved target total SHALL reconcile exactly while preserving visible rows
The system SHALL allocate an applied target total proportionally across the current cart rows using deterministic minor-unit rounding. The sum of authoritative allocated row `line_total` amounts MUST equal the target total exactly. The system SHALL retain one visible cart row per pre-existing row and SHALL display a rounded effective unit price for each adjusted row.

#### Scenario: Quantity does not divide evenly into an adjusted amount
- **WHEN** a row with quantity 3 and original unit price Rp3.500 is included in a target total that allocates Rp10.000 to that row
- **THEN** the cart MUST keep one visible row with quantity 3
- **AND** its authoritative line total MUST be Rp10.000
- **AND** its displayed unit price MUST be the rounded effective value derived from Rp10.000 divided by 3

#### Scenario: Multi-row rounding is deterministic
- **WHEN** proportional allocation produces fractional minor-unit shares across multiple cart rows
- **THEN** the system MUST distribute remaining minor units using a deterministic ordering
- **AND** the sum of allocated row totals MUST equal the requested total exactly

### Requirement: Total override SHALL update row pricing without breaking normal checkout
Applied total overrides SHALL mark affected rows as total-overridden and use their authoritative allocated line totals for cart totals, payment validation, receipts, tax display, split-owner allocation, and checkout posting. Packed and bundle rows SHALL remain single customer-facing rows and their normal repricing metadata MUST NOT overwrite an active total override.

#### Scenario: Packed row remains exact after total override
- **WHEN** a cart with packed pricing receives an approved total override
- **THEN** the packed row MUST remain one visible row
- **AND** its allocated authoritative line total MUST participate in the exact requested cart total
- **AND** subsequent checkout MUST use that allocated amount

#### Scenario: Split-owner checkout reconciles after total override
- **WHEN** a cart spanning multiple owners receives an approved total override and is checked out
- **THEN** the sum of generated owner-group totals MUST equal the overridden POS cart total exactly

### Requirement: Total-price override SHALL be fully auditable and bound to the approved cart
The system SHALL record the source total, requested total, allocation/fingerprint, reason, requester, approver, and executor for a total-price override. A pending or approved-but-unconsumed request MUST be invalidated when its cart changes, and token consumption MUST reject a request whose source-cart fingerprint no longer matches.

#### Scenario: Cart changes while request is pending
- **WHEN** a cashier changes cart contents, quantity, customer/tier, serial assignment, or pricing while a total-price override request is pending
- **THEN** the system MUST invalidate the request
- **AND** it MUST NOT permit that request to alter the changed cart

#### Scenario: Approved request is confirmed on unchanged cart
- **WHEN** a supervisor approves a total-price override and the requester confirms it with the issued one-time token before the cart changes
- **THEN** the system MUST consume the token once
- **AND** apply the exact approved target and audit allocation

