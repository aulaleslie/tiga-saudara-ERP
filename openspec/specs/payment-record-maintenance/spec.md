## ADDED Requirements

### Requirement: Payment details are read-only financial records
The system SHALL present normal setting-scoped sales and purchase payment details in read-only mode after creation. The payment amount, date, reference, payment method, parent document, attachment, status, and invalidation metadata MUST NOT be user-editable through either the interface or a direct update request.

#### Scenario: User opens an active payment
- **WHEN** an authorized user selects View for a sales or purchase payment
- **THEN** the system displays the payment's financial and supporting details without editable financial controls
- **AND** the action is represented as View rather than Edit

#### Scenario: User submits immutable fields to the note update endpoint
- **WHEN** an authorized user submits a note update containing different amount, date, reference, payment method, parent document, attachment, status, or invalidation values
- **THEN** the system updates only the validated note
- **AND** every immutable payment field retains its stored value

### Requirement: Authorized users can modify only active payment notes
The system SHALL allow a user with the applicable sales-payment or purchase-payment edit permission to modify the note of an active payment belonging to a non-archived parent document in the active setting. The note MUST be validated as nullable text with a maximum length of 1000 characters, and an empty note MUST be stored consistently as null.

#### Scenario: Authorized user updates an active payment note
- **WHEN** an authorized user opens an active payment for a non-archived parent document in the active setting and saves a valid note
- **THEN** the system persists the normalized note
- **AND** returns the user to a payment detail or payment history context with success feedback

#### Scenario: User lacks payment edit permission
- **WHEN** a user who can view payment history but lacks the applicable payment edit permission opens a payment detail
- **THEN** the payment remains viewable in read-only mode
- **AND** the note modification control is absent
- **AND** a direct note update request is forbidden

#### Scenario: Payment or parent is not maintainable
- **WHEN** a user attempts to modify the note of an invalidated payment or a payment whose parent document is archived
- **THEN** the system rejects the modification
- **AND** the stored note remains unchanged

### Requirement: Payment history tables expose notes
The system SHALL include a Catatan column in the normal sales-payment and purchase-payment history tables and SHALL source that value directly from each payment record without requiring additional per-row queries.

#### Scenario: Payment has a note
- **WHEN** a payment-history row represents a payment with a note
- **THEN** the row displays the note as escaped text
- **AND** the note is available to supported table export and print operations

#### Scenario: Payment has no note
- **WHEN** a payment-history row represents a payment without a note
- **THEN** the Catatan column displays a consistent empty-value marker

### Requirement: Eligible payments can be deleted immediately
The system SHALL allow a user with the applicable payment-delete permission to delete an eligible active or manually invalidated sales or purchase payment without requiring a separate invalidation step. A payment MUST be ineligible for direct deletion when it has linked credit applications, protected automated invalidation lineage, or another dependent settlement record whose removal is not explicitly handled atomically.

#### Scenario: Authorized user deletes an eligible active payment
- **WHEN** an authorized user confirms deletion of an eligible active sales or purchase payment on a non-archived parent document in the active setting
- **THEN** the payment is removed immediately
- **AND** the user receives success feedback

#### Scenario: Payment has protected dependencies
- **WHEN** a user attempts to delete a payment with linked credit applications, protected automated invalidation lineage, or an unsupported dependent settlement record
- **THEN** the system rejects the deletion
- **AND** neither the payment nor its dependent records are changed

#### Scenario: Payment belongs to an archived parent
- **WHEN** a user attempts to delete a payment whose sale or purchase is archived
- **THEN** the system rejects the deletion
- **AND** the payment and parent balances remain unchanged

### Requirement: Payment deletion canonically reconciles the parent balance
The system MUST delete the eligible payment and recalculate the parent document within one database transaction. Paid amount MUST be derived from the remaining active payments using the parent model's effective-payment semantics, due amount MUST be the non-negative difference between total amount and effective paid amount, and payment status MUST be derived consistently as unpaid, partial, or paid.

#### Scenario: Deleting one of several active payments
- **WHEN** an eligible payment is deleted and other active payments remain
- **THEN** the parent paid amount equals the effective sum of the remaining active payments and supported credit applications
- **AND** the due amount and payment status reflect that recalculated paid amount

#### Scenario: Deleting the last active payment
- **WHEN** an eligible last active payment is deleted and no effective payments remain
- **THEN** the parent paid amount is zero
- **AND** the parent due amount equals its total amount
- **AND** the parent payment status is unpaid

#### Scenario: Reconciliation fails
- **WHEN** payment deletion or parent balance reconciliation fails
- **THEN** the transaction is rolled back
- **AND** the payment and parent balance fields remain unchanged

### Requirement: Payment maintenance enforces parent and setting ownership
The system MUST authorize payment detail, note update, and deletion against the payment's actual parent document, require any parent identifier in the route to match that relationship, and preserve the normal active-setting boundary.

#### Scenario: Route parent does not own the payment
- **WHEN** a user requests a payment through a different sale or purchase identifier
- **THEN** the system rejects the request without disclosing or modifying the payment

#### Scenario: Payment belongs to another setting
- **WHEN** a user requests normal payment maintenance for a payment whose parent belongs to a setting other than the active setting
- **THEN** the system rejects the request without disclosing or modifying the payment

### Requirement: Automated payment invalidation remains available
The system SHALL preserve active and invalidated payment states and the existing automated sale-payment invalidation and replacement behavior used by POS Return and other settlement correction workflows. Removing manual monetary editing or the manual two-step purchase invalidation interface MUST NOT change those automated workflows.

#### Scenario: Return workflow adjusts active sale payments
- **WHEN** an existing POS Return workflow invokes sale-payment invalidation or active-payment reconciliation
- **THEN** the workflow continues to invalidate, split, or replace payment records according to the existing sale-payment invalidation requirements
- **AND** the resulting active payment total remains authoritative for the sale balance

