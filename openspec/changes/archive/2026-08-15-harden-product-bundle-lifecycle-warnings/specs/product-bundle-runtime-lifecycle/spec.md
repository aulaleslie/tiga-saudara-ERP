## ADDED Requirements

### Requirement: New bundle selections SHALL use setting-scoped live eligibility
The system SHALL allow a bundle to be selected for a new POS or Sales cart line only when the bundle belongs to the transaction setting, is enabled, is within its activation period, has a non-empty valid first-level composition, and its live component products remain available for selection. Activation dates SHALL be evaluated as inclusive calendar dates using the application business timezone, with a null boundary treated as open-ended.

#### Scenario: Eligible bundle is selectable
- **WHEN** an enabled bundle belongs to the transaction setting, the business date is on or between its configured date boundaries, and its live composition is valid
- **THEN** the system SHALL allow the bundle to be selected for a new cart line

#### Scenario: Inclusive activation boundaries
- **WHEN** the application business date equals an enabled bundle's `active_from` or `active_to` date
- **THEN** the system SHALL treat that date boundary as eligible

#### Scenario: Open-ended activation period
- **WHEN** an enabled bundle has a null `active_from`, a null `active_to`, or both
- **THEN** each null boundary SHALL impose no date restriction

#### Scenario: Ineligible bundle is unavailable for new selection
- **WHEN** a bundle is disabled, has not started, has expired, has an empty or invalid live composition, or has an inactive or missing live component
- **THEN** the system SHALL exclude or mark the bundle unavailable in POS and Sales selection surfaces
- **AND** the system SHALL reject a direct or manually submitted attempt to add that bundle as a new cart line

#### Scenario: Setting copy eligibility is independent
- **WHEN** a bundle copy in Setting A is disabled, expired, edited, or deleted while a corresponding copy in Setting B remains eligible
- **THEN** the Setting A copy SHALL be unavailable for new Setting A selections
- **AND** the Setting B copy SHALL remain independently selectable in Setting B

### Requirement: Captured bundle lifecycle changes SHALL require acknowledgement instead of blocking
When a POS or Sales transaction already contains a persisted bundle snapshot, live ineligibility SHALL produce a consolidated warning that requires explicit user acknowledgement for the current operation. Live ineligibility alone SHALL NOT permanently block the transaction after acknowledgement.

#### Scenario: POS draft contains a newly ineligible bundle
- **WHEN** a user loads or advances a persisted POS draft whose captured bundle is now disabled, future-dated, expired, deleted, or invalid in its live definition
- **THEN** the system SHALL present an acknowledgement warning describing each affected bundle line and reason
- **AND** the system SHALL allow the requested operation to continue after acknowledgement

#### Scenario: Sales draft contains a newly ineligible bundle
- **WHEN** a user loads, submits, updates, or approves a Sales draft whose captured bundle is now ineligible
- **THEN** the system SHALL present an acknowledgement warning describing each affected bundle line and reason
- **AND** the system SHALL allow the requested operation to continue after acknowledgement

#### Scenario: Dispatch uses an ineligible captured bundle
- **WHEN** dispatch creation or approval encounters a persisted Sales bundle whose live definition is now ineligible
- **THEN** the system SHALL require acknowledgement for that operation
- **AND** the system SHALL allow dispatch to continue from persisted demand after acknowledgement

#### Scenario: Multiple bundle warnings are consolidated
- **WHEN** one operation encounters multiple ineligible captured bundles or components
- **THEN** the system SHALL present one consolidated warning containing all affected lines and applicable reasons
- **AND** one acknowledgement SHALL apply to those warnings for that operation

#### Scenario: User cancels the warning
- **WHEN** a lifecycle warning is presented and the user declines or cancels acknowledgement
- **THEN** the requested load or lifecycle action SHALL not proceed
- **AND** the transaction's persisted snapshot and status SHALL remain unchanged

### Requirement: Component lifecycle changes SHALL be warnings for captured snapshots
For an already captured bundle snapshot, an inactive, deleted, missing, or no-longer-included live component SHALL be treated as a lifecycle warning rather than an automatic operational failure.

#### Scenario: Captured component becomes inactive
- **WHEN** a persisted bundle snapshot contains a component that is now inactive in the live product catalog
- **THEN** the lifecycle warning SHALL identify the affected component
- **AND** acknowledgement SHALL allow processing to continue using the captured component identity and quantity

#### Scenario: Captured component is deleted or missing
- **WHEN** a persisted bundle snapshot contains a component that can no longer be found in the live catalog or live bundle definition
- **THEN** the lifecycle warning SHALL identify the missing component using persisted snapshot data where available
- **AND** acknowledgement SHALL allow processing to proceed to existing operational validation

#### Scenario: Component removed from live composition
- **WHEN** a component captured in a draft is later removed from the live bundle composition
- **THEN** the system SHALL warn about the definition change
- **AND** the system SHALL retain the captured component in the transaction after acknowledgement

### Requirement: Acknowledged continuation SHALL use persisted bundle snapshots
After acknowledgement, POS and Sales SHALL use the transaction's persisted parent and first-level component identities, quantities, prices, and operational metadata. The system SHALL NOT refresh or replace captured composition from the live bundle definition.

#### Scenario: Live composition changed after draft capture
- **WHEN** a user acknowledges that the live bundle composition differs from a persisted draft
- **THEN** the continued transaction SHALL use the component composition captured by that draft
- **AND** newly added, removed, or modified live component rows SHALL NOT alter the captured transaction

#### Scenario: Live bundle was deleted
- **WHEN** a user acknowledges that the captured bundle no longer exists as a live definition
- **THEN** the system SHALL retain the persisted bundle grouping and component demand
- **AND** it SHALL continue to operational validation without requiring the live bundle row

### Requirement: Lifecycle acknowledgement SHALL be request-scoped and non-persistent
Lifecycle acknowledgement SHALL authorize only the current requested operation and SHALL NOT be stored as transaction history, audit metadata, or durable session state. A later lifecycle operation MAY require acknowledgement again.

#### Scenario: Acknowledged request is retried
- **WHEN** the user resubmits the current operation with explicit lifecycle acknowledgement
- **THEN** the server SHALL reevaluate the live lifecycle warnings
- **AND** it SHALL continue only the acknowledged operation when no separate hard validation fails

#### Scenario: Later operation encounters the same warning
- **WHEN** a draft was previously loaded after acknowledgement and is later submitted, approved, checked out, or dispatched
- **THEN** the later operation SHALL be allowed to present the lifecycle warning again
- **AND** no stored acknowledgement SHALL silently suppress it

### Requirement: Operational and integrity validation SHALL remain blocking
Lifecycle acknowledgement SHALL bypass only live bundle and component eligibility warnings. Existing stock, serial, ownership, location, tax, persisted snapshot integrity, idempotency, and dispatch reconciliation requirements SHALL remain authoritative blocking gates.

#### Scenario: Captured component lacks stock
- **WHEN** the user acknowledges a component lifecycle warning but existing stock validation cannot fulfill the captured component demand
- **THEN** the operation SHALL be rejected by the stock-availability gate

#### Scenario: Persisted snapshot integrity fails
- **WHEN** persisted POS or Sales bundle data is corrupted, tampered with, or insufficient to reconstruct authoritative captured demand
- **THEN** the operation SHALL remain blocked by the applicable integrity validation
- **AND** lifecycle acknowledgement SHALL NOT bypass the failure

#### Scenario: Required serial or dispatch mapping fails
- **WHEN** an acknowledged captured bundle cannot satisfy required serial, ownership, location, tax, or dispatch reconciliation rules
- **THEN** the applicable operational validation SHALL block the operation

### Requirement: Historical transactions SHALL be isolated from live lifecycle eligibility
Completed or posted Sales and POS history SHALL remain readable and reversible from persisted transaction data without applying live bundle eligibility warnings or filters.

#### Scenario: Receipt or reprint after lifecycle change
- **WHEN** a completed POS transaction is displayed or reprinted after its live bundle or component becomes ineligible
- **THEN** the receipt SHALL use persisted historical composition
- **AND** it SHALL not require lifecycle acknowledgement

#### Scenario: Return after lifecycle change
- **WHEN** a return is prepared from a posted transaction whose live bundle was changed, disabled, expired, or deleted
- **THEN** return composition SHALL continue to derive from persisted historical transaction data
- **AND** live lifecycle eligibility SHALL NOT remove captured components

#### Scenario: Historical report after lifecycle change
- **WHEN** a report includes a posted transaction whose live bundle or component is now ineligible
- **THEN** the report SHALL continue to use posted Sales, checkout, dispatch, and snapshot records
- **AND** it SHALL not filter or recalculate the transaction from the live bundle definition

### Requirement: Posted POS idempotent replay SHALL remain historical
A finalize request that resolves to an already-posted POS checkout with a matching idempotency payload SHALL return the stored checkout result without requiring current bundle lifecycle eligibility or acknowledgement.

#### Scenario: Posted checkout replay after bundle deletion
- **WHEN** a matching idempotent finalize request is replayed after the checkout's live bundle definition was deleted or became ineligible
- **THEN** the system SHALL return the stored posted result
- **AND** it SHALL not treat the replay as a new bundle sale

