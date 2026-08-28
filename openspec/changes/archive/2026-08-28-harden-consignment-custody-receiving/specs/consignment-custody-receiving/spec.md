## MODIFIED Requirements

### Requirement: Locations explicitly govern consignment custody
The system SHALL add a default-false `is_consignment` classification to locations. Consignment receiving SHALL target only an active location owned by the Consignment Receival's setting with `is_consignment = true`, and ordinary Purchase receiving SHALL NOT add stock to such a location. The location selector, pending-receiving construction, and receiving approval SHALL independently enforce the active setting-owned consignment classification.

#### Scenario: Consignment receiving selects an eligible location
- **WHEN** an authorized user creates receiving for an approved Consignment Receival
- **THEN** the location selector SHALL contain only active consignment locations owned by that receival's setting

#### Scenario: Forged standard, inactive, or foreign location is rejected
- **WHEN** a request submits a standard, inactive, or foreign-setting location for consignment receiving
- **THEN** the system SHALL reject it without creating receiving records or changing stock

#### Scenario: Location becomes inactive before approval
- **WHEN** a pending Consignment Receiving references a location that is inactive at approval time
- **THEN** approval SHALL fail without changing stock, cost, provenance, serials, transactions, or receiving status

#### Scenario: Location classification is disabled safely
- **WHEN** a user attempts to disable consignment classification while the location has stock, an active consignment document, an active consignment serial, unresolved receipt provenance, a discovered sold source, or an allocation dependency
- **THEN** the system SHALL reject the change and preserve the location classification
- **AND** the response SHALL identify the dependency category preventing reclassification

### Requirement: Consignment lines capture fixed custody cost and tax snapshots
Each Consignment Receival line SHALL reference one existing active stock-managed non-bundle product and store positive base quantity, product/UOM identity snapshots, fixed supplier unit cost, calculated unit DPP cost, and setting-driven tax snapshots. Serialized product quantity SHALL be a whole number. A Consignment Receival SHALL contain at most one line for each product so approval and full reversal evidence remain deterministic.

#### Scenario: New product without average cost is accepted
- **WHEN** an otherwise eligible product has no positive setting-scoped average purchase price
- **THEN** the Consignment Receival SHALL accept a positive supplier unit cost
- **AND** the approved receiving SHALL be able to seed the setting-scoped average from that cost

#### Scenario: Bundle or non-stock product is rejected
- **WHEN** a line references a bundle, service, or other non-stock-managed product
- **THEN** validation SHALL reject the line without saving or approving the document

#### Scenario: Serialized quantity is fractional
- **WHEN** a serialized product line has a fractional base quantity
- **THEN** validation SHALL reject the line

#### Scenario: Product appears more than once
- **WHEN** a create or edit request contains duplicate lines for the same product
- **THEN** validation SHALL reject the document with an actionable duplicate-product error
- **AND** no header, line, receiving, stock, cost, or provenance mutation SHALL persist

### Requirement: Lifecycle mutations are tenant-safe, permissioned, and concurrency-safe
Create, edit, delete, submit, approve, reject, receive, approve receiving, reject receiving, and reverse actions SHALL enforce dedicated permissions and the active document setting boundary. Edit, deletion, approval, rejection, receiving approval, and reversal SHALL lock authoritative headers and affected state and revalidate lifecycle eligibility inside one database transaction. Read surfaces and filter options SHALL expose only records and master data belonging to the active setting.

#### Scenario: Foreign-setting user invokes an action
- **WHEN** a user directly invokes a Consignment Receival or receiving action outside the active accessible setting
- **THEN** the system SHALL deny the action without disclosing or mutating the document

#### Scenario: Supplier filter is rendered
- **WHEN** an authorized user opens a Phase 1 Consignment Receival list in one setting
- **THEN** supplier filter options SHALL contain only suppliers owned by that setting

#### Scenario: Edit races with submission
- **WHEN** an edit and submission target the same draft or rejected Receival concurrently
- **THEN** the locked operation that commits first SHALL determine authoritative state
- **AND** the later operation SHALL revalidate that state and fail if editing is no longer allowed
- **AND** submitted line evidence SHALL not be replaced

#### Scenario: Deletion races with submission
- **WHEN** deletion and submission target the same draft Receival concurrently
- **THEN** at most one operation SHALL succeed
- **AND** a submitted Receival SHALL not be deleted

#### Scenario: Concurrent receiving approvals race
- **WHEN** two users approve the same pending receiving concurrently
- **THEN** at most one approval SHALL change inventory, provenance, serials, average cost, or status
