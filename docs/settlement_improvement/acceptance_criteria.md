# Acceptance Criteria: Purchase Return Settlement Improvement

## Ticket 1: Remove Cash Settlement Option (UI + Validation)
Scenario: Cash method is not selectable (happy path)
Given a user opens the per-item settlement method dropdown
When the method list is displayed
Then the "Pengembalian Tunai" option is not shown

Scenario: Legacy cash settlement renders read-only (edge case)
Given a purchase return has an existing settlement line with method CASH
When the settlement is viewed in read-only history
Then the method label displays correctly without error

Scenario: Cash submission is rejected (failure)
Given a settlement line payload with method CASH
When the user submits the line
Then the system rejects the submission with a validation error

## Ticket 2: Allow Paid Purchases in MODIFY_PURCHASE Selection
Scenario: Paid purchase appears in selection list (happy path)
Given a supplier has paid and unpaid purchases with the returned product
When the user selects MODIFY_PURCHASE
Then both paid and unpaid purchases are available to select

Scenario: Paid purchase label is clear (edge case)
Given a paid purchase with due_amount = 0
When the purchase appears in the dropdown
Then the label indicates it is paid (e.g., "Lunas")

Scenario: Supplier mismatch is blocked (failure)
Given a purchase from a different supplier
When the user attempts to select it as a target purchase
Then the system prevents selection or rejects submission

## Ticket 3: Quantity Mismatch Warning (Non-blocking)
Scenario: Warning displayed for non-serial overage (happy path)
Given a non-serial return quantity greater than the selected purchase quantity
When the user selects the target purchase
Then a warning message is shown without blocking submission

Scenario: No warning for serial lines (edge case)
Given a serial-numbered return line
When the user selects a target purchase
Then no quantity mismatch warning is displayed

Scenario: Warning does not block submission (failure)
Given a non-serial overage warning is visible
When the user submits the settlement line
Then the submission proceeds without a validation error

## Ticket 4: MODIFY_PURCHASE Approval Payment Reset
Scenario: Paid purchase resets to Unpaid on approval (happy path)
Given a MODIFY_PURCHASE line targets a paid purchase
When the approver approves the line
Then all purchase payments are deleted and payment_status becomes Unpaid

Scenario: Partial purchase resets to Unpaid (edge case)
Given a MODIFY_PURCHASE line targets a partially paid purchase
When the approver approves the line
Then payments are deleted and paid_amount is set to 0

Scenario: Payment reset fails transactionally (failure)
Given a database error occurs during payment deletion
When the approver approves the line
Then the approval is rolled back and no partial changes persist

## Ticket 5: CREDIT Approval Dialog (Attachments + Notes)
Scenario: Approver submits credit notes and attachments (happy path)
Given a CREDIT settlement line awaiting approval
When the approver adds notes and uploads valid jpg/png/pdf files
Then the approval succeeds and files are accepted

Scenario: Multiple attachments accepted (edge case)
Given multiple valid attachment files
When the approver submits the approval form
Then all attachments are stored with the payment

Scenario: Invalid file type rejected (failure)
Given an attachment with an unsupported file type
When the approver submits approval
Then the system rejects the submission with a file validation error

## Ticket 6: Create Purchase Payment on CREDIT Approval
Scenario: Payment created on CREDIT approval (happy path)
Given a CREDIT settlement line with a target purchase
When the approver approves the line
Then a purchase payment is created with the settlement nominal and notes

Scenario: Credit applied to supplier credit ledger (edge case)
Given a supplier credit exists for the return
When the CREDIT approval creates a payment
Then a credit application record is created and remaining_amount is updated

Scenario: Supplier mismatch is rejected (failure)
Given the target purchase belongs to a different supplier
When the approver attempts approval
Then approval fails with an error and no payment is created

## Ticket 7: PRODUCT_REPAIR Receive (Serial Rules)
Scenario: Serial repair quantity locked to 1 (happy path)
Given a PRODUCT_REPAIR line for a serial product
When the receive modal is opened
Then the quantity field is locked to 1 and old serial is displayed

Scenario: Replacement serial entry required (edge case)
Given a PRODUCT_REPAIR serial line
When the receiver attempts to submit without a replacement serial
Then the submission is blocked with a validation error

Scenario: Duplicate serial rejected (failure)
Given a replacement serial already exists for the product
When the receiver submits the replacement serial
Then the system rejects the submission with a uniqueness error

## Ticket 8: Serial Lifecycle Updates on Repair/Replacement
Scenario: Old serial marked permanently returned (happy path)
Given a PRODUCT_REPAIR line with an old serial
When the receiver completes the receive step
Then the old serial status is set to RETURNED and excluded from search

Scenario: New serial created and active (edge case)
Given a replacement serial is provided
When the receive action completes
Then a new serial record is created with active status and location

Scenario: Receive transaction fails (failure)
Given a failure occurs during serial creation
When the receive action is submitted
Then the transaction rolls back and old serial status is unchanged

## Ticket 9: BROKEN_STOCK Receive Quantity Lock
Scenario: Quantity locked for broken stock (happy path)
Given a BROKEN_STOCK line awaiting receive
When the receive modal is opened
Then the quantity field is read-only and location remains selectable

Scenario: Partial receive is blocked (edge case)
Given a BROKEN_STOCK line with expected quantity X
When the receiver tries to submit a different quantity
Then the system rejects the submission with a validation error

Scenario: Missing location is blocked (failure)
Given a BROKEN_STOCK line
When the receiver submits without selecting a location
Then the system rejects the submission with a required-field error
