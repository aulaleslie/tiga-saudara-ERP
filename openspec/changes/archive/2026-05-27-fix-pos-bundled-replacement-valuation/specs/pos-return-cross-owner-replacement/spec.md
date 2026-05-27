## ADDED Requirements

### Requirement: Cross-Owner Bundled Replacement Sale SHALL Use Source Sale Detail Commercial Amount
For cross-owner POS bundled product replacement, the generated replacement-owner Sale SHALL use the source sale detail commercial amount for the returned quantity as the Sale total, paid amount, Sale detail unit price, Sale detail subtotal, and SalePayment amount. The system MUST NOT use the POS bundle list price when it differs from the source sale detail commercial amount.

#### Scenario: Generated replacement sale mirrors replaced sale detail amount
- **WHEN** final approval executes a cross-owner product replacement for a bundled parent item
- **AND** the replaced source sale detail commercial amount is 6,085,000
- **AND** the POS bundle list price captured in the return snapshot is 6,100,000
- **THEN** the generated replacement-owner Sale total amount is 6,085,000
- **AND** the generated replacement-owner Sale paid amount is 6,085,000
- **AND** the generated replacement-owner Sale detail `price`, `unit_price`, and `sub_total` are 6,085,000
- **AND** the generated replacement-owner SalePayment amount is 6,085,000.

#### Scenario: Original sale correction uses same bundled replacement valuation
- **WHEN** final approval executes a cross-owner bundled product replacement
- **THEN** the original Sale correction amount and generated replacement-owner Sale amount are calculated from the same source sale detail commercial amount
- **AND** the approval remains atomic across the original Sale correction, replacement-owner Sale, payment, dispatch, stock, serial, POS Return, and linked Sales Return mutations.
