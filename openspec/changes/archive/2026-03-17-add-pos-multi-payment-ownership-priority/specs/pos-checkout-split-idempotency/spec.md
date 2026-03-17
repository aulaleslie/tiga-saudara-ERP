## MODIFIED Requirements

### Requirement: Split finalize replay MUST be idempotent
The system MUST treat finalize replay with the same checkout idempotency key as a read of the already posted split result, and MUST NOT create additional sales, payments, dispatch records, checkout payment rows, or payment-allocation mapping rows.

#### Scenario: Replay with same idempotency key
- **WHEN** finalize is called again with a previously successful idempotency key
- **THEN** no new posting side effects occur and the prior split mapping is returned
- **AND** returned payment composition and payment-allocation outputs are identical to first success.

### Requirement: Split group ordering SHALL be deterministic
The system SHALL order split groups deterministically by `split_key` for both first-time finalize and replay responses, and SHALL keep payment composition ordering deterministic for idempotent serialization.

#### Scenario: Stable split and payment ordering across retries
- **WHEN** the same checkout finalize is retried after a network timeout
- **THEN** returned split groups preserve identical ordering by `split_key`
- **AND** returned payment entries preserve deterministic order
- **AND** payment-allocation rows preserve deterministic ordering by split key then payment entry order.

## ADDED Requirements

### Requirement: Idempotency payload matching SHALL include payment composition
Idempotency payload comparison MUST include canonicalized multi-payment composition (method, amount, reference, and order identity) so semantically different payment mixes cannot reuse the same idempotency key.

#### Scenario: Same key with different payment composition
- **WHEN** a request reuses an existing idempotency key but changes payment composition while keeping grand total unchanged
- **THEN** finalize is rejected with idempotency payload mismatch
- **AND** no additional posting side effects occur.
