# Breakage Approval Bug Investigation Plan

The current symptom is that approving a breakage adjustment silently does nothing—no UI feedback, errors, or log messages—suggesting the PATCH request might not reach the controller or is exiting early. The following plan breaks down the investigation and remediation tasks.

## 1. Reproduce and capture baseline data
- [ ] Reproduce the issue on a local environment while tailing the Laravel log to confirm the absence of log entries from `AdjustmentController::approve`. Use the existing debug helpers in `resources/views/adjustment/show.blade.php` to verify gate conditions.  
- [ ] Record the browser network traffic for the `adjustments.approve` PATCH request (status code, response payload, redirects) and note any JavaScript console errors.

## 2. Verify request wiring from the UI
- [ ] Inspect the approval form rendered for breakage adjustments to ensure the PATCH verb spoofing and CSRF token are present and that no front-end script prevents form submission.  
- [ ] Confirm the approve button is visible only when `$adjustment->status === 'pending'` and the user has either `adjustments.breakage.approval` or `adjustments.approval` permission.

## 3. Trace backend execution path
- [ ] Add temporary structured logging (level INFO/DEBUG) at the start of `AdjustmentController::approve`, `approveBreakage`, and inside the permission gates to determine how far the request progresses.  
- [ ] Validate that route-model binding loads the correct adjustment record and that its `type` is `breakage` so the controller reaches `approveBreakage`.

## 4. Inspect breakage approval business rules
- [ ] Double-check `approveBreakage` logic for potential early exits—e.g., empty `adjustedProducts`, serial-number/quantity mismatches, or exceptions that are swallowed before logging.  
- [ ] Confirm the `adjusted_products.serial_numbers` payload format matches what `approveBreakage` expects (`array<int>` vs JSON objects) and update casting/decoding accordingly if needed.  
- [ ] Review stock mutation queries (especially `lockForUpdate` and decrement logic) to ensure they succeed for typical breakage data sets.

## 5. Improve observability and error handling
- [ ] Ensure caught exceptions in `approveBreakage` are logged with stack traces and contextual data (adjustment ID, payload).  
- [ ] Surface failure feedback to the UI via session flashes/toast so the user sees why the approval failed instead of “nothing happens.”

## 6. Regression coverage
- [ ] Add feature tests that cover approving a breakage adjustment with and without serial-numbered products to guard against future regressions.  
- [ ] Include negative test cases (insufficient stock, mismatched serial counts) to ensure meaningful error messages reach the UI.

## 7. Final QA
- [ ] After fixes, rerun the manual approval flow and confirm logs, toast notifications, and database updates (adjustment status, stock levels, transaction rows).  
- [ ] Validate that normal (non-breakage) approvals remain unaffected.
