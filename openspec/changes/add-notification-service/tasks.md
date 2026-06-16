## 1. Persistence and Permissions

- [x] 1.1 Add notification persistence migration with user, setting, optional location, category/type, source reference, fingerprint, title, message, action URL, metadata, read_at, resolved_at, and indexes.
- [x] 1.2 Add the notification Eloquent model, relationships to user/setting/location where applicable, casts, scopes for unread/read/unresolved, and source helper methods.
- [x] 1.3 Add the `notifications.lowStock` permission to the project permission configuration and ensure it can be assigned to roles.
- [x] 1.4 Add focused migration/model tests for notification row creation, read/resolved casts, indexes/unique duplicate behavior, and relationships.

## 2. Cross-Business Permission and Recipient Resolution

- [x] 2.1 Implement a per-setting permission resolver that checks a user's role/permissions for a specific setting without relying on the active session Gate.
- [x] 2.2 Implement recipient resolver methods for low-stock, approval, and revision notifications using per-setting permissions.
- [x] 2.3 Cover Super Admin and normal user behavior across multiple settings with focused permission resolver tests.
- [x] 2.4 Cover recipient filtering where a user has a permission in one accessible business but not another.

## 3. Notification Core Services

- [x] 3.1 Implement a notification writer service that creates or updates unresolved rows by deterministic fingerprint without duplicating active notifications.
- [x] 3.2 Implement notification resolution methods for resolving by category, source type, source id, setting id, and optional location id.
- [x] 3.3 Implement read methods for marking one notification as read and marking all matching visible unresolved notifications as read.
- [x] 3.4 Implement action URL and display payload conventions for business, location, source label, title, and message.
- [x] 3.5 Add service tests for duplicate prevention, read state, resolved state, and history retention.

## 4. Feed Queries and UI Surfaces

- [x] 4.1 Implement a feed service for unread unresolved count across all businesses visible to the user.
- [x] 4.2 Implement header dropdown query logic with max 10 unresolved notifications, unread first, then read items to fill remaining slots.
- [x] 4.3 Implement paginated notification index query with all visible notifications, unread first, read next, and newest within each group.
- [x] 4.4 Add routes/controller actions for notification index, click/read redirect, and mark-all-as-read.
- [x] 4.5 Replace the current header low-stock Blade query with service-backed count and dropdown rendering.
- [x] 4.6 Add notification index Blade view with pagination, business/source context, read/unread styling, and mark-all-as-read control.
- [x] 4.7 Add feature tests for header count, dropdown fill behavior, click-to-read redirect, mark-all-as-read, and index ordering.

## 5. Low-Stock Notification Generation

- [x] 5.1 Implement global product stock notification logic comparing `products.product_quantity` to `products.product_stock_alert`.
- [x] 5.2 Implement per-location stock notification logic comparing `product_stocks.quantity` to the related product's `product_stock_alert`.
- [x] 5.3 Hook global and location stock notification generation/resolution into known stock mutation paths where previous and current quantities are available.
- [x] 5.4 Ensure already-low stock decreases do not create duplicate unresolved notifications.
- [x] 5.5 Ensure stock rising above threshold resolves active stock notifications and later crossing down creates a new active notification.
- [x] 5.6 Add focused tests for global stock threshold crossing, location stock threshold crossing, duplicate prevention, resolution, and cross-business recipients.

## 6. Document Approval Notifications

- [x] 6.1 Define source-specific approval notification resolvers for supported document types and their relevant approval permissions.
- [x] 6.2 Hook purchase, sales, receiving note, sales dispatch, adjustment, and expense approval-needed transitions into the notification writer.
- [x] 6.3 Hook purchase return, sale return, POS return, return dispatch, return receiving, and return settlement approval-needed transitions into the notification writer.
- [x] 6.4 Resolve approval notifications when sources are approved, rejected, deleted, archived, cancelled, completed, or otherwise leave approval-needed state.
- [x] 6.5 Add focused tests for approval notification creation, permission-filtered recipients, duplicate prevention, and resolution for representative source types.

## 7. Revision and Rejection Notifications

- [x] 7.1 Define source-specific revision notification resolvers for supported document types and their relevant edit permissions.
- [x] 7.2 Hook rejection and manual-correction/revision-required transitions into the notification writer.
- [x] 7.3 Resolve revision notifications when sources are edited, resubmitted, approved, deleted, archived, cancelled, or otherwise leave revision-needed state.
- [x] 7.4 Add focused tests for revision notification recipients, read behavior, duplicate prevention, and resolution for representative source types.

## 8. Repair, Sync, and Prune Commands

- [x] 8.1 Implement a repair/sync command that scans active low-stock global/product-stock sources and creates/resolves notification rows.
- [x] 8.2 Extend the repair/sync command to scan active approval-needed document sources and create/resolve notification rows.
- [x] 8.3 Extend the repair/sync command to scan active revision-needed document sources and create/resolve notification rows.
- [x] 8.4 Implement a manual prune command that deletes notifications older than a provided age or cutoff argument.
- [x] 8.5 Add command tests for missing notification repair, stale notification resolution, idempotency, and prune cutoff behavior.

## 9. Verification and Cleanup

- [x] 9.1 Run focused notification feature tests and fix failures.
- [x] 9.2 Run focused affected module tests for stock, approval, return, and header behavior where feasible.
- [x] 9.3 Run `php artisan test` or `composer test:fresh-sqlite` if feasible for broader regression confidence.
- [x] 9.4 Review notification query plans/index usage for header and index page queries.
- [x] 9.5 Remove obsolete header low-stock cache usage and ensure no notification logic remains embedded in Blade.
