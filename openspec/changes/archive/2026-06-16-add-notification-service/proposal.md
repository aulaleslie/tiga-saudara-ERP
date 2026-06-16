## Why

The current header notification implementation is a Blade-level low-stock query with no per-user rows, read/unread state, cross-business visibility, or document workflow coverage. Users need a persistent notification inbox that shows actionable stock, approval, and revision items correctly when pages load, without requiring real-time infrastructure.

## What Changes

- Replace the header-only low-stock notification query with a persistent per-user notification service.
- Add notification rows with unread/read state, source references, action URLs, cross-business setting context, and lifecycle resolution.
- Add a header dropdown that shows up to 10 unresolved notifications across all businesses the user can access, ordered unread first and then read items to fill remaining slots.
- Add a notification page that shows all notifications, including past read notifications, ordered unread first and then read items.
- Add click-to-read behavior before redirecting to the source document or product page.
- Add mark-all-as-read support.
- Generate notifications immediately during relevant stock and document mutations, with repair/sync and manual prune commands.
- Add low-stock notifications for both global product quantity and per-location product stock quantity using the existing product stock alert threshold.
- Add approval-needed notifications for existing document approval flows, including purchase, sales, receiving, dispatch, return, settlement, POS return, and related return sub-flows.
- Add rejected/revision-needed notifications for users with permission to edit the related document type.
- Add a new `notifications.lowStock` permission for low-stock recipients.

## Capabilities

### New Capabilities
- `notifications`: Persistent per-user notification rows, unread/read behavior, cross-business notification visibility, low-stock alerts, document approval and revision notifications, header dropdown behavior, notification history page, repair/sync, and pruning.

### Modified Capabilities
- None.

## Impact

- Affected UI: shared application header notification dropdown and a new notifications index page.
- Affected backend areas: notification persistence, notification recipient resolution, permission evaluation across user-accessible settings, stock mutation flows, document approval/rejection/submission flows, and console commands.
- Affected permissions: adds `notifications.lowStock` alongside existing `notifications.access`.
- Affected modules: Product stock, Purchase, Sale, PurchasesReturn, SalesReturn, POS return, receiving notes, dispatch approvals, return settlements, Adjustment, Expense, and shared User/Setting permission context.
- Testing impact: requires focused feature tests for notification generation, duplicate prevention, read/unread ordering, cross-business recipient filtering, low-stock transition behavior, repair/sync, prune command, and header/page queries.
