# expense-approval-workflow Specification

## Purpose
TBD - created by archiving change harden-expense-approval-workflow. Update Purpose after archive.
## Requirements
### Requirement: Expense transactions are setting scoped
The system SHALL enforce current setting ownership for every expense transaction read and mutation, independent of global expense category and tax master data.

#### Scenario: Cross-setting expense show is blocked
- **WHEN** a user in setting A requests the show page for an expense owned by setting B
- **THEN** the system MUST deny access and MUST NOT disclose the expense details

#### Scenario: Cross-setting expense mutation is blocked
- **WHEN** a user in setting A attempts to edit, update, delete, submit, approve, reject, archive, or mutate attachments for an expense owned by setting B
- **THEN** the system MUST deny the mutation and MUST leave the expense unchanged

#### Scenario: Global category can be used by current setting expense
- **WHEN** a user creates an expense in the current setting using a global expense category
- **THEN** the system MUST store the expense with the current `setting_id` and the selected global `category_id`

### Requirement: Expense lifecycle is enforced
The system SHALL manage expenses through uppercase stored statuses `DRAFT`, `SUBMITTED`, `APPROVED`, and `REJECTED`, with archive represented by archive metadata rather than a status value.

#### Scenario: Save draft
- **WHEN** a user with `expenses.create` saves a new valid expense as draft
- **THEN** the system MUST store the expense with status `DRAFT`, current `setting_id`, generated reference, detail rows, and attachments

#### Scenario: Submit for approval
- **WHEN** a user with `expenses.create` or `expenses.edit` submits a valid draft expense for approval
- **THEN** the system MUST set the expense status to `SUBMITTED`

#### Scenario: Approve submitted expense
- **WHEN** a user with `expenses.approval` in the current setting approves a submitted expense
- **THEN** the system MUST set the expense status to `APPROVED`

#### Scenario: Reject submitted expense with reason
- **WHEN** a user with `expenses.approval` in the current setting rejects a submitted expense with a reason
- **THEN** the system MUST set the expense status to `REJECTED` and store the rejection reason

#### Scenario: Reject without reason is blocked
- **WHEN** a user attempts to reject a submitted expense without a reason
- **THEN** the system MUST reject the action and MUST keep the expense status unchanged

### Requirement: Expense edit and delete rules are lifecycle aware
The system SHALL allow edits and deletes only when the expense lifecycle permits them.

#### Scenario: Draft expense can be edited
- **WHEN** a user with `expenses.edit` edits a draft expense in the current setting
- **THEN** the system MUST allow header, detail row, tax, and attachment changes

#### Scenario: Rejected expense returns to draft when edited
- **WHEN** a user with `expenses.edit` updates a rejected expense in the current setting
- **THEN** the system MUST set the expense status to `DRAFT` and MUST keep the rejection reason visible for user context

#### Scenario: Submitted expense cannot be edited
- **WHEN** a user attempts to edit a submitted expense
- **THEN** the system MUST deny changes to header, details, tax, and attachments

#### Scenario: Approved expense cannot be edited
- **WHEN** a user attempts to edit an approved expense
- **THEN** the system MUST deny changes and direct the user to archive the expense if it should be removed from normal operations

#### Scenario: Draft and rejected expenses can be hard deleted
- **WHEN** a user with `expenses.delete` deletes a draft or rejected expense in the current setting
- **THEN** the system MUST hard delete the expense and its cascaded detail rows

#### Scenario: Submitted and approved expenses cannot be hard deleted
- **WHEN** a user attempts to hard delete a submitted or approved expense
- **THEN** the system MUST deny hard deletion and MUST require the archive workflow instead

### Requirement: Expense archive preserves records and hides normal operations
The system SHALL archive submitted and approved expenses through archive metadata and hide archived expenses from normal lists and reports.

#### Scenario: Archive submitted expense
- **WHEN** a user with `expenses.archive` archives a submitted expense in the current setting
- **THEN** the system MUST set `archived_at` and `archived_by`, hide the expense from normal lists, and leave the lifecycle status unchanged

#### Scenario: Archive approved expense with reason
- **WHEN** a user with `expenses.archive` archives an approved expense with a reason
- **THEN** the system MUST set `archived_at`, `archived_by`, and `archive_reason`, hide the expense from normal lists, and exclude it from normal reports

#### Scenario: Archive approved expense without reason is blocked
- **WHEN** a user attempts to archive an approved expense without a reason
- **THEN** the system MUST reject the action and MUST leave the expense unarchived

### Requirement: Expense show page supports review actions
The system SHALL provide a dedicated expense show page for read-only review of expense header, detail rows, tax totals, attachments, status, and permitted lifecycle actions.

#### Scenario: Show page displays expense details
- **WHEN** an authorized user opens the expense show page
- **THEN** the system MUST display the expense header, category, date, reference, detail rows, tax totals, attachments, status label, rejection reason when present, and available actions

#### Scenario: Show page exposes permitted actions
- **WHEN** an authorized user views an expense
- **THEN** the system MUST show only actions allowed by the user's permissions, current setting role, expense status, and archive state

#### Scenario: Indonesian status labels are shown
- **WHEN** the UI displays an expense status
- **THEN** the system MUST map the stored uppercase status to an Indonesian user-facing label

### Requirement: Expense persistence is shared across write paths
The system SHALL apply the same validation, normalization, setting ownership checks, lifecycle rules, detail persistence, tax behavior, and attachment handling to Livewire and controller expense write paths.

#### Scenario: Controller create accepts structured details
- **WHEN** a controller `POST /expenses` request submits `date`, `category_id`, `details[]`, optional files, and a draft-or-submit action
- **THEN** the system MUST persist the same result as the Livewire form for the same payload

#### Scenario: Row amounts must be positive
- **WHEN** a user submits an expense detail row with amount less than or equal to zero
- **THEN** the system MUST reject the expense and MUST report a validation error

#### Scenario: Expense total is computed from details and tax rules
- **WHEN** a user submits multiple valid expense detail rows
- **THEN** the system MUST compute the expense amount from detail amounts and applicable tax rules rather than trusting a client-supplied header total

#### Scenario: Non-tax setting hides tax fields
- **WHEN** the current setting does not allow tax usage for expenses
- **THEN** the system MUST hide tax controls and MUST persist expense detail tax values as null

### Requirement: Expense references are setting scoped
The system SHALL generate expense references at draft creation using the current setting document prefix and enforce uniqueness per setting.

#### Scenario: Reference uses setting document prefix
- **WHEN** a new expense is created in a setting with document prefix `TNC`
- **THEN** the generated reference MUST begin with `TNC-EXP-`

#### Scenario: Reference sequence is scoped per setting and month
- **WHEN** two settings create expenses in the same month
- **THEN** each setting MUST receive its own monthly expense reference sequence

#### Scenario: Duplicate reference is rejected
- **WHEN** a persistence attempt would create a duplicate `setting_id` and `reference` pair
- **THEN** the system MUST prevent the duplicate and retry or surface a safe validation failure

### Requirement: Expense reports include only approved active expenses
The system SHALL include only approved, non-archived expenses in normal reports, dashboards, and payment-sent totals.

#### Scenario: Draft expense is excluded from reports
- **WHEN** an expense has status `DRAFT`
- **THEN** normal reports MUST NOT include its amount

#### Scenario: Submitted expense is excluded from reports
- **WHEN** an expense has status `SUBMITTED`
- **THEN** normal reports MUST NOT include its amount

#### Scenario: Approved expense is included in reports
- **WHEN** an expense has status `APPROVED` and is not archived
- **THEN** normal reports MUST include its amount

#### Scenario: Archived approved expense is excluded from reports
- **WHEN** an approved expense has `archived_at` set
- **THEN** normal reports MUST NOT include its amount

### Requirement: Expense category global rules are preserved
The system SHALL treat expense categories as global master data while applying current-setting usage display and global delete protection.

#### Scenario: Category list shows global categories
- **WHEN** a user opens the expense category list or expense category dropdown
- **THEN** the system MUST show global expense categories regardless of category `setting_id`

#### Scenario: Category usage count reflects current setting
- **WHEN** a user views the expense category list from a setting
- **THEN** the category usage count shown in the list MUST count expenses from the current setting

#### Scenario: Used category cannot be deleted globally
- **WHEN** a user attempts to delete an expense category used by any expense in any setting
- **THEN** the system MUST block deletion

### Requirement: Expense detail suggestions use approved history
The system SHALL provide free-text expense detail suggestions from approved historical expense details without converting details into products or inventory items.

#### Scenario: Approved detail name appears as suggestion
- **WHEN** a user types into an expense detail name field
- **THEN** the system MUST provide matching suggestions from approved expense details when matches exist

#### Scenario: Non-approved detail name is excluded from suggestions
- **WHEN** a matching detail name exists only on draft, submitted, rejected, or archived expenses
- **THEN** the system MUST NOT show that name as a suggestion

### Requirement: Existing expense data is migrated safely
The system SHALL preserve existing expense behavior by migrating existing expenses to approved status and backfilling structured and legacy detail data where useful.

#### Scenario: Existing expense becomes approved
- **WHEN** the migration runs against existing expense rows
- **THEN** each existing expense MUST receive status `APPROVED` unless it already has a valid lifecycle status

#### Scenario: Legacy details are preserved
- **WHEN** an existing expense has legacy `expenses.details` text but no structured detail rows
- **THEN** the system MUST create a compatible structured detail row or otherwise preserve the legacy text for display

#### Scenario: Structured details can backfill legacy summary
- **WHEN** an existing expense has structured detail rows and blank legacy `expenses.details`
- **THEN** the system MUST populate a useful legacy details summary without changing the structured amounts

### Requirement: Expense supplier assignment
The system SHALL allow expenses to store an optional supplier while preserving existing expense lifecycle and setting ownership rules.

#### Scenario: New expense can be saved with supplier
- **WHEN** a user creates a valid expense and selects a supplier from the current setting
- **THEN** the system MUST persist the supplier on the expense
- **AND** the expense lifecycle status, detail rows, tax handling, attachments, and reference generation MUST behave as before

#### Scenario: Expense can be saved without supplier
- **WHEN** a user creates or edits a valid expense without selecting a supplier
- **THEN** the system MUST persist the expense with no supplier
- **AND** the expense MUST remain valid

#### Scenario: Supplier from another setting is rejected
- **WHEN** a user attempts to save an expense with a supplier that does not belong to the current setting
- **THEN** the system MUST reject the save
- **AND** the expense MUST remain unchanged

#### Scenario: Expense review displays supplier
- **WHEN** an authorized user views an expense show page or expense list row
- **THEN** the system MUST display the assigned supplier when present
- **AND** the system MUST display a placeholder when no supplier is assigned

### Requirement: Expense tag assignment
The system SHALL allow expenses to store optional tags using the existing tag infrastructure.

#### Scenario: New expense can be saved with tags
- **WHEN** a user creates a valid expense and selects one or more tags
- **THEN** the system MUST persist those tags on the expense
- **AND** the expense lifecycle status, detail rows, tax handling, attachments, and reference generation MUST behave as before

#### Scenario: Expense edit syncs tags
- **WHEN** a user edits a draft or rejected expense and changes its selected tags
- **THEN** the system MUST sync the expense tags to exactly the submitted set

#### Scenario: Submitted and approved expense tag mutation follows existing edit rules
- **WHEN** a user attempts to change tags on a submitted or approved expense through the normal edit flow
- **THEN** the system MUST enforce the same edit restrictions that apply to expense header and detail changes

#### Scenario: Expense review displays tags
- **WHEN** an authorized user views an expense show page or expense list row
- **THEN** the system MUST display the assigned tags when present

### Requirement: Expense persistence includes supplier and tags across write paths
The system SHALL apply supplier and tag validation and persistence consistently across Livewire and controller expense write paths.

#### Scenario: Livewire create persists supplier and tags
- **WHEN** the Livewire expense form submits a valid expense with supplier and tags
- **THEN** the saved expense MUST include the selected supplier and tags

#### Scenario: Controller create persists supplier and tags
- **WHEN** a controller expense create request submits a valid expense with supplier and tags
- **THEN** the saved expense MUST include the selected supplier and tags

#### Scenario: Validation failure does not partially sync tags
- **WHEN** an expense save fails validation after tags were submitted
- **THEN** the system MUST NOT partially persist tag changes for that expense

### Requirement: Existing expenses remain compatible
The system SHALL preserve existing expenses when supplier and tag support is introduced.

#### Scenario: Existing expense has no supplier by default
- **WHEN** the supplier migration runs against existing expenses
- **THEN** existing expense rows MUST remain valid with `supplier_id` unset

#### Scenario: Existing expense has no tags by default
- **WHEN** tag support is enabled for expenses
- **THEN** existing expense rows MUST remain valid without assigned tags

