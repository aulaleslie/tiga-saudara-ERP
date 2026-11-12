# Spec Delta

## MODIFIED Requirements

### Requirement: Approved dispatch evidence governs consignment sale eligibility
The system SHALL identify billable consignment sale quantities only from approved dispatch details whose persisted source location is classified as consignment and whose local receipt or verified transfer provenance establishes supplier responsibility. The original receipt business SHALL govern supplier-billing eligibility even when the dispatch location and selling business differ. Ordinary Sales and POS-generated Sales SHALL use the same dispatch-detail authority, and the system SHALL NOT rerun current stock selection or POS location priority during discovery. Bundle association alone SHALL NOT make an authoritative stock-managed dispatch detail unsupported.

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
- **WHEN** a dispatch detail's source location is deactivated or reclassified so it is no longer an active consignment location of its persisted custody business
- **THEN** both preview and persistence SHALL classify the evidence as an invalid source location
- **AND** preview SHALL expose it as a blocker while persistence SHALL exclude it without creating a sold source

#### Scenario: Discovery remains manually invoked
- **WHEN** an ordinary Sales or POS transaction posts
- **THEN** consignment sold-source discovery SHALL not run automatically or add cashier steps
- **AND** later authorized manual discovery SHALL read the existing dispatch without changing its location, quantity, owner, tax, revenue, or payment records

#### Scenario: Remote sale is manually discovered by the original receipt business
- **WHEN** TOP IT runs discovery for its receipt and verified provenance links a PERDANA dispatch to that receipt
- **THEN** internal reconciliation SHALL retain PERDANA as the seller and TOP IT as supplier-billing business
- **AND** that quantity SHALL not become another PERDANA supplier obligation

### Requirement: Sold-source evidence is immutable and idempotent
The system SHALL create at most one immutable consignment sold-source record per eligible dispatch detail and SHALL snapshot its original product, setting, sale, optional POS checkout, source location, base quantity, dispatch time, tax context, serial identities, source references, bundle identity, inventory classification, snapshot version, and canonical source hash. Discovery SHALL support future and historical eligible dispatches without rewriting an existing sold source, and live validation SHALL use the canonical payload associated with the stored snapshot version. Where a snapshot version defines classification evidence, live validation SHALL require that evidence to be present and valid and SHALL re-verify it against the live classification rather than trusting the persisted value alone. New transfer-aware evidence SHALL distinguish actual selling/custody identity from receipt-billing responsibility. One dispatch SHALL remain one sold occurrence even when separate receipt-backed obligation slices belong to different suppliers or businesses. Additional provenance SHALL NOT rewrite historical sold-source snapshots.

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

#### Scenario: Two businesses discover the same transferred sale
- **WHEN** origin and selling businesses manually discover the same remote dispatch
- **THEN** the physical sold occurrence SHALL exist once and original-receipt obligation slices SHALL not duplicate billed or available quantities

#### Scenario: Existing snapshot gains verified transfer provenance
- **WHEN** a historical sold source needs additional transfer/receipt attribution
- **THEN** the original snapshot and canonical hash SHALL remain unchanged and versioned supplementary provenance SHALL record the new decision

### Requirement: Serialized ownership resolves from approved receipt lineage
For every serialized sold unit, the system SHALL resolve exactly one approved, non-reversed Consignment Receiving detail for the same product using immutable receiving pivots, serial history, and verified transfer paths to the persisted sale location. A different receipt location/business SHALL be accepted only through complete transfer evidence, not by disabling tenant or location checks. The resolved supplier and receipt lot SHALL be non-editable, and a serialized unit SHALL have at most one active or approved allocation claim.

#### Scenario: Sold serial has valid consignment lineage
- **WHEN** a sold serial maps unambiguously to an approved consignment receiving detail matching its sold product and location
- **THEN** the system SHALL display the resolved supplier, receipt, cost, and tax snapshots read-only
- **AND** the user SHALL NOT redirect it to another supplier or receipt lot

#### Scenario: Serialized lineage is missing or ambiguous
- **WHEN** a sold serial has no eligible receipt lineage, multiple conflicting lineages, a reversed source, or a product/location mismatch
- **THEN** allocation SHALL be blocked with actionable lineage evidence

#### Scenario: Returned serial no longer has a current dispatch pointer
- **WHEN** an effective return cleared a serial's current dispatch pointer
- **THEN** the system SHALL retain its original sold and supplier identity through immutable sold-source, receiving-pivot, and history evidence

#### Scenario: Sold serial was transferred across businesses
- **WHEN** a PERDANA sold serial traces through completed consignment transfers to an approved TOP IT receipt
- **THEN** its supplier and financial receipt identity SHALL resolve to TOP IT while preserving the actual PERDANA dispatch location
- **AND** original-location mismatch alone SHALL not block its verified lineage

### Requirement: Non-serialized quantities are manually allocated to same-location supplier receipt lots
An authorized user SHALL allocate non-serialized sold base quantity explicitly among approved, non-reversed Consignment Receiving details belonging to the confirmation supplier, product, and original receipt business, with sufficient receipt-backed custody at the exact sold source location established by direct receiving or verified transfer allocations. The system SHALL permit multiple receipt lots for that supplier but SHALL NOT automatically substitute, pool across locations, or apply FIFO/LIFO.

#### Scenario: User allocates across eligible receipt lots
- **WHEN** a supplier has multiple eligible receiving-detail lots for the sold product at the source location
- **THEN** the user SHALL be able to distribute the sold quantity across those lots
- **AND** each selected quantity and commercial snapshot SHALL be retained separately

#### Scenario: User selects another location or supplier
- **WHEN** an allocation references a receipt lot from a different supplier, product, billing business, or without sufficient direct/transfer-backed custody at the sold location
- **THEN** validation SHALL reject it without reserving or approving quantity

#### Scenario: Allocation does not cover the selected sold quantity
- **WHEN** receipt-lot allocations do not sum exactly to the sold base quantity selected for confirmation
- **THEN** submission SHALL fail with no reservation change

#### Scenario: Remote non-serialized sale has several eligible receipts
- **WHEN** manual discovery finds a completed remote sale that could consume multiple receipt lots
- **THEN** back-office staff SHALL explicitly allocate the quantity among eligible custody-backed lots before billing or buyout
- **AND** checkout SHALL not be changed, deferred, or require receipt selection

#### Scenario: Sole eligible lot is proposed
- **WHEN** only one receipt-backed lot can cover the sale
- **THEN** reconciliation SHALL propose it for authorized confirmation without silently reserving or billing quantity

### Requirement: Sold and receipt capacities are enforced atomically
Submission and approval SHALL atomically ensure that each requested quantity does not exceed both the sold source's remaining eligible quantity and each receiving detail's remaining supplier-owned quantity after effective returns, reversals, approved allocations, and other active reservations. Locks SHALL be acquired in a stable order, and over-allocation SHALL be rejected rather than reassigned. Capacity SHALL include active legacy reservations/allocations and approved or finalized non-reversed buyout consumption exactly once. Unsold physical availability SHALL additionally exclude explicitly attributed outstanding sales and incompatible physical reservations; financial receipt residual alone SHALL NOT establish physical availability.

#### Scenario: Two submissions compete for the same sold quantity
- **WHEN** concurrent confirmations attempt to reserve more than the remaining quantity of one sold source
- **THEN** at most the valid remaining quantity SHALL be reserved
- **AND** the losing transaction SHALL make no partial reservation

#### Scenario: Two submissions compete for one receipt pool
- **WHEN** concurrent confirmations attempt to reserve more than one receiving detail's remaining supplier-owned quantity
- **THEN** at most the available receipt quantity SHALL be reserved
- **AND** the system SHALL NOT substitute another receipt lot

#### Scenario: Later return invalidates a pending confirmation
- **WHEN** an effective return reduces capacity after submission but before approval
- **THEN** approval SHALL fail atomically and require the confirmation to be revised

#### Scenario: Legacy confirmation competes with a buyout
- **WHEN** sold-only confirmation submission and buyout approval compete for the same receipt or sold-source balance
- **THEN** shared authoritative capacity SHALL permit at most the available quantity across both workflows
- **AND** the losing transaction SHALL create no partial allocation or claim

#### Scenario: A buyout is finalized or reversed
- **WHEN** an approved buyout is finalized or an eligible finalized buyout is fully reversed
- **THEN** its reservation SHALL become consumed capacity or released capacity respectively without counting both the reservation and its posting

#### Scenario: Transfers and remote billing share one receipt balance
- **WHEN** local or remote billing, buyout, transfer, or return operations target the same original receipt
- **THEN** physical custody SHALL be checked at its actual location and financial consumption SHALL be checked globally against the original receipt and exact sold-receipt attribution
- **AND** no transfer, reservation, or mirrored business view SHALL consume supplier capacity twice

### Requirement: Reconciliation exposes custody-to-allocation balances
The consignment reconciliation SHALL expose approved received, reversed, sold-from-consignment, physically returned-before-billing, waiting-reserved, approved-allocated, and remaining receipt-pool quantities with filters for setting, supplier, product, location, source transaction, confirmation status, and serial. Totals SHALL derive from immutable source and allocation events rather than mutable available-balance counters. Supported inventory-managed bundle movements SHALL participate in these totals using their physical DispatchDetail product and quantity. Reconciliation SHALL additionally distinguish non-financial sold receipt attribution, active buyout reservations, acquired sold/unsold quantities, ownership movements, and reversals without treating the same goods as both available consignment and acquired stock.

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

#### Scenario: Sold attribution is not supplier billing
- **WHEN** staff attribute pooled sales to supplier receipts before preparing an unsold buyout
- **THEN** reconciliation SHALL show that attribution separately from payable creation and financial allocation
- **AND** those sold quantities SHALL no longer appear as physically unsold for the attributed receipts

#### Scenario: Proven transfers explain the apparent local shortage
- **WHEN** a receipt's remainder is distributed across local stock, remote stock, transit, and remote sales
- **THEN** reconciliation SHALL show those categories and source transfer/sale references separately instead of comparing its whole residual only with the original warehouse
- **AND** only unexplained shortages SHALL block affected quantities

#### Scenario: Internal and supplier-facing views differ
- **WHEN** remote activity contributes to an original-business supplier obligation
- **THEN** authorized internal reconciliation SHALL show the selling/custody businesses while supplier-facing documents SHALL retain only the original business identity
