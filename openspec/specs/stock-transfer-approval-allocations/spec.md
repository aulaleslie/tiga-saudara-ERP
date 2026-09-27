# stock-transfer-approval-allocations Specification

## Purpose
Configure multiple source and destination allocations for one immutable stock-transfer goods manifest, with resumable approval work and authoritative execution safeguards.

## Requirements

### Requirement: Serialized allocations follow actual serial locations
For a version 3 pending manifest, approval SHALL group selected serials by product and authoritative source location under the document condition. Each serial SHALL belong to exactly one allocation, and the approver SHALL select one valid destination per source group without changing goods or substituting serials.

#### Scenario: Product has serials in two sources
- **WHEN** three requested serials of one product consist of two at location A and one at location B
- **THEN** approval presents two source groups with quantities two and one and a destination selection for each

#### Scenario: Selected serial moves before final approval
- **WHEN** a selected serial's location or eligibility differs from the reviewed configuration
- **THEN** dispatch fails without effects and requires refreshing and reviewing the affected allocation

### Requirement: Non-serialized allocations exactly cover the requested quantity
The approver SHALL use searchable source-location selectors limited to valid locations with eligible stock for the product and condition across all businesses. Each positive whole-base-unit allocation SHALL select one destination distinct from its source. The combined allocations for a product MUST equal its requested quantity, and repeated allocations sharing a source MUST be validated against aggregate consumption.

#### Scenario: Split ten units across sources
- **WHEN** the approver assigns six units from A and four from B with valid destinations
- **THEN** the ten-unit request is fully allocated

#### Scenario: Repeated source would overdraw stock
- **WHEN** two allocations draw six units each from one source with only ten eligible units
- **THEN** approval fails even if each allocation independently fits

#### Scenario: Allocation is incomplete or self-directed
- **WHEN** totals are short or excessive, a destination is unset, or a source equals its destination
- **THEN** final approval is blocked with allocation-specific guidance

#### Scenario: Long location labels stay within the workspace
- **WHEN** source or destination labels are long, or the viewport is narrow
- **THEN** allocation tables keep destination, quantity, and remove controls reachable within the card, selected labels are truncated while the open searchable list shows full labels, narrow screens scroll inside the table rather than the page, and source stock is shown below the selected source (with the tax split only for approvers who may view system stock)

### Requirement: Approval configuration is manually resumable and revision-bound
Simpan Progres SHALL persist incomplete source and destination choices with actor, time, manifest revision, and configuration revision without reserving or moving stock. Authorized approvers SHALL resume that configuration. Concurrent saves MUST reject stale revisions rather than overwrite another approver's work.

#### Scenario: Resume incomplete approval
- **WHEN** an approver saves only some destinations and later returns
- **THEN** saved choices are restored and the document remains pending without inventory reservations

#### Scenario: Concurrent approvers save
- **WHEN** another save advances configuration revision before a stale save arrives
- **THEN** the stale save is rejected and the saved configuration is preserved

### Requirement: Final approval confirms the complete reviewed plan
Setujui dan Kirim SHALL open an approver-only summary of the exact goods, serial groups, source allocations, destinations, condition, and cross-business routes. Confirmation MUST identify the reviewed manifest and configuration revisions and execute no dispatch if either changed. Approvers SHALL reject goods requiring correction with a reason rather than changing submitted quantities or serials through allocation fields.

#### Scenario: Confirm a complete plan
- **WHEN** a permitted approver confirms a complete current summary that passes live validations
- **THEN** the entire document is approved and dispatched atomically

#### Scenario: Summary becomes stale
- **WHEN** an editor or approver changes the manifest or configuration after the modal opens
- **THEN** confirmation fails without dispatch and requires a fresh summary
