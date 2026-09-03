## Context

`SerialConversionEligibilityService::checkEligibility` (`Modules/Product/Services/SerialConversionEligibilityService.php`) blocks stock-to-serial conversion whenever a related document exists in certain "unfinished" statuses. Draft is currently included as a blocking status for five document types even though a draft document has not moved any stock:

- `PurchaseReturn::STATUS_DRAFT` (line ~171)
- `Transfer::STATUS_DRAFT` (line ~206)
- `Sale::STATUS_DRAFTED` (line ~264)
- `Adjustment` — raw strings `'pending'`, `'PENDING'`, `'draft'`, `'DRAFT'` (line ~291)
- `SaleReturn` header `status` in `['Pending', 'PENDING', 'Awaiting Settlement', 'AWAITING_SETTLEMENT', 'Draft', 'DRAFT']` (line ~316)

`ReceivedNote` and `ConsignmentReceiving` have no draft status and are unaffected.

## Goals / Non-Goals

**Goals:**
- Header-level DRAFT status no longer blocks conversion for the five document types above.
- Every non-draft status currently in each blocking list keeps blocking, unchanged.

**Non-Goals:**
- `SaleReturn.settlementItems.status == DRAFT` (nested settlement-item sub-state) stays a blocker — it represents an already-active return, not a standalone unsubmitted document.
- No change to `ReceivedNote` or `ConsignmentReceiving` (no draft concept).
- No change to the conversion execution logic itself, only the eligibility gate.
- No change to how blockers are surfaced (structured blocker shape, routes, permissions stay the same).

## Decisions

- **Remove the draft literal(s) from each status array/whereIn, rather than adding a separate "is draft" bypass branch.** The existing code structure is a flat allow/block list per document type; removing the draft entries is the minimal, lowest-risk diff and keeps the blocker-building logic (`$addDocumentBlocker`) untouched.
- **Adjustment uses raw string literals (`'draft'`, `'DRAFT'`) instead of model constants.** Leave this inconsistency as-is; simply drop those two literals from the `whereIn` array. Not in scope to introduce constants on the `Adjustment` model.
- **SaleReturn's compound `where` clause** (header status OR approval_status OR settlementItems status) needs `'Draft'`/`'DRAFT'` removed only from the header `status` `whereIn`, leaving `approval_status` and `settlementItems` sub-queries untouched.

## Risks / Trade-offs

- [A drafted document later resumes and moves to an active status referencing a now-serialized product, with line items still in the pre-conversion (non-serial) shape] → Out of scope for this change; the downstream document-resume/validation flow already exists independently of this eligibility gate and is not being modified here. Flagged as a known limitation, not blocking this change.
- [Removing the wrong status value by accident, since Adjustment uses raw strings not constants] → Mitigated by focused test coverage asserting each specific status still blocks/no-longer-blocks per document type.

## Verification

Full regression suite is not required for this change. Focused verification only:
- Run the targeted Product-module conversion eligibility tests after the edit (e.g. `php artisan test --filter=SerialConversion`).
- Manually confirm via test cases (not full suite) that: draft status no longer appears in blockers; every other status in the same list still blocks.
