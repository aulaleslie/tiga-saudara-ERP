## MODIFIED Requirements

### Requirement: Approved dispatch evidence governs consignment sale eligibility
The system SHALL identify billable consignment sale quantities only from approved dispatch details whose persisted source location belongs to the active setting and is classified as consignment. Ordinary Sales and POS-generated Sales SHALL use the same dispatch-detail authority, and the system SHALL NOT rerun current stock selection or POS location priority during discovery. Bundle association alone SHALL NOT make an authoritative stock-managed dispatch detail unsupported.

#### Scenario: Ordinary sale is dispatched from a consignment location
- **WHEN** an approved ordinary Sales dispatch detail records a supported stock-managed product and a consignment source location
- **THEN** the dispatched base quantity SHALL become eligible for consignment allocation

#### Scenario: Inventory-managed bundle parent or component is dispatched
- **WHEN** an approved dispatch detail for a bundle parent or component records an authoritative inventory-managed movement from a consignment location
- **THEN** that dispatch detail SHALL become eligible for consignment allocation
- **AND** its `bundle_id` SHALL be retained as provenance rather than treated as a blocker

#### Scenario: POS sale uses mixed source locations
- **WHEN** a posted POS checkout produces approved dispatch details for the same product from standard and consignment locations
- **THEN** only quantities persisted on dispatch details from consignment locations SHALL become eligible
- **AND** current POS location priority SHALL NOT be recalculated

#### Scenario: Historical POS inventory classification is nullable
- **WHEN** an otherwise valid historical approved POS dispatch detail has null `is_inventory_managed`, a valid source location, and an authoritative stock-managed product
- **THEN** discovery SHALL classify it through the documented compatibility rule
- **AND** SHALL preserve that compatibility decision in sold-source evidence

#### Scenario: Dispatch is pending, rejected, standard, or non-inventory
- **WHEN** a dispatch is not approved, uses a standard location, lacks valid product or location evidence, or explicitly represents service or non-stock content
- **THEN** it SHALL NOT become allocatable
- **AND** an unsupported consignment dispatch SHALL remain visible as a reconciliation blocker where applicable

#### Scenario: Preview and persistence evaluate the same bundle row
- **WHEN** discovery previews and then persists the same bundle-associated dispatch evidence without an intervening state change
- **THEN** both operations SHALL classify that evidence identically
- **AND** an eligible row SHALL be reported as eligible by both operations

#### Scenario: Source location no longer qualifies at persistence time
- **WHEN** a dispatch detail's source location is deactivated or reclassified so it is no longer an active consignment location of the setting
- **THEN** both preview and persistence SHALL classify the evidence as an invalid source location
- **AND** preview SHALL expose it as a blocker while persistence SHALL exclude it without creating a sold source

### Requirement: Sold-source evidence is immutable and idempotent
The system SHALL create at most one immutable consignment sold-source record per eligible dispatch detail and SHALL snapshot its original product, setting, sale, optional POS checkout, source location, base quantity, dispatch time, tax context, serial identities, source references, bundle identity, inventory classification, snapshot version, and canonical source hash. Discovery SHALL support future and historical eligible dispatches without rewriting an existing sold source, and live validation SHALL use the canonical payload associated with the stored snapshot version. Where a snapshot version defines classification evidence, live validation SHALL require that evidence to be present and valid and SHALL re-verify it against the live classification rather than trusting the persisted value alone.

#### Scenario: Eligible dispatch is discovered repeatedly
- **WHEN** discovery processes the same eligible bundle or non-bundle dispatch detail more than once
- **THEN** exactly one sold-source record SHALL exist
- **AND** its immutable source snapshot SHALL remain unchanged

#### Scenario: Bundle parent and component have separate physical dispatches
- **WHEN** a Sale contains bundle parent or component rows with distinct authoritative DispatchDetails
- **THEN** discovery SHALL represent each physical DispatchDetail exactly once
- **AND** SHALL NOT synthesize additional sold quantity from `sale_bundle_items`

#### Scenario: Historical POS return reduced dispatch quantity
- **WHEN** discovery finds an eligible POS dispatch whose quantity was reduced by an executed cash return before sold-source capture
- **THEN** the system SHALL reconstruct original sold quantity from authoritative dispatch and executed return evidence
- **AND** ambiguous evidence SHALL block allocation instead of being guessed

#### Scenario: Versioned bundle source changes after capture
- **WHEN** bundle identity, inventory classification, dispatch, location configuration, serial state, or checkout data no longer matches a versioned sold-source snapshot
- **THEN** the original sold-source evidence SHALL remain unchanged
- **AND** lifecycle revalidation SHALL report the incompatible live change

#### Scenario: Historical unversioned source is revalidated
- **WHEN** a sold source created under the historical unversioned hash contract is submitted or approved after this change
- **THEN** live validation SHALL use the exact historical canonical payload shape
- **AND** SHALL NOT report a mismatch solely because bundle-aware hashing was introduced later

#### Scenario: Unknown snapshot version is encountered
- **WHEN** lifecycle validation encounters a sold source with an unsupported snapshot/hash version
- **THEN** it SHALL block the operation with an actionable version error
- **AND** SHALL NOT guess a canonical payload shape

#### Scenario: Versioned classification evidence is missing or invalid
- **WHEN** lifecycle validation encounters a versioned sold source whose stored inventory classification or historical-compatibility evidence is absent, null, or not a recognised value
- **THEN** it SHALL block the operation with an actionable corruption error
- **AND** SHALL NOT skip classification revalidation for that source

#### Scenario: Historical compatibility evidence no longer holds
- **WHEN** a sold source captured through the historical compatibility rule is revalidated after its product ceased to be stock-managed
- **THEN** lifecycle validation SHALL block the operation because the live classification no longer matches the captured classification
- **AND** the original sold-source evidence SHALL remain unchanged

### Requirement: Reconciliation exposes custody-to-allocation balances
The consignment reconciliation SHALL expose approved received, reversed, sold-from-consignment, physically returned-before-billing, waiting-reserved, approved-allocated, and remaining receipt-pool quantities with filters for setting, supplier, product, location, source transaction, confirmation status, and serial. Totals SHALL derive from immutable source and allocation events rather than mutable available-balance counters. Supported inventory-managed bundle movements SHALL participate in these totals using their physical DispatchDetail product and quantity.

#### Scenario: Reconciliation includes allocated and remaining quantities
- **WHEN** a supplier receipt pool has sold sources, a waiting confirmation, and an approved confirmation
- **THEN** reconciliation SHALL separately show reserved, approved-allocated, and remaining quantities without double counting

#### Scenario: Supported bundle movement is viewed
- **WHEN** a stock-managed bundle parent or component was dispatched from a consignment location
- **THEN** reconciliation SHALL show its sold source as eligible or allocated rather than as an unsupported bundle blocker

#### Scenario: Standard-only activity is viewed
- **WHEN** Sales and POS activity uses only standard locations
- **THEN** consignment allocation totals SHALL remain unchanged

#### Scenario: Unsupported or ambiguous evidence exists
- **WHEN** discovery encounters missing lineage, non-stock content, unreconstructable historical quantity, unknown hash version, or conflicting return/allocation evidence
- **THEN** reconciliation SHALL expose a blocker with its source reference rather than silently omitting or allocating it

## ADDED Requirements

### Requirement: Bundle serial and receipt provenance remains product-specific
The system SHALL resolve an eligible serialized bundle movement from the DispatchDetail's physical `product_id`, source location, and serial identities, and SHALL allocate it only to matching approved Consignment Receiving provenance. Bundle membership SHALL NOT permit supplier, product, location, or serial lineage substitution. Every captured serial identity SHALL resolve to exactly one live product serial record belonging to that DispatchDetail's product, resolved under lock in deterministic order after the product lock using the composite `(product_id, serial_number)` identity; serial provenance SHALL be recorded completely or not at all.

#### Scenario: Serialized bundle component has matching receipt lineage
- **WHEN** an approved bundle component DispatchDetail carries sold serials received for the same product at the same consignment location
- **THEN** each serial SHALL resolve to its exact receiving detail and supplier
- **AND** the bundle association SHALL remain audit evidence

#### Scenario: Serialized bundle evidence belongs to another product
- **WHEN** a serial carried by a bundle-associated DispatchDetail does not belong to that DispatchDetail's product or receiving provenance
- **THEN** allocation SHALL be blocked without creating a serial claim or receipt allocation

#### Scenario: Captured serial evidence cannot be resolved exactly
- **WHEN** discovery captures a serialized dispatch detail whose serial identities include a serial with no product serial record for that dispatch's product, a repeated serial text, or a serial resolving to more than one record for that product
- **THEN** the sold source SHALL record an actionable reconstruction blocker naming the offending serial evidence
- **AND** no partial serial provenance SHALL be created for the remaining resolvable serials

#### Scenario: The same serial text exists under a different product
- **WHEN** a captured serial's text also exists as a product serial record belonging to a different product
- **THEN** that unrelated record SHALL NOT be read, locked, or treated as conflicting evidence
- **AND** provenance SHALL resolve only to the record owned by the dispatch detail's product

#### Scenario: Serial authority changes between selection and persistence
- **WHEN** the product or existence of a captured serial changes after discovery selects a candidate but before it persists the sold source
- **THEN** discovery SHALL re-resolve serial authority under lock and classify from the locked state
- **AND** SHALL NOT link provenance based on the selection-time state
