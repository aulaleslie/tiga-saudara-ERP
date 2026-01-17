# Purchase Return Settlement — User Stories (Per-Item Approval)

## Settlement Entry & Drafts
As a returns staff member,
I want to open the settlement page from the purchase return detail,
So that I can configure settlement per item or serial.

As a returns staff member,
I want to save a settlement draft even when some lines have no method selected,
So that I can proceed while waiting for supplier decisions.

As the system,
I want to persist pending settlement lines with a null method,
So that drafts remain consistent across sessions.

## Per-Line Submission & Approval
As a returns staff member,
I want to submit a line for approval once its method is set,
So that approvers can review only ready items.

As an approver,
I want to approve or reject each submitted line from the purchase return detail page,
So that each item is controlled independently.

As a returns staff member,
I want rejected lines to reset and show the rejection reason,
So that I can correct and resubmit them quickly.

As a returns staff member,
I want submitted or approved lines to be read-only on the settlement page,
So that approved items remain consistent and auditable.

As the system,
I want pending lines to remain editable and not block approvals on other lines,
So that partial settlement can progress.

## Financial Effects & Validation
As a finance user,
I want monetary methods (modify purchase, credit, cash) to apply effects at approval time,
So that approvals immediately reflect in financial records.

As the system,
I want to validate method-specific rules at approval time (serial match, due balance, nominal limits),
So that only valid settlements are approved.

As a finance user,
I want credit and cash records to be adjusted in place as lines are approved,
So that totals are accurate without duplicate transactions.

As the system,
I want PRODUCT_REPAIR and BROKEN_STOCK approvals to skip `paid_amount` updates,
So that only monetary methods affect financial totals.

## Roll-up Status & Visibility
As a manager,
I want the purchase return status to roll up from line approvals (Settled Partially, Settled),
So that overall progress is visible without changing payment status fields.

As a user viewing lists or prints,
I want settlement methods and statuses displayed per line,
So that mixed outcomes are clear.

## Permissions & Access
As an admin,
I want permissions to gate settlement submit, per-line submit, per-line approval, and price visibility,
So that only authorized roles can act or view values.
