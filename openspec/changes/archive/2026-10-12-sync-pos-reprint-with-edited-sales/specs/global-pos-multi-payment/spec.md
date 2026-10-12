# Spec Delta

## MODIFIED Requirements

### Requirement: Global receipt reprinting preserves historical truth
The system SHALL reuse the completed POS receipt presentation and existing print log when reprinting from global detail. Reprints with proven Sale-to-POS-line mapping SHALL show current Sale-derived line prices and transaction total, and `Sisa Utang` SHALL use the same current settlement projection as global POS payment. Original checkout payment methods, tender, and change SHALL remain historical checkout facts and SHALL be distinguishable from current settlement. Persisted checkout and print-history data SHALL remain unchanged.

#### Scenario: Authorized global reprint
- **WHEN** an authorized user reprints a qualifying completed transaction from global detail and its monetary line mapping is proven
- **THEN** the receipt uses the originating business, existing receipt layout, current Sale-derived prices, and current live due
- **AND** the action is recorded as a reprint with the authenticated actor.

#### Scenario: Later collection does not rewrite original tender
- **WHEN** a receipt is reprinted after later Sale payments
- **THEN** original methods, tender, and change remain checkout-time facts
- **AND** `Sisa Utang` reflects the current aggregate live due across generated Sales, including later active payments.

#### Scenario: Historical mapping is ambiguous
- **WHEN** a global reprint requests current prices for a multi-line checkout whose Sale details cannot be assigned to POS lines with certainty
- **THEN** no updated-price receipt or misleading reprint log is produced
- **AND** the operator receives an actionable explanation and can access the original checkout receipt.
