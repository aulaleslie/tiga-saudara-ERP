## 1. Event Ledger Foundation

- [x] 1.1 Inventory every production writer of `last_purchase_price`, `sale_price`, `tier_1_price`, `tier_2_price`, and `bundle_sale_price`, and map each supported manual, quick-add, cross-business, purchase-sync, import/job, and bundle path to its shared integration point.
- [x] 1.2 Add module migrations for the future-only event operation and per-setting snapshot storage, including immutable subject/business display fields, actor/source metadata, nullable live references, operation grouping, timestamps, and chronological/business/search-supporting indexes without historical backfill.
- [x] 1.3 Add Eloquent models, relationships, casts, event-type constants, and factories/builders needed for grouped event operations and setting snapshots.
- [x] 1.4 Implement a shared event recorder that normalizes monetary decimals, compares tracked before/after fields, suppresses no-op updates, groups multi-business changes, and records created/update snapshots inside the caller's transaction.
- [x] 1.5 Add focused recorder tests for created snapshots, changed-field comparisons, no-op suppression, multi-business grouping, automated sources, immutable subject snapshots, and transaction rollback.

## 2. Product and Purchase Price Capture

- [x] 2.1 Integrate product creation and the shared Purchase/Sales quick-add workflow with the recorder after initial per-setting prices are persisted.
- [x] 2.2 Integrate normal product edit and cross-business price management with grouped tracked-price event capture.
- [x] 2.3 Integrate the shared latest-purchase-price synchronizer and touched purchase completion/approval price-update paths with setting-specific event capture.
- [x] 2.4 Integrate supported product import and background price-processing paths identified in task 1.1, assigning explicit `Import` or system source metadata and batching/grouping safely where appropriate.
- [x] 2.5 Add focused integration tests for the touched manual/quick-add, cross-business, purchase synchronization, and import/job paths, including no-op and rollback assertions; do not run or plan unrelated module or full-suite tests.

## 3. Bundle Event Capture

- [x] 3.1 Integrate replicated bundle creation with one grouped bundle-created operation containing each successfully created business snapshot.
- [x] 3.2 Integrate single-business bundle price updates and `apply_price_to_all_businesses` updates with changed-only before/after snapshots under one operation group.
- [x] 3.3 Ensure bundle metadata-only edits and unchanged bundle prices do not emit bundle-price events, while failed bundle transactions emit no event.
- [x] 3.4 Add focused bundle controller/service tests for creation, single-setting price update, replicated price update, metadata-only/no-op update, grouping, and rollback.

## 4. Permission-Safe Feed Query

- [x] 4.1 Add or extend a bulk per-setting permission resolver that reads `user_setting.role_id` without changing the active session role and derives purchase-price, sales-tier, and bundle visibility masks.
- [x] 4.2 Implement the shared feed query/service for Super Admin unrestricted access and regular-user assigned-setting filtering, field masking, empty-section removal, grouped newest-first ordering, and sanitized view models.
- [x] 4.3 Implement event-detail retrieval through the same visibility boundary, returning stored snapshots when live subjects are missing and denying hidden event identifiers without leaking business or price data.
- [x] 4.4 Implement visible business filter options plus event type and inclusive date-range constraints for Super Admin and regular users.
- [x] 4.5 Implement case-insensitive Product List-style tokenized partial `LIKE` search across subject name, product code, and bundle name snapshots, with every token required and equivalent MySQL/MariaDB and SQLite behavior.
- [x] 4.6 Add focused service tests for Super Admin bypass, purchase-only, Sales-only, complete and incomplete POS permission combinations, combined permissions, differing roles across settings, unassigned settings, grouped partial visibility, tokenized search, filters, and deleted live subjects.

## 5. History Route and Professional Event UI

- [x] 5.1 Add authenticated history and detail routes/controllers or a Livewire page that use the shared feed service, paginate grouped operations at twenty per page, validate filters, reset pagination when filters change, and add no sidebar/header entry.
- [x] 5.2 Build a reusable compact event-row partial/component using existing Bootstrap/CoreUI card, list-group, badge, icon, relative-time, hover, responsive, and keyboard-accessible patterns.
- [x] 5.3 Build the full history card with tokenized search, authorized business filter, event type, date range, reset action, result/empty/loading states, and server-side pagination.
- [x] 5.4 Build one reusable `modal-lg modal-dialog-centered` detail modal with loading/error states, focus-safe open/close behavior, subject/source metadata, exact timestamp, authorized business sections, created-price snapshots, and changed-only before/after tables.
- [x] 5.5 Ensure modal retrieval and rendered/serialized page state never contain masked price fields or hidden business names and preserve current history search, filters, and page when closed.
- [x] 5.6 Add focused history feature/component tests for authentication, lack of global navigation links, direct authorized route access, pagination, filters, tokenized partial search, modal detail, keyboard-ready controls, state preservation where testable, and sensitive-value absence.

## 6. Home Preview Integration

- [x] 6.1 Extend `HomeController@index` to request the latest ten sanitized grouped events from the shared feed service without adding feed work to Dashboard.
- [x] 6.2 Add the `Pembaruan Produk & Harga` card beneath Quick Access with compact reusable rows, explicit empty state, shared detail modal, and `Lihat Semua Pembaruan` as the sole navigation entry to history.
- [x] 6.3 Add focused Home tests for placement below Quick Access, ten-item visible limit, newest-first grouping, regular-user field masking, unrestricted Super Admin content, empty state, modal trigger, and history link.

## 7. Focused Verification

- [x] 7.1 Run the new event-ledger, capture-integration, visibility/search, history/modal, and Home-preview tests with focused `php artisan test --filter` or explicit test paths and fix regressions within the touched scope.
- [x] 7.2 Run focused existing Product, Purchase, bundle, Home/Dashboard, and permission tests directly affected by modified production paths; record commands and outcomes without running the full application suite.
