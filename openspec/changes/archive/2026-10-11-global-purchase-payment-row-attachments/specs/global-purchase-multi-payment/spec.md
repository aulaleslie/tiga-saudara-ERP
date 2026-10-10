# Spec Delta

## MODIFIED Requirements

### Requirement: Sample-inspired supplier multi-payment interface
The system SHALL present a supplier payment form using the existing ERP Bootstrap/CoreUI conventions and supported purchase-payment fields.

#### Scenario: Eligible supplier purchases are displayed as allocation rows
- **WHEN** the multi-payment form loads
- **THEN** it lists non-archived purchases with the starting purchase's exact `supplier_id`, a status of `RECEIVED PARTIALLY`, `RECEIVED`, or `RETURNED PARTIALLY`, and a positive current outstanding balance
- **AND** the candidate query does not apply a `setting_id` restriction
- **AND** each row displays transaction number, description, due date, total, outstanding balance, an editable payment amount, and a multiple-file attachment control for that purchase

#### Scenario: Ineligible starting purchase is rejected
- **WHEN** the requested starting purchase is archived, has a status other than `RECEIVED PARTIALLY`, `RECEIVED`, or `RETURNED PARTIALLY`, or has no positive current outstanding balance
- **THEN** the system does not render a payable allocation form
- **AND** no purchase payment is created

### Requirement: Shared attachment is replicated to every payment
For new global purchase payment submissions, the system SHALL accept zero or more supported attachments for each purchase allocation row and SHALL associate each accepted file only with the `PurchasePayment` generated for that row. The system MUST NOT replicate a submission-level attachment across generated payments. Existing payments and their stored attachments MUST remain unchanged.

#### Scenario: Different purchases have different files
- **WHEN** a valid submission allocates positive amounts to multiple purchases and each row has its own files
- **THEN** each generated payment contains only the files submitted on its corresponding purchase row
- **AND** each file remains separately accessible through that payment's existing attachment views

#### Scenario: One row has multiple files
- **WHEN** a positive allocation row has several accepted files
- **THEN** its generated payment contains a distinct attachment for each file
- **AND** other generated payments do not receive those files

#### Scenario: Positive allocation without files
- **WHEN** a valid positive allocation row has no attachments
- **THEN** its payment is created without media
- **AND** other rows may independently have attachments

#### Scenario: Files on a zero-amount row
- **WHEN** any row has staged attachments but its payment amount is zero
- **THEN** the complete submission is rejected with an error identifying that purchase row
- **AND** no payment from the submission is committed

#### Scenario: Unsupported or duplicated staged file reference
- **WHEN** a submitted row references an invalid file, or the same staged file is assigned more than once in the submission
- **THEN** the complete submission is rejected
- **AND** no payment from the submission is committed

#### Scenario: Attachment storage failure leaves no partial result
- **WHEN** storing an attachment for any generated payment fails
- **THEN** no payment from the submission remains committed
- **AND** media files and records already prepared for that failed submission are cleaned up

#### Scenario: Existing global payment remains intact
- **WHEN** a user views a global purchase payment created before this change
- **THEN** its previously stored attachments remain associated with and accessible from that payment

#### Scenario: Attachment is copied to all generated payments
- **WHEN** a new multi-purchase submission has files on one positive allocation row
- **THEN** those files are attached only to that row's generated payment
- **AND** they are not copied to other generated payments

#### Scenario: Submission without attachment remains valid
- **WHEN** a valid multi-purchase submission contains no attachments on any row
- **THEN** all allocated payments are created without media
- **AND** payment creation otherwise follows the same behavior

#### Scenario: Attachment replication failure leaves no partial result
- **WHEN** attachment storage fails during a new multi-purchase submission
- **THEN** no payment from the submission remains committed
- **AND** any media files or records already prepared for that failed submission are cleaned up
