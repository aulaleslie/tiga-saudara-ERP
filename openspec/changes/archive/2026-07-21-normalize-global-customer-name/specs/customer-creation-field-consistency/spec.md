## ADDED Requirements

### Requirement: Canonical customer-name persistence
Every non-import customer creation and editing entry point SHALL require and persist a non-empty `customer_name` as the canonical customer identity. This SHALL include the full Customer CRUD form, Sales create/edit customer quick-add, legacy customer quick-add, and POS customer creation.

#### Scenario: Create customer from Sales quick-add
- **WHEN** a user creates a customer from the customer quick-add used by Sales create or Sales edit
- **THEN** the supplied customer identity SHALL be stored in `customer_name`
- **AND** an omitted contact person SHALL be stored as null or remain unset

#### Scenario: Create customer from POS
- **WHEN** a cashier creates a customer using the POS form that collects only one customer name
- **THEN** the supplied name SHALL be stored in `customer_name`
- **AND** `contact_name` SHALL remain null or unset

#### Scenario: Create customer from full Customer form
- **WHEN** an authorized user submits the full Customer creation form
- **THEN** a non-empty `customer_name` SHALL be required and persisted
- **AND** the customer SHALL be created successfully without `contact_name`

#### Scenario: Edit canonical customer name
- **WHEN** an authorized user changes `customer_name` in the full Customer edit form
- **THEN** the updated canonical name SHALL be validated and persisted

### Requirement: Contact name is optional
The customer database and every non-import customer creation and editing validator SHALL permit `contact_name` to be null or empty. Persistence code MUST NOT copy `customer_name` into `contact_name` solely to satisfy display code.

#### Scenario: Omit contact name on create
- **WHEN** a customer is created with a valid `customer_name` and no contact person
- **THEN** validation SHALL succeed
- **AND** the persisted `contact_name` SHALL be null or unset rather than a copy of `customer_name`

#### Scenario: Clear contact name on edit
- **WHEN** an authorized user clears `contact_name` while retaining a valid `customer_name`
- **THEN** validation SHALL succeed
- **AND** the customer SHALL retain the canonical `customer_name`

### Requirement: Existing records are preserved during normalization
The system MUST implement the canonical-name contract without a destructive customer backfill, merge, or rewrite. Existing `customer_name`, `contact_name`, and `setting_id` values SHALL remain unchanged unless an authorized user edits that customer through an existing workflow.

#### Scenario: Deploy normalization with historical customers
- **WHEN** the change is deployed with existing customer records
- **THEN** deployment SHALL require no customer-table data migration
- **AND** existing customer field values SHALL remain unchanged

## REMOVED Requirements

### Requirement: POS customer name populates contact_name
**Reason**: Copying the canonical name into `contact_name` was a workaround for readers that incorrectly displayed only `contact_name`. It obscures the distinct optional-contact meaning and can produce duplicate labels.

**Migration**: Readers now use `customer_name` as canonical with an empty-safe compatibility fallback. Existing duplicated values are preserved and handled without a data rewrite; future creation paths leave an omitted `contact_name` null or unset.
