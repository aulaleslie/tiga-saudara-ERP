## Context

The version 2 return-dispatch review Blade view renders a rejection form for a pending movement using `transfers.movements.return.reject`. The Adjustment module route file registers the neighboring review and approval routes but omits this rejection route. The `ReturnDispatchMovementController::reject` action already authorizes destination-side dispatch approval and delegates reason validation, state transition, history, and transaction handling to `TransferMovementDocumentService::reject`.

## Goals / Non-Goals

**Goals:**
- Make the pending return-dispatch review page render for an authorized approver.
- Route the existing rejection form to the existing rejection action using the established movement route shape.
- Verify rendering and one reasoned rejection through focused coverage.

**Non-Goals:**
- Change rejection domain rules, permissions, movement state handling, or inventory effects.
- Alter other movement workflows or introduce a database migration.

## Decisions

- Add `POST /transfers/{transfer}/movements/{movement}/return/reject` named `transfers.movements.return.reject` in the Adjustment route group next to return approval. This matches the Blade form and the route naming pattern of forward dispatch, forward receipt, and return receipt. Changing the form to use a different route would leave the existing controller action unreachable by the intended workflow.
- Point the route at `ReturnDispatchMovementController@reject`. The controller and document service already implement authorization, mandatory reason validation, and audited rejection; duplicating that logic in a new handler would add inconsistent behavior.
- Add focused feature coverage using a pending version 2 return-dispatch movement. Assert the authorized HTML review succeeds and includes the rejection form URL, then POST a reason through the named route and assert rejection state and reason. A full suite run is unnecessary for this isolated route registration.

## Risks / Trade-offs

- [A route registration can expose an existing action to requests] → Keep the existing controller authorization and movement guards; test an authorized request through the browser route.
- [Route caches can retain the old route map after deployment] → Refresh the Laravel route cache during normal deployment and confirm the named route appears in `route:list`.

## Migration Plan

Deploy the route and focused regression test with the normal application release. No data migration is needed. Rollback removes the route registration; existing movement data remains unchanged.

## Open Questions

None.
