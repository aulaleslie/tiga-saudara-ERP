## ADDED Requirements

### Requirement: Active Purchase supplier number uniqueness
The system SHALL reject a non-empty `supplier_purchase_number` when another unarchived Purchase in the same `setting_id` has the same value. An archived Purchase SHALL not reserve its supplier purchase number. The rule SHALL exclude the document being edited and SHALL apply to Purchase creation, ordinary edits, and the authorized document-level supplier-number correction flow.

#### Scenario: Active same-setting Purchase blocks creation
- **WHEN** a user submits a new Purchase with a supplier purchase number held by another unarchived Purchase in the selected setting
- **THEN** the system SHALL reject the field as a duplicate and SHALL not create the Purchase

#### Scenario: Archived Purchase releases its supplier number
- **WHEN** a user submits a new Purchase with a supplier purchase number held only by archived Purchases in the selected setting
- **THEN** the system SHALL accept the number subject to all other validation rules

#### Scenario: Edit excludes the current Purchase
- **WHEN** a user saves an eligible Purchase without changing its non-empty supplier purchase number
- **THEN** the system SHALL not treat that Purchase as a duplicate of itself

#### Scenario: Active same-setting Purchase blocks correction
- **WHEN** an authorized user changes a Purchase's supplier purchase number through the document-level correction flow to a value held by another unarchived Purchase in the same setting
- **THEN** the system SHALL reject the correction and preserve the existing value

#### Scenario: Archived conflict does not block correction
- **WHEN** an authorized user changes a Purchase's supplier purchase number through the document-level correction flow to a value held only by archived Purchases in the same setting
- **THEN** the system SHALL accept the correction subject to the flow's existing authorization and lifecycle restrictions

### Requirement: Canonical external customer sales number
The system SHALL use `sales.imported_sales_reference_number` as the sole persisted external customer sales number. The system SHALL not introduce a separate customer sales number field. When the value is present, Sale detail presentation SHALL display it as the external customer sales/invoice number alongside the internal Sale reference.

#### Scenario: Imported customer invoice is presented as the customer sales number
- **WHEN** a user views a Sale with a non-empty `imported_sales_reference_number`
- **THEN** the detail view SHALL show that value using customer sales/invoice terminology while retaining the internal Sale reference

#### Scenario: Manual Sale has no external customer number
- **WHEN** a user views a Sale whose `imported_sales_reference_number` is empty
- **THEN** the detail view SHALL continue to show the internal Sale reference without displaying an empty external-number value

### Requirement: Imports ignore archived external-number conflicts
Purchase and Sales import duplicate detection SHALL compare external document numbers only against unarchived documents within the resolved setting. An archived matching document SHALL not cause an imported Purchase or Sale to be skipped as a duplicate.

#### Scenario: Purchase import reuses an archived supplier invoice number
- **WHEN** an incoming Purchase import has a supplier invoice number that exists only on archived Purchases in the resolved setting
- **THEN** the import SHALL create the Purchase instead of marking the rows as duplicate

#### Scenario: Sales import reuses an archived customer invoice number
- **WHEN** an incoming Sales import has an external customer invoice number that exists only on archived Sales in the resolved setting
- **THEN** the import SHALL create the Sale instead of marking the rows as duplicate

#### Scenario: Active external-number conflict is still skipped
- **WHEN** an incoming Purchase or Sales import has an external document number held by an unarchived document in the resolved setting
- **THEN** the import SHALL retain its existing duplicate-skip behavior
