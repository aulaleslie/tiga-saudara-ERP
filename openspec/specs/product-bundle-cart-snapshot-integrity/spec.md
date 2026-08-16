## Purpose
Define the integrity contract for captured bundle data across POS drafts and mutable transaction flows.

## Requirements

### Requirement: Bundle transaction snapshots SHALL preserve captured commercial identity
When a bundle is selected, the system SHALL capture the parent bundle identity and commercial price together with each first-level component's identity, quantity per bundle, saved informational allocation price, and operational classification metadata. Later live-definition or product-price changes SHALL NOT replace captured component identities, quantities, informational allocations, or the parent commercial price in an open cart, persisted draft, acknowledged continuation, or checkout snapshot.

#### Scenario: Bundle definition changes after cart selection
- **WHEN** an administrator changes the bundle price, adds or removes a component, or changes a component quantity after the bundle was selected
- **THEN** the existing transaction SHALL retain its captured parent price and first-level component snapshot
- **AND** the system SHALL NOT rebuild the transaction from the changed definition

#### Scenario: Standalone product price changes after capture
- **WHEN** a component product's live sale price changes without the relevant bundle copy being saved
- **THEN** the existing bundle transaction SHALL retain its captured informational allocation
- **AND** the standalone product-price change SHALL NOT by itself constitute bundle snapshot drift

### Requirement: Persisted POS bundle snapshots SHALL have complete deterministic integrity protection
The POS persisted-draft integrity projection SHALL include every authoritative parent and component field needed to reconstruct captured bundle demand and commercial allocation. The projection SHALL normalize field aliases, numeric precision, null values, and component ordering deterministically.

#### Scenario: Component snapshot is mutated after draft persistence
- **WHEN** a persisted POS draft's component product, quantity per bundle, informational allocation, or captured operational metadata no longer matches the stored integrity hash
- **THEN** loading the draft SHALL fail with `SNAPSHOT_DRIFT`
- **AND** lifecycle acknowledgement SHALL NOT bypass the failure

#### Scenario: Bundle parent metadata is mutated after draft persistence
- **WHEN** an integrity-protected bundle identifier, captured bundle name, parent commercial price, or other authoritative parent field is changed without regenerating the hash
- **THEN** loading the draft SHALL fail with `SNAPSHOT_DRIFT`

#### Scenario: Equivalent component ordering is canonicalized
- **WHEN** the same valid captured component data is presented in a non-semantic storage order
- **THEN** canonical integrity calculation SHALL produce the same result
- **AND** posting order SHALL NOT change the transaction's commercial or inventory meaning

### Requirement: Valid legacy POS drafts SHALL upgrade without losing captured data
The system SHALL recognize the previous POS draft hash format, verify it with its original projection, and atomically upgrade a valid legacy draft to the complete integrity format before cart hydration. An invalid legacy hash SHALL remain a hard failure.

#### Scenario: Valid legacy draft is loaded
- **WHEN** a persisted POS draft has a valid legacy snapshot hash
- **THEN** the system SHALL verify the legacy projection under the transaction lock
- **AND** it SHALL store the complete current hash before returning the captured cart

#### Scenario: Invalid legacy draft is loaded
- **WHEN** a persisted POS draft does not match its legacy snapshot hash
- **THEN** loading SHALL fail with `SNAPSHOT_DRIFT`
- **AND** the hash SHALL NOT be upgraded

### Requirement: Quantity and informational-allocation drift SHALL use normalized semantics
Sales and POS SHALL compare captured component quantity per bundle and saved informational allocation against the current saved bundle definition using the same normalized field semantics. Detected drift SHALL generate a request-scoped warning but SHALL NOT change the captured transaction.

#### Scenario: POS component quantity changes after capture
- **WHEN** a POS cart or draft captured a component quantity and the live bundle definition later contains a different quantity per bundle
- **THEN** preflight or draft loading SHALL report a quantity-change warning
- **AND** acknowledged continuation SHALL use the captured quantity

#### Scenario: Administrator refreshes informational allocation
- **WHEN** saving the relevant setting's bundle copy changes a component's saved informational allocation after a transaction captured the prior value
- **THEN** the next mutable operation SHALL report an informational-allocation-change warning
- **AND** acknowledged continuation SHALL retain the captured allocation and parent commercial price

### Requirement: All mutable Sales persistence paths SHALL enforce captured-bundle drift checks
Every reachable Sales create or mutable-update entry point SHALL evaluate captured bundle drift immediately before persistence using the same normalized snapshot contract. No controller or Livewire path SHALL silently bypass the required warning and acknowledgement behavior.

#### Scenario: Sales update uses a non-Livewire endpoint
- **WHEN** a reachable Sales update endpoint submits a captured bundle whose live definition has drifted
- **THEN** the endpoint SHALL require the same request-scoped acknowledgement as the Livewire edit path
- **AND** acknowledged persistence SHALL use the captured `sale_bundle_items` composition

#### Scenario: Sales edit hydration follows persisted rows
- **WHEN** a Sales draft is opened after the live bundle definition changes or is deleted
- **THEN** the edit cart SHALL be hydrated from `sale_details` and `sale_bundle_items`
- **AND** live bundle rows SHALL be used only for drift diagnostics, not reconstruction

### Requirement: POS finalization SHALL revalidate drift before ledger mutation
A successful checkout preflight SHALL NOT permanently authorize later finalization. Each new finalize request SHALL reevaluate bundle drift and applicable operational gates before creating or mutating checkout, payment, Sale, dispatch, or stock records.

#### Scenario: Drift occurs after preflight
- **WHEN** preflight succeeds and the bundle definition changes before finalize
- **THEN** finalize SHALL return the applicable drift warning unless that finalize request explicitly acknowledges it
- **AND** no checkout or payment ledger mutation SHALL occur on the unacknowledged attempt

#### Scenario: Matching posted checkout is replayed after drift
- **WHEN** a finalize request matches an already-posted checkout after the live bundle changes or is deleted
- **THEN** the system SHALL return the stored checkout result as an idempotent replay
- **AND** it SHALL NOT reapply current drift or operational eligibility as though the request were a new sale

### Requirement: Stock allocation tax classification SHALL belong to each allocation owner setting
When split allocations are generated for stock-managed parent or bundle components, the tax classification of each Sales allocation SHALL follow the allocation owner setting's `is_pkp` status rather than the POS transaction owner's status. For non-serialized products, stock SHALL be allocated sequentially from configured (location_id, setting_id) sources in exact priority order; PKP and tax status SHALL NOT influence allocation priority. For serialized products, the allocation owner setting SHALL be resolved directly from the selected serial's location ownership.

#### Scenario: Non-PKP POS consumes stock from PKP setting
- **WHEN** a checkout initiated from a non-PKP POS allocates stock from a location owned by a PKP setting
- **THEN** the generated Sales allocation for that stock SHALL be classified as taxable
- **AND** the tax amount SHALL be calculated and recorded on that allocation

#### Scenario: PKP POS consumes stock from non-PKP setting
- **WHEN** a checkout initiated from a PKP POS allocates stock from a location owned by a non-PKP setting
- **THEN** the generated Sales allocation for that stock SHALL be classified as non-tax
- **AND** zero tax amount SHALL be recorded for that allocation
