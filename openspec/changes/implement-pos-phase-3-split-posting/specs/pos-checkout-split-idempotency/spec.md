## ADDED Requirements

### Requirement: Split finalize replay MUST be idempotent
The system MUST treat finalize replay with the same checkout idempotency key as a read of the already posted split result, and MUST NOT create additional sales, payments, or dispatch records.

#### Scenario: Replay with same idempotency key
- **WHEN** finalize is called again with a previously successful idempotency key
- **THEN** no new posting side effects occur and the prior split mapping is returned

### Requirement: Split group ordering SHALL be deterministic
The system SHALL order split groups deterministically by `split_key` for both first-time finalize and replay responses.

#### Scenario: Stable split ordering across retries
- **WHEN** the same checkout finalize is retried after a network timeout
- **THEN** returned split groups preserve identical ordering by `split_key`

### Requirement: Checkout split map MUST be persisted per group
The system MUST persist one mapping row per split group with unique `(pos_checkout_id, split_key)` so replay and reconciliation can use canonical stored results.

#### Scenario: Duplicate split key prevention
- **WHEN** persistence is attempted for an already stored split key on the same checkout
- **THEN** the system rejects duplicate insert and keeps existing mapping as authoritative
