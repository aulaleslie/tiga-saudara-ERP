## ADDED Requirements

### Requirement: POS SHALL create approved audit-only dispatch details for non-stock content
When POS checkout posts a non-stock-managed parent product or non-stock-managed bundle component, the system SHALL persist its fulfilled quantity as an approved DispatchDetail in the generated owner Sale's approved Dispatch. The Sale SHALL be `DISPATCHED` as part of the same successful checkout transaction.

#### Scenario: Service-only POS checkout is immediately dispatched
- **WHEN** a POS checkout containing only a non-stock-managed service succeeds
- **THEN** the generated Sale SHALL be `DISPATCHED`
- **AND** it SHALL have an approved Dispatch and a DispatchDetail for the service quantity

#### Scenario: Non-stock dispatch detail has no inventory effects
- **WHEN** POS posts an approved DispatchDetail for non-stock-managed content
- **THEN** it SHALL not require available stock, serials, or a stock allocation
- **AND** it SHALL not create or mutate product stock, product quantities, serial state, or inventory transactions

### Requirement: POS non-stock ownership SHALL use the first configured sales-location source
For every non-stock-managed POS parent product or bundle component, the generated financial and audit ownership SHALL be resolved from the first enabled location returned by the ordered POS sales-location configuration for the terminal setting. The owner Sale setting SHALL be that location's business setting and the audit DispatchDetail location SHALL be that configured location.

#### Scenario: Non-stock service uses the configured first source instead of the terminal business
- **WHEN** the terminal setting differs from the business that owns the first enabled configured POS sales location
- **THEN** a non-stock POS service Sale SHALL belong to that first configured location's business
- **AND** its audit DispatchDetail SHALL reference that first configured location

#### Scenario: Reordering configured POS locations changes future non-stock ownership
- **WHEN** an authorized user changes the enabled POS sales-location order so another location is first
- **THEN** a later non-stock POS checkout SHALL use the newly first configured location and its business as owner
- **AND** prior checkout ownership and audit mappings SHALL remain unchanged

### Requirement: POS checkout SHALL be idempotent for non-stock audit dispatches
The checkout idempotency contract SHALL cover non-stock audit Dispatches and DispatchDetails as part of the atomic successful posting result.

#### Scenario: Checkout replay does not duplicate a service audit detail
- **WHEN** a completed non-stock POS checkout is submitted again with its original idempotency key and matching payload
- **THEN** the system SHALL return the persisted checkout result
- **AND** it SHALL not create an additional Sale, Dispatch, or DispatchDetail
