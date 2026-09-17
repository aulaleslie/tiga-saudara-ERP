## Why

The version 2 return-dispatch review page fails to render for a pending batch because its rejection form references an unregistered named route. This blocks approvers from reviewing, approving, or rejecting the batch through the page even though the rejection controller action exists.

## What Changes

- Register the missing POST route for return-dispatch movement rejection using the name and URL expected by the review form.
- Confirm the pending review page renders and its rejection action reaches the existing authorized controller and document service.
- Add focused regression coverage for the route and review flow.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-transfer-return-dispatch`: Clarify that an authorized approver can render the pending batch review page and submit a reasoned rejection from it.

## Impact

- `Modules/Adjustment/Routes/web.php` gains one named POST route.
- Existing return-dispatch review Blade view and controller rejection method are reused.
- Focused tests under `Modules/Adjustment/Tests/Feature/` cover the browser entry point and rejection dispatch. No schema or dependency changes.
