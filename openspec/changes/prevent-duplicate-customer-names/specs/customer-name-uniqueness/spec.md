## ADDED Requirements

### Requirement: Customer name uniqueness on create
The system SHALL reject creation of a customer whose `customer_name`, compared case-insensitively after trimming leading/trailing whitespace, matches the `customer_name` of an existing customer record, regardless of `setting_id`.

#### Scenario: Duplicate customer_name rejected on create
- **WHEN** a user submits a new customer with `customer_name` "Toko ABC"
- **AND** an existing customer already has `customer_name` "toko abc"
- **THEN** the system SHALL reject the submission with a validation error
- **AND** no new customer record SHALL be created

#### Scenario: Duplicate customer_name across settings rejected
- **WHEN** a user in one setting submits a new customer with `customer_name` "Toko ABC"
- **AND** an existing customer with `customer_name` "Toko ABC" belongs to a different `setting_id`
- **THEN** the system SHALL reject the submission with a validation error

#### Scenario: Distinct customer_name accepted
- **WHEN** a user submits a new customer with `customer_name` "Toko XYZ"
- **AND** no existing customer has a matching `customer_name` after trim/case normalization
- **THEN** the system SHALL create the customer record

### Requirement: Customer name uniqueness on update
The system SHALL reject an update that would set a customer's `customer_name`, compared case-insensitively after trimming, to match another existing customer's `customer_name`. The customer record being updated SHALL be excluded from its own duplicate check.

#### Scenario: Update rejected when new name collides with another customer
- **WHEN** a user edits customer A and changes `customer_name` to a value matching customer B's `customer_name` (case-insensitive, trimmed)
- **THEN** the system SHALL reject the update with a validation error

#### Scenario: Update allowed when name is unchanged
- **WHEN** a user edits customer A and submits the same `customer_name` A already has
- **THEN** the system SHALL allow the update to proceed
- **AND** SHALL NOT reject it as a duplicate of itself

### Requirement: Contact name uniqueness on create and update
The system SHALL reject a create or update that would set a customer's `contact_name`, compared case-insensitively after trimming, to match another existing customer's non-empty `contact_name`, regardless of `setting_id`. This check SHALL NOT apply when the submitted `contact_name` is empty, null, or whitespace-only.

#### Scenario: Duplicate contact_name rejected
- **WHEN** a user submits a customer with `contact_name` "Budi Santoso"
- **AND** an existing customer already has `contact_name` "budi santoso "
- **THEN** the system SHALL reject the submission with a validation error

#### Scenario: Blank contact_name never treated as duplicate
- **WHEN** a user submits a customer with an empty `contact_name`
- **AND** other existing customers also have an empty `contact_name`
- **THEN** the system SHALL NOT reject the submission on the basis of `contact_name`

### Requirement: Uniqueness validation applies to all customer write paths
The system SHALL apply customer_name and contact_name uniqueness validation consistently across every customer create/update entry point: the admin Customers controller (create and update), the Customer quick-add Livewire modal, the People quick-add Livewire modal, and the POS customer quick-create endpoint.

#### Scenario: POS quick-create rejects duplicate customer_name
- **WHEN** a cashier attempts to create a new customer from the POS sell page with a `customer_name` matching an existing customer (case-insensitive, trimmed)
- **THEN** the system SHALL reject the request with a validation error

#### Scenario: Livewire quick-add modal rejects duplicate customer_name
- **WHEN** a user attempts to create a new customer via either Livewire quick-add modal with a `customer_name` matching an existing customer (case-insensitive, trimmed)
- **THEN** the system SHALL reject the submission with a validation error
