## Why

POS Return approval currently has too much hidden risk: an approver can move a pending return forward without seeing whether the system can generate the owner/sale-aligned Sales Return targets, dispatch links, stock movements, serial movements, and settlement implications required by the return. This is especially unsafe for split-owner, bundle, repeated-SKU, and serial-tracked POS transactions.

This change adds a preview-only approval checkpoint so approvers can inspect the planned execution graph before any approval mutation occurs.

## What Changes

- Change the pending POS Return approve action so the first click opens an approval preview page instead of approving immediately.
- Disable or block the current direct web approval mutation path for this preview-only phase; there must be no web-accessible final approve submission until a later change defines approval execution.
- Add a read-only approval preview that generates and displays the planned POS Return execution target without creating or updating Sales Returns, stock, serials, payments, dispatches, or lifecycle status.
- Show generated split-sale targets, owner/location/tax alignment, planned Sales Return headers/details, line-level resolution, dispatch detail anchors, cash-return amounts, replacement serials, bundle/component traces, and validation blockers.
- Use persisted POS Return lines as the approver-visible intent, then verify that intent against current source sale, dispatch, serial, owner/location, tax, and replacement-serial state.
- Surface unresolvable mapping issues as explicit preview blockers, while showing non-blocking drift or expected absence of linked Sales Returns as warnings or informational notes.
- Keep rejection behavior out of scope unless it only needs navigation compatibility.
- Do not add the final approval submission in this change. Preview only.

## Capabilities

### New Capabilities
- `pos-return-approval-preview`: Defines the read-only approval preview checkpoint, execution plan contents, mutation boundaries, and blocker behavior.

### Modified Capabilities
- `pos-return-draft-resolutions`: Changes the pending approval user journey so the approve button enters preview instead of approving immediately; final approval remains a later action outside this preview-only change.

## Impact

- Affected areas:
  - `Modules/Pos/Routes/web.php`
  - `Modules/Pos/Http/Controllers/PosReturnController.php`
  - `Modules/Pos/Services/PosReturnLifecycleService.php`
  - new or reused POS Return approval preview/planner service
  - `Modules/Pos/Resources/views/returns/*`
  - focused POS Return feature tests
- No database migration is required for the preview-only step.
- No application lifecycle mutation should occur from rendering the preview.
- Existing pending approval list/detail actions must navigate to preview, not post to `pos.returns.approve`.
- Any remaining direct approval POST route, if kept for route compatibility, must reject with a clear preview-only lifecycle message and leave all data unchanged.
- This change reduces approval risk before later work creates the final approval execution path.
