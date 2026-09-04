# supplier-name-uniqueness Specification

## Purpose
Prevent duplicate supplier records by enforcing case-insensitive, whitespace-trimmed uniqueness on `supplier_name` and `contact_name` across every supplier create, update, and import-matching entry point.

## Requirements
### Requirement: Supplier name uniqueness on create
The system SHALL reject creation of a supplier whose `supplier_name`, compared case-insensitively after trimming leading/trailing whitespace, matches the `supplier_name` of an existing supplier record, regardless of `setting_id`.

#### Scenario: Duplicate supplier_name rejected on create
- **WHEN** a user submits a new supplier with `supplier_name` "PT Maju"
- **AND** an existing supplier already has `supplier_name` "pt maju"
- **THEN** the system SHALL reject the submission with a validation error
- **AND** no new supplier record SHALL be created

#### Scenario: Duplicate supplier_name across settings rejected
- **WHEN** a user in one setting submits a new supplier with `supplier_name` "PT Maju"
- **AND** an existing supplier with `supplier_name` "PT Maju" belongs to a different `setting_id`
- **THEN** the system SHALL reject the submission with a validation error

#### Scenario: Distinct supplier_name accepted
- **WHEN** a user submits a new supplier with `supplier_name` "CV Sejahtera"
- **AND** no existing supplier has a matching `supplier_name` after trim/case normalization
- **THEN** the system SHALL create the supplier record

### Requirement: Supplier name uniqueness on update
The system SHALL reject an update that would set a supplier's `supplier_name`, compared case-insensitively after trimming, to match another existing supplier's `supplier_name`. The supplier record being updated SHALL be excluded from its own duplicate check.

#### Scenario: Update rejected when new name collides with another supplier
- **WHEN** a user edits supplier A and changes `supplier_name` to a value matching supplier B's `supplier_name` (case-insensitive, trimmed)
- **THEN** the system SHALL reject the update with a validation error

#### Scenario: Update allowed when name is unchanged
- **WHEN** a user edits supplier A and submits the same `supplier_name` A already has
- **THEN** the system SHALL allow the update to proceed
- **AND** SHALL NOT reject it as a duplicate of itself

### Requirement: Contact name uniqueness on create and update
The system SHALL reject a create or update that would set a supplier's `contact_name`, compared case-insensitively after trimming, to match another existing supplier's non-empty `contact_name`, regardless of `setting_id`. This check SHALL NOT apply when the submitted `contact_name` is empty, null, or whitespace-only.

#### Scenario: Duplicate contact_name rejected
- **WHEN** a user submits a supplier with `contact_name` "Andi Wijaya"
- **AND** an existing supplier already has `contact_name` "andi wijaya "
- **THEN** the system SHALL reject the submission with a validation error

#### Scenario: Blank contact_name never treated as duplicate
- **WHEN** a user submits a supplier with an empty `contact_name`
- **AND** other existing suppliers also have an empty `contact_name`
- **THEN** the system SHALL NOT reject the submission on the basis of `contact_name`

### Requirement: Uniqueness validation applies to all supplier write paths
The system SHALL apply supplier_name and contact_name uniqueness validation consistently across every supplier create/update/import-matching entry point: the admin Suppliers controller (create and update), the People supplier quick-add Livewire modal, the `/api/suppliers` endpoint used by the Alpine quick-add modal, and both purchase and expense import services' supplier matching.

#### Scenario: Livewire quick-add modal rejects duplicate supplier_name
- **WHEN** a user attempts to create a new supplier via the People supplier quick-add Livewire modal with a `supplier_name` matching an existing supplier (case-insensitive, trimmed)
- **THEN** the system SHALL reject the submission with a validation error

#### Scenario: API endpoint rejects duplicate supplier_name
- **WHEN** a request to `POST /api/suppliers` submits a `supplier_name` matching an existing supplier (case-insensitive, trimmed)
- **THEN** the system SHALL reject the request with a validation error

### Requirement: Import placeholder contact_name does not create false duplicates
Purchase and expense import services SHALL NOT populate `contact_name` with a shared literal placeholder value when no real contact name is available from the import source; they SHALL store null instead.

#### Scenario: Expense import without a contact name stores null
- **WHEN** an expense import creates a new supplier and the import source provides no contact name
- **THEN** the created supplier's `contact_name` SHALL be null
- **AND** SHALL NOT be a shared placeholder string

#### Scenario: Repeated imports without contact names do not collide
- **WHEN** multiple expense imports each create a new supplier with no contact name provided
- **THEN** none of these creations SHALL be rejected as a contact_name duplicate of one another
