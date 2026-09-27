## MODIFIED Requirements

### Requirement: Split finalize replay MUST be idempotent
The system MUST treat finalize replay with the same successfully posted checkout idempotency key as a read of the complete stored owner-split result, and MUST NOT create additional Sales, details, bundle items, payments, dispatches, inventory transactions, serial mutations, or checkout mappings.

#### Scenario: Replay with same successful idempotency key
- **WHEN** finalize is called again with a previously successful idempotency key and matching payload
- **THEN** no new posting side effects SHALL occur
- **AND** the prior ordered split map, receipt identity, totals, payments, and compatibility fields SHALL be returned

#### Scenario: Failed key is not replayed as success
- **WHEN** finalize is called with an idempotency key whose prior posting attempt failed and rolled back
- **THEN** the system SHALL reject that key as previously failed
- **AND** a corrected attempt SHALL require a new idempotency key

### Requirement: Checkout split map MUST be persisted per group
The system MUST persist one authoritative mapping row per successfully posted split group with unique `(pos_checkout_id, split_key)`. Each mapping SHALL retain the source setting, source location, tax bucket, Sale, payment, dispatch, and reconciled group totals required for replay and historical owner resolution.

#### Scenario: Successful checkout persists complete owner map
- **WHEN** a checkout posts multiple owner groups successfully
- **THEN** exactly one mapping SHALL exist for each planned split key
- **AND** every mapping's source setting SHALL agree with its source location's persisted setting

#### Scenario: Duplicate split key prevention
- **WHEN** persistence is attempted for an already stored split key on the same checkout
- **THEN** the system SHALL reject the duplicate insert
- **AND** the existing mapping SHALL remain authoritative

#### Scenario: Failed posting persists no partial split map
- **WHEN** any owner group fails during atomic posting
- **THEN** no mapping from that posting attempt SHALL remain persisted
