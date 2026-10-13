# Tasks

## 1. Approval claim behavior

- [x] 1.1 Scope the `expenses.approve` idempotency fallback to the route's expense ID while preserving other routes' key behavior; verify focused middleware tests show two expense IDs can claim separately and a repeated same-ID claim is rejected.
- [x] 1.2 Make the expense approval status check and update atomic, and release its claim after a failed pre-commit attempt; verify focused tests show a failed attempt can be retried and simultaneous requests cannot approve twice.

## 2. User feedback

- [x] 2.1 Render the `idempotency` rejection message on expense list and show pages; verify focused HTTP/view tests show the message after a duplicate approval request from each page.

## 3. Focused verification

- [x] 3.1 Run only the focused expense approval and idempotency tests, including consecutive approvals by the same non-admin user, duplicate same-expense submission, and failure retry; verify they pass and record the commands/results. Do not plan or run the full test suite for this change.
