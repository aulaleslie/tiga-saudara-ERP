# sales-expense-detail-attachments Specification

## Purpose
Document-level attachment viewing and management on Sales and Expense detail pages, including upload, deletion, authorization, and tenant boundary enforcement.

## Requirements
### Requirement: Detail pages display document attachments
The system SHALL display the contents of the document-level `attachments` media collection on Sales and Expense detail pages. Each displayed attachment SHALL include its original filename when available, stored size, a preview action, and a download action, and the section SHALL remain visible with an empty-state message when no attachment exists.

#### Scenario: Attachments are available
- **WHEN** an authorized user views a Sales or Expense detail page whose document has attachments
- **THEN** the system lists every document-level attachment with its filename, size, preview action, and download action

#### Scenario: No attachment is available
- **WHEN** an authorized user views a Sales or Expense detail page whose document has no attachments
- **THEN** the system displays the attachment section with a "Tidak ada lampiran" empty state

### Requirement: Authorized users can upload an attachment from the detail page
The system SHALL allow a user with `sales.edit` for a Sale or `expenses.edit` for an Expense to upload one valid file at a time directly from the corresponding detail page. The system SHALL store the original file without image compression or ZIP conversion in that document's `attachments` media collection and SHALL preserve the client-visible original filename.

#### Scenario: Authorized Sale attachment upload
- **WHEN** a user with `sales.edit` uploads a valid file for a non-archived Sale belonging to the active setting
- **THEN** the system stores the unchanged file in that Sale's `attachments` collection and returns the user to the detail page with success feedback

#### Scenario: Authorized Expense attachment upload
- **WHEN** a user with `expenses.edit` uploads a valid file for a non-archived Expense belonging to the active setting
- **THEN** the system stores the unchanged file in that Expense's `attachments` collection and returns the user to the detail page with success feedback

#### Scenario: More than one file is submitted
- **WHEN** an attachment upload request contains more than one file
- **THEN** the system rejects the request without storing any submitted file

#### Scenario: Attachment validation fails
- **WHEN** the submitted attachment is absent, is not a valid uploaded file, or exceeds the configured 10 MB limit
- **THEN** the system rejects the request with validation feedback and stores no attachment

### Requirement: Authorized users can delete an attachment from the detail page
The system SHALL allow a user with the applicable module edit permission to delete an attachment from a non-archived Sales or Expense document only when the media record belongs to that exact route-bound document and its `attachments` collection.

#### Scenario: Authorized attachment deletion
- **WHEN** an authorized user deletes an attachment that belongs to the displayed non-archived document
- **THEN** the system removes the media record and stored file and returns the user to the detail page with success feedback

#### Scenario: Attachment belongs to another document
- **WHEN** a user attempts to delete a media record that belongs to another document, model type, or media collection
- **THEN** the system returns a not-found response and does not delete the media record

### Requirement: Attachment mutations respect authorization and tenant boundaries
The system MUST enforce attachment mutation authorization on the server, MUST verify that the Sales or Expense document belongs to the active setting, and MUST prohibit upload and deletion for archived documents. Attachment controls SHALL be hidden when mutation is not allowed, while existing attachments remain viewable by users authorized to view the document.

#### Scenario: User lacks edit permission
- **WHEN** a user without the applicable module edit permission attempts to upload or delete an attachment
- **THEN** the system denies the request and does not mutate attachments

#### Scenario: Document belongs to another setting
- **WHEN** a user attempts to mutate attachments on a Sales or Expense document outside the active setting
- **THEN** the system denies the request and does not mutate attachments

#### Scenario: Document is archived
- **WHEN** a user attempts to upload or delete an attachment on an archived Sales or Expense document
- **THEN** the system denies the mutation even if the user has the applicable edit permission

#### Scenario: Read-only attachment display
- **WHEN** a user may view a document but may not mutate its attachments
- **THEN** the system displays existing attachments without upload or delete controls

