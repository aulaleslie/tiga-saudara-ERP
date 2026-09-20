# Spec Delta

## MODIFIED Requirements

### Requirement: Debt sale SHALL be collectible from the Sales document
A debt checkout SHALL post one or more Sales whose outstanding balances can be collected later through the existing Sale-level payment workflows or the authorized global POS payment workspace. Every later collection SHALL remain represented by ordinary Sale settlement records rather than a separate POS financial ledger.

#### Scenario: Later collection recomputes status
- **WHEN** a later payment is recorded against a debt sale from the Sales document
- **THEN** the sale's `paid_amount`, `due_amount`, and `payment_status` MUST be recomputed by the existing Sales payment flow toward `Paid`

#### Scenario: Later POS-level collection expands to generated Sales
- **WHEN** an authorized user records a later payment against a completed debt POS transaction
- **THEN** the payment MUST be allocated to the transaction's generated Sales using POS ownership and payment priority
- **AND** each affected Sale's settlement fields MUST be recomputed from canonical active settlement data
- **AND** no separate POS financial ledger is created

#### Scenario: Debt sale appears in receivables
- **WHEN** a debt Sale is posted with an outstanding balance
- **THEN** it MUST appear in existing outstanding-receivables views
- **AND** its completed POS transaction MUST appear as payable in the authorized global POS payment workspace
