## ADDED Requirements

### Requirement: Checkout payment composer SHALL support multiple payment rows
The checkout modal SHALL allow cashier to compose one or more payment rows, where each row captures payment method, amount, and optional reference, and contributes to one shared checkout total.

#### Scenario: Cashier composes mixed-method payment
- **WHEN** cashier adds two payment rows (for example, transfer and cash) in checkout modal
- **THEN** the system stores both rows in checkout form state
- **AND** the modal shows aggregated `total_paid`, `remaining`, and `change` values based on all rows.

#### Scenario: Cashier removes one payment row
- **WHEN** cashier deletes an existing payment row from the composer list
- **THEN** the system recalculates aggregate totals immediately
- **AND** removed row no longer contributes to checkout validation.

### Requirement: Payment method picker SHALL not collide with selected payment rows
The payment method search/picker UI SHALL be isolated from the selected-payment list so search results do not visually overlap, hide, or replace the selected payment composition area.

#### Scenario: Search results open while payment list is populated
- **WHEN** cashier focuses payment method search with one or more selected payment rows already present
- **THEN** search results are displayed in the picker region only
- **AND** selected payment rows remain visible and interactive without stacking/collision.

### Requirement: Checkout confirmation SHALL enforce multi-row payment validation
Checkout submit SHALL be enabled only when payment composition satisfies grand-total reconciliation and per-method constraints.

#### Scenario: Non-cash row missing required reference
- **WHEN** a payment row uses a method that requires reference and reference is blank
- **THEN** checkout submit is blocked
- **AND** validation message identifies the specific row/method requiring reference.

#### Scenario: Remaining amount above zero
- **WHEN** sum of all payment row amounts is less than checkout grand total
- **THEN** checkout submit is blocked
- **AND** modal shows explicit remaining amount to be paid.

#### Scenario: Cash overpayment computes change from aggregate cash component
- **WHEN** sum of payment rows exceeds grand total and at least one row is cash
- **THEN** checkout submit is allowed
- **AND** change is computed and displayed from aggregate overpayment.
