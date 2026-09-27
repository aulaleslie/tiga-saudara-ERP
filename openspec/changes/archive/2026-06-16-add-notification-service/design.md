## Context

The shared header currently renders low-stock notifications by querying products directly in `resources/views/layouts/header.blade.php`. That implementation has no persistent notification rows, no user-specific read state, no notification history page, and no coverage for approval or revision workflows. The app already has multi-business user access through `user_setting`, per-setting role assignment, Spatie permissions, and many module-specific approval states with inconsistent status values.

This change introduces a domain notification service for page-load correctness, not a real-time transport. The service must work across all businesses a user can access, even though the current request role is dynamically synced to only the active `setting_id`.

## Goals / Non-Goals

**Goals:**
- Persist per-user notification rows with unread/read state, source references, action URLs, setting context, and resolution state.
- Show notification data across all businesses the user can access, not only the active business.
- Replace the header low-stock query with service-backed notification count and dropdown data.
- Add a paginated notification page that includes past read notifications.
- Generate low-stock, approval-needed, and revision-needed notifications immediately during relevant mutations.
- Provide repair/sync and manual prune commands.
- Prevent duplicate active rows for the same user/source/category.

**Non-Goals:**
- Real-time delivery, WebSockets, push notifications, email notifications, or browser notifications.
- Reworking the underlying approval workflows or normalizing all document status columns.
- Replacing Spatie permissions or the existing per-setting role assignment model.
- Adding price or bundle alerts; stock is the only product alert scope for this change.

## Decisions

### Use a custom ERP notification table and service

Create a first-party notification model/table rather than using Laravel's default database notification table as the primary domain store. The table should include `user_id`, `setting_id`, optional `location_id`, `category`, `type`, `title`, `message`, `source_type`, `source_id`, `fingerprint`, `action_url`, `read_at`, `resolved_at`, timestamps, and supporting metadata JSON.

Rationale: the ERP needs source de-duplication, cross-business setting context, lifecycle resolution, source URLs, per-location stock alerts, and command-driven repair/prune behavior. A custom table keeps those concepts explicit and queryable.

Alternative considered: Laravel database notifications. It is useful for generic delivery, but the default shape pushes too much domain behavior into payload JSON and does not naturally model resolution or source uniqueness.

### Compute recipients with per-setting permission resolution

Add a permission resolver that evaluates whether a user has a permission in a specific setting by looking at `user_setting.role_id` and that role's permissions, with Super Admin behavior kept consistent with existing app rules. Notification recipient selection must not rely only on `Gate::allows()` for the active session setting.

Rationale: the notification header must show items across all businesses the user can access. The active request role can only answer permission checks for one setting at a time.

### Model notification lifecycle separately from source lifecycle

Notification rows have independent read and resolved states:
- `read_at` tracks whether the recipient has seen/clicked the notification.
- `resolved_at` removes the row from actionable header surfaces when the source no longer needs attention.
- Notification history page keeps past read and resolved notifications unless pruned manually.

Rationale: users need a historical inbox, while the header should remain focused on unresolved work.

### Use source fingerprints for duplicate prevention

Generate deterministic fingerprints for active notifications, such as:
- `stock:global:{product_id}:user:{user_id}`
- `stock:location:{product_stock_id}:user:{user_id}`
- `approval:{source_type}:{source_id}:user:{user_id}`
- `revision:{source_type}:{source_id}:user:{user_id}`

The service should create a new row only when no unresolved matching fingerprint exists. If an existing unresolved row exists, update message/metadata as needed without creating duplicates.

### Generate stock notifications on threshold crossings

Use the existing `products.product_stock_alert` threshold. Generate separate global product and per-location stock notifications:
- Global stock compares `products.product_quantity` to `products.product_stock_alert`.
- Location stock compares `product_stocks.quantity` to the related product's `product_stock_alert`.

Create notifications only when quantity crosses from above threshold to at-or-below threshold. Do not create another notification when quantity was already low and decreases further. Resolve existing active stock notifications when quantity rises above threshold. A later crossing from above threshold back to low creates a new active notification.

### Generate document notifications from lifecycle transitions

Hook into document submission, approval request, rejection, revision-required, approval, completion, deletion/archive, and correction transitions in the existing module services/controllers. Initial scope includes purchases, sales, purchase receiving notes, sales dispatches, purchase returns, sale returns, POS returns, return dispatch approvals, return settlement approvals, adjustments, and expenses where those approval flows exist.

Approval notifications go to users with the relevant approval permission in the source setting. Revision/rejected notifications go to users with the relevant edit permission in the source setting.

### Header and page queries come from one feed service

Add a `NotificationFeedService` or equivalent with methods for unread unresolved count, dropdown items, paginated index items, mark-read, and mark-all-read. The dropdown returns at most 10 unresolved rows: newest unread first, then newest read rows to fill the remaining slots. The notification page returns all rows visible to the user, unread first and then read, with newest rows within each group.

### Commands repair, sync, and prune

Add a repair/sync command that scans source tables for currently active low-stock, approval-needed, and revision-needed conditions, creates missing notifications, resolves stale unresolved rows, and avoids duplicates by fingerprint. Add a manual prune command to delete notifications older than a provided cutoff or retention argument. Notifications are retained forever unless this command is explicitly run.

## Risks / Trade-offs

- [Risk] Cross-setting permission checks can drift from runtime Gate behavior. → Mitigation: centralize permission resolution and cover role-per-setting cases with tests.
- [Risk] Missing mutation hooks could leave notifications stale. → Mitigation: add the repair/sync command and tests for the highest-risk transitions.
- [Risk] Existing document status values are inconsistent across modules. → Mitigation: keep status normalization inside source-specific notification resolvers instead of spreading raw status checks through UI code.
- [Risk] Creating notification rows for every eligible user can be expensive in large tenants. → Mitigation: query recipients per setting/permission, bulk upsert by fingerprint, index `user_id`, `setting_id`, `read_at`, `resolved_at`, `category`, `source_type`, `source_id`, and `fingerprint`.
- [Risk] Stock transition detection may be missed in code paths that update quantities directly. → Mitigation: capture previous/current values in known stock mutation services/controllers and rely on repair/sync for backstop correctness.
- [Risk] Header can become noisy across all businesses. → Mitigation: include business and location context in rows, keep header limited to 10 unresolved items, and provide filters on the full page.

## Migration Plan

1. Add notification persistence schema, indexes, model, permissions, and navigation route/page.
2. Add cross-setting permission resolver and recipient resolver tests.
3. Add notification feed service methods for count, dropdown, page, read, and mark-all-read.
4. Replace header notification query with feed service data.
5. Add stock transition hooks and repair/sync coverage for global and per-location low stock.
6. Add document workflow hooks by module, starting with approval-needed and revision-needed transitions.
7. Add repair/sync and prune commands.
8. Run focused feature tests, then a broader Laravel test pass where feasible.

Rollback is additive: remove the header service usage to restore the old header low-stock query if needed, while leaving the notification table unused until a cleanup migration is planned.

## Open Questions

- Exact route names and labels for every source type should be confirmed during implementation against existing route definitions.
- Some legacy documents may not have reliable creator/submitter fields; revision recipients are permission-based for this change, but source-specific resolver behavior may need small adjustments where edit permissions are too broad.
