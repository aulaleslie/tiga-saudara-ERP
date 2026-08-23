## Purpose

Defines deactivation, reactivation, administrative visibility, new-transaction eligibility, historical resolution, and referential-integrity behavior for transaction-linked master data (products, customers, suppliers, taxes, payment methods, payment terms, locations, units, and chart-of-account records). Replaces destructive delete actions for these records with a reversible active/inactive lifecycle that prevents new commercial use of inactive records without hiding them from historical transactions, payments, returns, reports, or audit trails.

## Requirements

### Requirement: Covered transaction master records have a reversible lifecycle
The system SHALL provide active and inactive states for products, customers, suppliers, taxes, payment methods, payment terms, locations, units, and chart-of-account records, and SHALL provide authorized users with deactivate and reactivate actions instead of destructive delete actions.

#### Scenario: Authorized user deactivates an active record
- **WHEN** an authorized user deactivates a covered active master record
- **THEN** the system marks that same record inactive without deleting it or changing its identifier

#### Scenario: Authorized user reactivates an inactive record
- **WHEN** an authorized user reactivates a covered inactive master record and its required configuration remains valid
- **THEN** the system marks that same record active and makes it eligible for applicable new transactions

#### Scenario: Delete endpoint is invoked
- **WHEN** a user invokes a legacy delete endpoint for a covered master record
- **THEN** the system SHALL not physically or soft-delete the record and SHALL apply the authorized deactivation behavior or reject the request

### Requirement: Master administration exposes lifecycle status
The system SHALL show active and inactive covered records in their administrative lists with an unambiguous status and SHALL support filtering by lifecycle status.

#### Scenario: Administrator views the default master list
- **WHEN** an administrator opens a covered master-data list
- **THEN** the system displays each record's lifecycle status and provides access to inactive records

#### Scenario: Administrator filters inactive records
- **WHEN** an administrator selects the inactive status filter
- **THEN** the system displays inactive records that the administrator is authorized to access

### Requirement: Inactive records are unavailable for new transaction choices
The system SHALL exclude inactive covered master records from selectors, searches, quick-add results, imports, APIs, and defaults used to create new transaction documents or add new transactional activity.

#### Scenario: User searches while creating a transaction
- **WHEN** a user searches for a covered master record while creating a sale, purchase, POS checkout, quotation, adjustment, transfer, expense, payment, journal, or equivalent new transaction
- **THEN** inactive records are not returned as eligible choices

#### Scenario: Default record becomes inactive
- **WHEN** a covered record configured as a default is deactivated
- **THEN** the record is not applied to a new transaction and the system either requires a valid active replacement or uses the documented no-default behavior

### Requirement: Server-side validation rejects new use of inactive records
The system SHALL validate lifecycle eligibility at the authoritative write boundary and SHALL reject an inactive master identifier introduced into a new transaction even when it came from stale UI state, a crafted request, an import, or an API call.

#### Scenario: Crafted new-transaction request uses inactive record
- **WHEN** a request attempts to create new transactional activity using an inactive covered master record
- **THEN** the system rejects the request without partially persisting the transaction

#### Scenario: Record is deactivated after selection
- **WHEN** a record is selected while active but becomes inactive before transaction submission
- **THEN** final submission revalidates the record and rejects its new use atomically

### Requirement: Existing documents preserve their inactive references
The system SHALL continue displaying a covered record already referenced by an existing transaction or draft after that record becomes inactive, without silently clearing or replacing the reference.

#### Scenario: User views a historical document
- **WHEN** a historical document references an inactive master record
- **THEN** the document displays the referenced record and its historically relevant identifying data

#### Scenario: User edits a draft with an inactive existing selection
- **WHEN** an editable draft already references a master record that is now inactive
- **THEN** the system preserves and displays that selection while excluding other inactive records from replacement choices

#### Scenario: User adds a new line to an existing draft
- **WHEN** a user adds or replaces a master-data selection on an existing draft
- **THEN** the newly introduced selection must satisfy the same active eligibility rule as a new transaction

### Requirement: Historical operations can resolve inactive records
The system SHALL allow inactive master records to be resolved when an operation is anchored to an existing source transaction, including history search, reporting, audit inspection, returns, refunds, reversals, and settlement of existing receivables or payables.

#### Scenario: Return is created from a source transaction
- **WHEN** a user creates a return or reversal from an existing transaction that references inactive master data
- **THEN** the system resolves the source references and permits the operation subject to the source transaction's normal eligibility rules

#### Scenario: Existing balance is settled
- **WHEN** a payment is recorded against an existing customer receivable or supplier payable whose party is inactive
- **THEN** the system permits settlement against that existing balance while preventing the inactive party from being selected for an unrelated new transaction

#### Scenario: Report includes inactive records
- **WHEN** a report or audit query covers transactions referencing inactive master data
- **THEN** those transactions remain present and retain usable master-data labels and grouping

### Requirement: New payment activity distinguishes document parties from payment configuration
The system SHALL allow an inactive customer or supplier inherited from an existing payable or receivable to participate in its settlement, but SHALL require any newly selected payment method or accounting destination to be active.

#### Scenario: Settle inactive customer's existing receivable
- **WHEN** a user records payment for an existing receivable belonging to an inactive customer
- **THEN** the customer reference is accepted because it is inherited from the source document

#### Scenario: Select inactive payment method for settlement
- **WHEN** a user attempts to add a payment using an inactive payment method
- **THEN** the system rejects that payment method as a new operational choice

### Requirement: Product deactivation remains distinct from product merging
The system SHALL represent ordinary product inactivity independently of `merged_into_id`, and SHALL preserve existing duplicate-product merge lineage and behavior.

#### Scenario: Discontinued unique product is deactivated
- **WHEN** an authorized user deactivates a discontinued product that is not a duplicate
- **THEN** the product becomes inactive without acquiring a merge target and its transaction references remain unchanged

#### Scenario: Merged product is encountered
- **WHEN** a product has a `merged_into_id`
- **THEN** existing merge resolution rules continue to apply independently of the product's ordinary active status

### Requirement: Historical references are protected from destructive deletion
The system SHALL prevent covered master records from being physically or soft-deleted through normal application workflows and SHALL not cascade-delete historical transaction or accounting records when lifecycle status changes.

#### Scenario: Referenced master is deactivated
- **WHEN** a covered master record with historical references is deactivated
- **THEN** all referencing transaction, payment, return, report, inventory, and accounting rows remain unchanged

#### Scenario: Unauthorized destructive deletion is attempted
- **WHEN** an application path attempts destructive deletion of a covered master record
- **THEN** the operation fails safely without deleting the master record or its historical dependents
