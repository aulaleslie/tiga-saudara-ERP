## Context

Home currently renders a personalized greeting and permission-aware Quick Access card. Products are shared catalog records while `product_prices` stores setting-specific `sale_price`, `tier_1_price`, `tier_2_price`, and `last_purchase_price`; bundles are setting-specific and may be replicated across businesses through `replica_group_uuid`. Catalog values are mutated through controllers, Livewire quick-add paths, cross-business services, purchase-price synchronization, imports, background jobs, and bundle workflows.

Regular-user roles are attached per setting through `user_setting.role_id`, while the middleware synchronizes only the active setting's role into Spatie at request time. Feed visibility spanning several assigned businesses therefore cannot rely on the user's current `can()` result. The existing notification `PermissionResolver` demonstrates the appropriate per-setting role lookup. Super Admin is a deliberate exception and must see every event and price field across all settings.

The feed is an audit-style operational awareness surface, not a replacement for notifications or a general product audit log. It begins recording at deployment and requires no historical reconstruction.

## Goals / Non-Goals

**Goals:**

- Persist sufficient immutable context to explain future product and bundle price changes even after current records change or disappear.
- Capture qualifying successful changes consistently across the explicitly supported mutation paths without recording no-ops or rolled-back work.
- Use one server-side visibility policy for Home, history results, filters, and modal detail.
- Present a compact Home preview and a professional searchable history consistent with existing Bootstrap/CoreUI list, card, badge, and modal patterns.
- Group one multi-business operation into one user-facing event while independently filtering each setting section.
- Keep verification focused on changed paths and access boundaries.

**Non-Goals:**

- Backfilling events for existing products, prices, or bundles.
- Recording product deletion, bundle deletion, stock, average cost, tax, bundle composition, activation dates, or unrelated product metadata changes.
- Adding read/unread state or integrating these events with the notification bell.
- Adding a sidebar or header link to the history page.
- Implementing fuzzy spelling correction, FULLTEXT search, or activating `TypoTolerantSearch`.
- Planning or requiring the complete application test suite.

## Decisions

### 1. Persist an append-only operation header with setting-specific snapshots

Use a new operation/event header plus child setting snapshots rather than deriving feed entries from current `updated_at` values. The header holds event type, group identifier, subject type/id, subject name/code snapshots, actor, source, and occurrence time. Each child holds `setting_id`, setting-name snapshot, and normalized JSON before/after values for the tracked fields.

A header-and-children shape naturally groups replicated operations, avoids repeating actor/subject metadata, and permits a regular user to receive only authorized children. If implementation simplicity favors a single table, rows must still share an operation UUID and the query layer must group them; the externally observable grouping and snapshot guarantees are unchanged.

Alternative considered: query `products`, `product_prices`, and `product_bundles` by timestamps. Rejected because it cannot identify changed fields, preserve earlier values, distinguish no-ops, group one operation, or exclude pre-deployment history reliably.

### 2. Record through a shared domain recorder inside existing transactions

Introduce one recorder that accepts a normalized operation, compares tracked before/after values using consistent decimal normalization, drops unchanged fields, and writes the header and setting snapshots. Existing mutation paths call this recorder only after their affected values are known and while still inside the domain transaction.

Product creation records one operation after product and initial per-setting price rows have been established. Product-price writes record only changed tracked keys. Bundle creation groups replicated setting copies; bundle update records the current setting or every changed replica when applying price across businesses. Automated flows supply an explicit source label and nullable actor.

Model observers alone are not sufficient because query-builder bulk updates do not emit Eloquent model events and because observers lack reliable operation grouping. Database triggers were considered but rejected due to grouping difficulty, missing actor/source context, and MySQL/SQLite portability. The implementation inventory must locate every supported write path and route it through the shared recorder or a shared mutation service.

### 3. Keep event persistence atomic and future-only

The new migration creates empty event tables; it does not scan or seed catalog data. Recorder writes occur within the same database transaction as the catalog mutation. Where an existing path is not transactional, the touched path must introduce an appropriately scoped transaction around the mutation and event write.

No deployment timestamp predicate is needed because only explicit post-deployment recorder calls create events. Failed transactions roll back their events with the domain mutation.

### 4. Resolve visibility per event setting and return view models, not raw models

Create a feed visibility/query service that produces already-sanitized view models. It loads assigned setting roles in bulk and derives a visibility mask per setting:

- `can_purchase_price`: role has `purchases.create`.
- `can_sales_prices`: role has `sales.create`, or has both `pos.access` and `pos.sessions.open`.
- `can_bundle_event`: same as `can_sales_prices`.

Super Admin skips assignment and mask checks and receives all tracked fields. Regular users receive only child snapshots for assigned settings, and price keys are removed according to the mask before controllers, views, Livewire state, modal payloads, or JSON responses receive the data. Empty children and then empty operation headers are removed.

Reuse or extend the existing `PermissionResolver` pattern, with bulk role/permission loading to avoid per-event N+1 queries. Do not mutate the active session role while evaluating other settings.

Alternative considered: render all values and hide unauthorized columns in Blade. Rejected because sensitive values would remain in HTML or serialized component state.

### 5. Use stable snapshots for display and current IDs only as optional links

Subject name, product code, and setting name are copied into the event at capture time. Modal and list rendering use snapshots as canonical display values. Nullable current foreign keys may support optional navigation but must not be required to render an event.

This preserves useful history after rename or deletion and provides a stable search target. Foreign keys to mutable subjects should therefore be nullable with null-on-delete behavior, or stored as non-constrained identifiers where appropriate; deletion must never cascade into the event ledger.

### 6. Implement Product List-style tokenized partial search in the ledger query

Normalize the query by trimming it, splitting on whitespace, and discarding empty tokens. For every token, add a grouped case-insensitive partial `LIKE` condition across subject name, subject code, and bundle name snapshot columns. All tokens are combined with `AND`; searchable fields within one token are combined with `OR`.

Use bound parameters and escape wildcard input as required by the database abstraction. This is intentionally tokenized substring matching, not typo correction. Maintain equivalent behavior on MySQL/MariaDB and SQLite focused tests. Add indexes supporting chronological and setting-scoped retrieval; conventional indexes help filtering and ordering even though leading-wildcard search itself cannot fully use them.

### 7. Share one query contract between Home, history, and modal

The Home controller asks the feed service for the newest ten sanitized grouped events. The history endpoint/page uses the same base policy with search, visible-business, event-type, date, and pagination inputs. Modal detail requests the same service by event identifier; absence after authorization returns 404 or 403 without disclosing whether a hidden event exists.

Business-filter options come from all settings for Super Admin and assigned settings for regular users, narrowed as appropriate to the feed contract. Filter changes reset pagination.

This avoids small authorization differences between UI surfaces and keeps tests centered on one policy boundary.

### 8. Follow existing compact timeline and modal presentation patterns

Home uses one `card border-0 shadow-sm` below Quick Access, with `card-header bg-white` and a `list-group list-group-flush`. Each keyboard-accessible compact row contains an event icon/badge, title, short visible-business and price summary, relative time, and chevron. A subtle event-type color or left border complements, but never replaces, the text label. The header contains `Lihat Semua Pembaruan`; no global navigation entry is added.

History uses the same row partial inside a filterable paginated card. A single reusable `modal-lg modal-dialog-centered` displays subject metadata, actor/source, exact timestamp, and business sections. Created events show authorized snapshots; updated events use a compact `Sebelum`/`Sesudah` table containing only changed authorized fields. Closing the modal preserves page state. The component must support click, Enter/Space activation, close controls, focus restoration, loading state if details are fetched asynchronously, responsive stacking, and an explicit empty state.

Generating one modal per row was considered but rejected because it duplicates markup, increases payload size, and makes permission-safe asynchronous detail harder to centralize.

### 9. Keep the feed informational and bounded at query time

The initial Home limit is ten and the history page uses twenty items per page, newest first with a deterministic ID tie-breaker. No read state or notification delivery is created. Retain events indefinitely for the initial implementation; a later archival policy can be added based on observed volume.

Eager-load child snapshots and setting data required by a page, and paginate operation headers rather than flattened setting rows so grouping does not produce duplicate or short pages.

## Risks / Trade-offs

- [A supported mutation path bypasses the recorder] → Inventory tracked-field writers with `rg`, centralize touched writers where practical, and add focused integration coverage for each supported category: manual/quick-add, cross-business, purchase synchronization, import/job, and bundle replication.
- [Bulk imports generate excessive event rows] → Group changes by source operation and subject, write in batches where safe, and paginate grouped headers; retain per-setting snapshots for authorization.
- [Permission checks cause N+1 queries] → Preload assigned setting roles and permissions once per request and eager-load event children in bounded pages.
- [JSON snapshots accidentally expose hidden values] → Map raw persistence records to sanitized DTOs/view arrays inside the feed service and test response/HTML absence, not only visual hiding.
- [Decimal representation creates false changes] → Normalize tracked monetary values to the database scale before comparison and persistence.
- [Subject or setting is renamed/deleted] → Render immutable name/code snapshots and make live relationships optional.
- [Home becomes slow] → Query only ten grouped headers through indexed occurrence/setting relationships and avoid loading full history/filter counts there.
- [Tokenized leading-wildcard search slows as history grows] → Keep search server-side and paginated, index filters/order, measure real volume, and defer FULLTEXT until evidence justifies changing semantics.
- [Mixed Bootstrap conventions cause modal issues] → Follow the layout's currently working modal attributes and reusable patterns, and cover open/close behavior in focused UI/feature tests where supported.

## Migration Plan

1. Add empty event header and setting-snapshot tables with indexes and non-destructive nullable subject links; perform no backfill.
2. Deploy the recorder and integrate supported mutation paths transactionally.
3. Deploy the shared visibility/query service and history/detail routes.
4. Add the Home preview and full history UI without global navigation changes.
5. Run only focused tests for the touched recorder integrations, permission matrix, grouping, tokenized search, Home rendering, history pagination/filters, and modal authorization.

Rollback removes the Home/history surfaces and recorder calls first. Event tables may remain dormant to preserve already captured operational history; a later explicit migration may remove them only if data retention is intentionally abandoned.

## Open Questions

None. Initial defaults are ten Home events, twenty history events per page, indefinite retention, automated changes labeled by source, and no read/unread state.
