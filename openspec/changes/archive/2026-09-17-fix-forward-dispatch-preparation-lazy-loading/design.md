## Context

The forward-dispatch preparation controller receives a movement from `ForwardDispatchPreparationService::getOrCreateDraft()`. Existing drafts include `lines.product`, but newly created drafts are returned by `TransferMovementDocumentService::createDraft()` with `lines.serials` only. The HTML view reads `line.product`, `line.serials`, and, for stock-visible users, `transfer.products`. The JSON projection already loads its required relations explicitly. With lazy loading disabled, a new draft containing multiple lines can fail during Blade rendering.

## Goals / Non-Goals

**Goals:**
- Make the HTML preparation view render new and resumed drafts with explicit relation loading.
- Preserve the existing stock-visibility boundary for requested quantities.
- Verify the route with a small regression test.

**Non-Goals:**
- Change draft creation, inventory behavior, scanning, approval, or the JSON response shape.
- Change the global lazy-loading policy.
- Run a full application test suite for this localized fix.

## Decisions

1. In the HTML branch of `ForwardDispatchMovementController::prepare`, load missing `lines.product` and `lines.serials` before returning the view. This makes the view's relation contract explicit regardless of whether the service created or resumed the draft. The alternative is changing the document service's general `createDraft()` return graph, which affects other movement workflows.
2. Load `transfer.products` only when `TransferStockVisibility::canView()` is true, matching the view's conditional requested-quantity column. The alternative of unconditional loading would fetch protected request data for blind users without a rendering need.
3. Add a focused feature regression using an eligible approved version 2 transfer with multiple products. Request HTML preparation with lazy loading disabled, assert the new draft renders, then request it again to cover the resumed draft. Check requested quantities appear only for a stock-visible actor if the fixture supports both permission states. This exercises the failure boundary without broad test expansion.

## Risks / Trade-offs

- [Additional eager-load queries on HTML preparation] → Relations are limited to those the view reads; `loadMissing` avoids reloading already populated relations.
- [Protected request quantities exposed to a blind user] → Load transfer products only in the stock-visible branch and keep the view's permission condition intact.
- [Blade errors occur after the controller returns a view] → Exercise the full HTML route in the focused regression, so rendering itself is covered.

## Migration Plan

No schema or data migration is needed. Deploy the controller change with the focused regression. Roll back the application change if it causes an unexpected rendering issue.

## Open Questions

None.
