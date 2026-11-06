# Spec Delta

## Purpose

Defines auditable financial resolution of standard cash returns using source-sale value, verified customer payments, and debt adjustments without changing POS financial execution.

## ADDED Requirements

### Requirement: Return value derives from source financial evidence
Standard cash-return value SHALL be bounded by the original attributable charged value of the returned quantity or serial, including applicable discounts, taxes, and prior committed returns. Persisted source evidence MUST govern approval; client prices, zero-to-full fallbacks, and per-detail totals applied to single serials MUST NOT inflate settlement.

#### Scenario: One serial from a multi-serial detail
- **WHEN** one of three equally valued serials is cash-returned
- **THEN** its settlement is bounded by its attributable unit charge rather than the entire detail subtotal

#### Scenario: Source value changes after review
- **WHEN** affected source financial evidence changes between settlement review and approval
- **THEN** approval blocks stale amounts and requires renewed review without committing money effects

### Requirement: Cash return reduces debt before paying cash
Approval SHALL apply the committed cash-return value to outstanding source-sale debt first and refund the remainder. It MUST record commercial reduction, debt reduction, and actual refund separately and atomically, reconciling source and return balances. Repair/replacement and cannot-process settlements MUST leave source-sale financial amounts unchanged.

#### Scenario: Partially paid source sale
- **WHEN** a Rp1,000,000 sale paid Rp400,000 with Rp600,000 outstanding settles returned value Rp800,000
- **THEN** debt reduction is Rp600,000, cash refund is Rp200,000, and the remaining net sale obligation is Rp200,000
- **AND** distinct adjustment and cash-payment evidence explains the allocation

#### Scenario: Debt-only return
- **WHEN** returned value is fully absorbed by outstanding debt
- **THEN** the settlement can complete with a recorded debt adjustment and no zero-value cash-payment record

#### Scenario: Repair completed
- **WHEN** repaired goods or a replacement are dispatched to the customer
- **THEN** the source-sale total, paid amount, and due amount are unchanged by that resolution

### Requirement: Cash payout is positive and payment capped
Any actual cash payout SHALL be positive and MUST NOT exceed source-attributable active customer payments remaining after previous refunds. Explicit zero or negative payout inputs MUST NOT become a full refund. Debt-only and zero-charge physical returns MUST remain distinguishable from cash payouts.

#### Scenario: Explicit zero cash input
- **WHEN** a caller explicitly submits a zero or negative cash payout
- **THEN** the payout is rejected rather than substituted with the detail subtotal

#### Scenario: Prior refunds exhaust paid balance
- **WHEN** an attempted payout exceeds verified payments less prior committed refunds
- **THEN** approval is blocked without partial debt or money effects

### Requirement: Ambiguous financial allocation blocks settlement
Financial approval SHALL stop with an actionable explanation when source value, shared or historical payment allocation, or prior-refund evidence cannot reliably establish the source-sale refundable balance. Physical receiving and custody tracking MUST remain available independently. The system MUST NOT guess allocations or rewrite historical evidence.

#### Scenario: Shared payment cannot be attributed
- **WHEN** a standard source sale has a shared payment with no reliable sale allocation
- **THEN** financial approval is blocked with the missing evidence identified
- **AND** authorized receiving and custody tracking can continue

### Requirement: Financial actions serialize against source balances
Concurrent return settlements and payment changes SHALL serialize and revalidate the affected source balance before committing. Each settlement MUST have unique adjustment and payout evidence. Source-sale outstanding balances, payment status, return payment display, and financial projections MUST reconcile without deducting a return twice.

#### Scenario: Two settlements compete for remaining payments
- **WHEN** concurrent approvals together exceed the remaining refundable payment balance
- **THEN** only valid allocations commit and no duplicate payout or debt reduction occurs

#### Scenario: Financial projections after partial return
- **WHEN** a partial cash return commits
- **THEN** source-sale outstanding balance, return settlement totals, and receivables displays agree on the separate debt and cash effects
