## ADDED Requirements

### Requirement: Locations explicitly govern consignment custody
The system SHALL add a default-false `is_consignment` classification to locations. Consignment receiving SHALL target only an active location owned by the Consignment Receival's setting with `is_consignment = true`, and ordinary Purchase receiving SHALL NOT add stock to such a location.

#### Scenario: Consignment receiving selects an eligible location
- **WHEN** an authorized user creates receiving for an approved Consignment Receival
- **THEN** the location selector SHALL contain only consignment locations owned by that receival's setting

#### Scenario: Forged standard location is rejected
- **WHEN** a request submits a standard or foreign-setting location for consignment receiving
- **THEN** the system SHALL reject it without creating receiving records or changing stock

#### Scenario: Location classification is disabled safely
- **WHEN** a user attempts to disable consignment classification while the location has stock, an active consignment document, an active consignment serial, or unresolved consignment provenance
- **THEN** the system SHALL reject the change and preserve the location classification

### Requirement: Consignment Receival has an approval lifecycle independent from Purchase
The system SHALL provide a setting-scoped Consignment Receival document with supplier, date, reference, optional supplier delivery reference, notes, and one or more product lines. Its lifecycle SHALL be `DRAFT`, `WAITING_APPROVAL`, `APPROVED`, or `REJECTED`; it SHALL create no Purchase, payable, payment, stock, serial, or inventory transaction before receiving approval.

#### Scenario: Draft is submitted for approval
- **WHEN** an authorized user submits a valid draft containing at least one eligible line
- **THEN** its status SHALL become `WAITING_APPROVAL`
- **AND** the system SHALL record the submitting actor and time

#### Scenario: Approver approves the document
- **WHEN** an authorized approver approves a `WAITING_APPROVAL` receival in the active setting
- **THEN** its status SHALL become `APPROVED`
- **AND** its commercial, product, quantity, cost, UOM, and tax snapshots SHALL become immutable
- **AND** no inventory or payable mutation SHALL occur

#### Scenario: Approver rejects the document
- **WHEN** an authorized approver rejects a `WAITING_APPROVAL` receival with a required reason
- **THEN** its status SHALL become `REJECTED`
- **AND** the actor, time, and reason SHALL be recorded
- **AND** no inventory or payable mutation SHALL occur

#### Scenario: Rejected document is revised and resubmitted
- **WHEN** an authorized user revises a rejected receival and resubmits valid data
- **THEN** the document SHALL return to `WAITING_APPROVAL` with its prior rejection evidence retained for audit

### Requirement: Consignment lines capture fixed custody cost and tax snapshots
Each Consignment Receival line SHALL reference one existing active stock-managed non-bundle product and store positive base quantity, product/UOM identity snapshots, fixed supplier unit cost, calculated unit DPP cost, and setting-driven tax snapshots. Serialized product quantity SHALL be a whole number.

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

### Requirement: Tax context is determined by the receival setting
The system SHALL derive tax behavior exclusively from the Consignment Receival's persisted setting and approved line snapshots, not supplier, customer, or active-session PKP state. A PKP setting SHALL require valid applicable tax identity on each line; a non-PKP setting SHALL persist no tax identity or tax amount.

#### Scenario: PKP receival captures tax
- **WHEN** a PKP setting submits a receival line without a valid tax selection
- **THEN** submission SHALL fail without changing document state

#### Scenario: Non-PKP receival ignores forged tax state
- **WHEN** a non-PKP setting submits tax identity or tax amounts
- **THEN** the system SHALL persist null tax identity and zero tax amounts

#### Scenario: Setting changes after receival approval
- **WHEN** a receival was approved with a tax snapshot and the setting configuration later changes before receiving approval
- **THEN** receiving SHALL use the immutable approved tax snapshot

### Requirement: One full receiving note verifies physical custody
An approved Consignment Receival SHALL allow exactly one active Consignment Receiving Note. The note SHALL contain one detail for every receival line at the full approved base quantity and SHALL have lifecycle `PENDING`, `APPROVED`, or `REJECTED`.

#### Scenario: Full receiving is submitted
- **WHEN** an authorized user records the complete quantities and required serials for an approved receival at an eligible location
- **THEN** the system SHALL create one `PENDING` receiving note
- **AND** physical stock SHALL remain unchanged

#### Scenario: Partial receiving is attempted
- **WHEN** any submitted detail quantity differs from its approved receival-line quantity
- **THEN** the system SHALL reject the submission and instruct the operator to create a separate Consignment Receival for another delivery

#### Scenario: Second active receiving is attempted
- **WHEN** a receival already has a pending or approved receiving note
- **THEN** the system SHALL reject creation of another active receiving note

#### Scenario: Pending receiving is rejected
- **WHEN** an authorized approver rejects a pending receiving with a required reason
- **THEN** the note SHALL become `REJECTED` with immutable actor, time, and reason evidence
- **AND** stock, average cost, provenance, and serials SHALL remain unchanged

### Requirement: Receiving approval atomically establishes physical and supplier provenance
Approving a pending Consignment Receiving Note SHALL atomically increase aggregate product and location stock, apply the approved tax/non-tax quantity bucket, create a distinct consignment inventory transaction and durable source link, establish supplier receipt provenance, commit serial lineage, and mark the note approved. It SHALL NOT create a Purchase, payable, payment, or ordinary Purchase receiving record.

#### Scenario: Non-serialized receiving is approved
- **WHEN** an authorized approver approves a valid non-serialized receiving
- **THEN** physical product and location quantity SHALL increase by the approved base quantity
- **AND** the approved receiving detail SHALL establish that supplier's receipt pool for the product and location
- **AND** a `CONSIGNMENT_RECEIPT` inventory transaction SHALL retain setting, location, quantity buckets, before/after balances, actor, and source detail

#### Scenario: No payable is created
- **WHEN** consignment receiving approval succeeds
- **THEN** no Purchase, Purchase detail, Purchase payment, supplier due amount, or payment eligibility SHALL be created

#### Scenario: Failure on a later line rolls back earlier lines
- **WHEN** any product, stock, transaction, provenance, serial, cost, or status mutation fails during approval
- **THEN** every mutation from the approval SHALL roll back

### Requirement: Serialized receiving preserves exact current and historical ownership lineage
For a serialized line, receiving submission SHALL require exactly one non-empty unique serial per approved base unit. Approval SHALL create or safely reactivate serial records at the receiving location and record immutable `RECEIVED` history referencing the approved Consignment Receiving detail so that later workflows can resolve its supplier and cost source.

#### Scenario: Exact serial set is approved
- **WHEN** two approved serialized units are received with two eligible unique serials
- **THEN** both serials SHALL become active at the consignment location
- **AND** each SHALL have immutable history linked to the approved consignment source

#### Scenario: Missing or duplicate serial is rejected
- **WHEN** serial count differs from quantity or a serial duplicates another submitted or active product serial
- **THEN** submission or approval SHALL fail without partial serial or stock mutation

### Requirement: Approved consignment receiving updates only setting-scoped operational average cost
Receiving approval SHALL calculate the weighted average from the receiving setting's pre-approval physical quantity and setting-scoped average plus each approved consignment line's unit DPP cost. It SHALL seed a missing average for a new product, update only that setting's `ProductPrice.average_purchase_price`, leave every other setting and all last-purchase-price fields unchanged, and store before/after quantity and cost snapshots for audit and safe reversal.

#### Scenario: New product average is seeded
- **WHEN** the receiving setting has zero prior quantity and no positive average for a product and receives five units with unit DPP cost 100000
- **THEN** that setting's average purchase price SHALL become 100000

#### Scenario: Existing average is weighted
- **WHEN** the setting has five physical units at average 100000 and receives five units at unit DPP cost 120000
- **THEN** that setting's resulting average SHALL become 110000

#### Scenario: Other setting is unchanged
- **WHEN** Setting A approves a consignment receipt for a shared product
- **THEN** Setting B's ProductPrice average and last purchase price SHALL remain unchanged

#### Scenario: Last purchase price is unchanged
- **WHEN** consignment receiving approval succeeds
- **THEN** product-level and setting-level last purchase prices SHALL remain unchanged

### Requirement: Approved receiving supports a controlled full reversal
The system SHALL allow only an authorized user to fully reverse an approved Consignment Receiving Note with a required reason when every affected product, location stock, serial, average-cost state, and transaction remains eligible for exact reversal and no later dependent movement, sale, transfer, return, adjustment, allocation, bill, or incompatible cost event exists. Partial reversal and direct editing SHALL be prohibited.

#### Scenario: Eligible receipt is fully reversed
- **WHEN** an authorized user confirms reversal of an eligible approved receipt
- **THEN** the system SHALL atomically restore exact pre-approval stock buckets, global and setting quantities, setting-scoped average snapshots, serial state/history, and supplier provenance availability
- **AND** it SHALL create explicit reversal transaction/audit evidence rather than deleting historical approval records

#### Scenario: Later movement blocks reversal
- **WHEN** any affected product or serial has a later incompatible inventory or cost movement
- **THEN** reversal SHALL be rejected with the blocking evidence
- **AND** no state SHALL change

#### Scenario: Partial reversal is attempted
- **WHEN** a request attempts to reverse only selected lines, quantities, or serials
- **THEN** the system SHALL reject it without mutation

### Requirement: Lifecycle mutations are tenant-safe, permissioned, and concurrency-safe
Create, submit, approve, reject, receive, approve receiving, reject receiving, and reverse actions SHALL enforce dedicated permissions and the document setting boundary. Approval, rejection, and reversal SHALL lock authoritative headers and affected inventory state and revalidate lifecycle eligibility inside one database transaction.

#### Scenario: Foreign-setting user invokes an action
- **WHEN** a user directly invokes a Consignment Receival or receiving action outside the active accessible setting
- **THEN** the system SHALL deny the action without disclosing or mutating the document

#### Scenario: Concurrent receiving approvals race
- **WHEN** two users approve the same pending receiving concurrently
- **THEN** at most one approval SHALL change inventory, provenance, serials, average cost, or status

### Requirement: Phase 1 boundaries are enforced
Phase 1 SHALL support only inbound custody for existing stock-managed non-bundle products and SHALL expose no consignment sale allocation, supplier bill, payment, transfer, import, ownership conversion, outbound consignment, multiple/partial receiving, commission, or agreement workflow. Access SHALL be governed by dedicated permissions and tenant boundaries without an additional environment feature flag.

#### Scenario: User lacks consignment permission
- **WHEN** a user without the relevant consignment permission accesses a consignment menu or action
- **THEN** the menu or action SHALL be unavailable or denied
- **AND** ordinary workflows SHALL remain unchanged

#### Scenario: Unsupported operation is attempted
- **WHEN** a request attempts a Phase 1-excluded operation against consignment records or locations
- **THEN** the system SHALL reject it without mutation
