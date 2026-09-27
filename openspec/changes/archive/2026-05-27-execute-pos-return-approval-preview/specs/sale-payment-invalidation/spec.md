## ADDED Requirements

### Requirement: Sale Payments Support Active And Invalidated States
The system SHALL support active and invalidated Sale payment states equivalent to Purchase payment invalidation. Existing Sale payments MUST remain active after migration. Invalidated Sale payments MUST retain their original amount, date, method, reference, attachments, invalidation actor, invalidation timestamp, invalidation source, and invalidation source id.

#### Scenario: Existing sale payments remain active
- **WHEN** the Sale payment invalidation schema is applied to existing Sale payment records
- **THEN** existing records are treated as active payments
- **AND** Sale paid and due calculations remain unchanged before any return execution

#### Scenario: Sale payment is invalidated with source
- **WHEN** POS Return cash execution invalidates a Sale payment
- **THEN** the Sale payment records invalidated status, actor, timestamp, source, and source id
- **AND** the original payment amount and payment metadata remain available for audit

### Requirement: Cash Return Splits Active Sale Payments
When a POS cash return lowers a Sale total below the active paid amount, the system SHALL invalidate affected active Sale payments and create replacement active Sale payment records so active payments sum to the corrected paid amount. The surplus amount MUST be represented by linked Sale Return Payment refund evidence.

#### Scenario: Partial paid sale return creates replacement active payment
- **WHEN** a fully paid Sale with active payment amount 1000 is cash-returned so the corrected Sale total is 600
- **THEN** the original active payment is invalidated
- **AND** a replacement active Sale payment for 600 is created
- **AND** a Sale Return Payment refund record exists for the returned 400

#### Scenario: Full paid sale return invalidates all active payments
- **WHEN** a fully paid Sale is fully cash-returned and the corrected Sale total is 0
- **THEN** all active Sale payments are invalidated
- **AND** no replacement active Sale payment is created
- **AND** the Sale total, paid amount, and due amount are all 0

### Requirement: Multi Payment Adjustment Uses Last Payment First
When POS Return cash execution must reduce active Sale payments, the system SHALL invalidate or split active Sale payments using last-payment-first ordering. Replacement active payment records MUST preserve the original payment method and trace the source payment they replace when practical.

#### Scenario: Last payment is reduced first
- **WHEN** a Sale has multiple active payments and a cash return creates surplus paid amount
- **THEN** the most recent active payment is invalidated or split before earlier active payments
- **AND** earlier payments remain active when the surplus is fully absorbed by later payments

#### Scenario: Surplus exceeds last payment
- **WHEN** the surplus paid amount exceeds the latest active Sale payment amount
- **THEN** the latest active payment is fully invalidated
- **AND** the system continues invalidating or splitting earlier active payments until active payments match the corrected Sale paid amount
