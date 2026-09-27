## Context

The product-price feed stores immutable subject display fields on `product_price_feed_events`. Product events already record a product name and code, but bundle capture currently writes only the bundle name to `subject_name` and leaves `subject_code` null. The shared event row and detail modal render these stored fields, and history search already performs tokenized matching over both columns.

Bundle creation has the parent product ID but does not currently resolve the parent model for feed capture. Bundle update already receives the parent `Product` through route-model binding. Current bundle records also expose a `parentProduct` relationship, but resolving that relationship while reading the feed would make immutable historical presentation depend on mutable or deleted catalog data.

## Goals / Non-Goals

**Goals:**

- Make future bundle feed events recognizable by both parent product name and bundle name.
- Include the parent product code in the existing code display and search path.
- Keep Home, history, and modal presentation consistent through the existing shared event view model.
- Preserve snapshot behavior without a schema migration or read-time catalog lookup.
- Cover only the changed capture and its likely search/presentation regressions with focused tests.

**Non-Goals:**

- Backfill or rewrite historical immutable feed events.
- Change which users, businesses, or price fields are visible.
- Change bundle pricing, replication, grouping, or mutation behavior.
- Add a separate parent-product relationship to feed event reads.
- Run the full application test suite as part of this narrowly scoped change.

## Decisions

### Store a combined immutable subject label in existing event columns

For bundle events, capture `subject_name` as `<parent product name> — <bundle name>` and `subject_code` as the parent product code. Both bundle-created and bundle-price-updated paths use the same formatting rule.

This reuses the fields already returned by `ProductPriceFeedQueryService`, rendered by the shared row/modal, and searched by history. It avoids a migration and keeps the event independently renderable after catalog deletion.

Alternatives considered:

- Add product-name and bundle-name columns. This is more structurally explicit but introduces a migration and broader query/view-model changes for two display values that already fit the immutable subject contract.
- Resolve the bundle and parent product during feed reads. This would allow historical events to gain product context, but would make snapshots change when products are renamed and fail after deletion.
- Change only the Home Blade view. The shared row has no stored parent-product context, and a Home-only lookup would create inconsistent Home, history, and modal identities.

### Resolve the parent product inside the existing mutation transaction

Bundle creation will obtain the parent product from the supplied product ID before recording the event; bundle update will use its already route-bound `Product`. Event capture remains inside the existing database transaction so a failed bundle mutation cannot leave an orphaned feed entry.

The formatter may be a small private controller helper if that keeps creation and update identical; introducing a new service is unnecessary unless implementation reveals another bundle-event producer.

### Preserve historical rows exactly as stored

No backfill will be performed. Existing events continue to show only their bundle name and no code. This respects the feed's future-only immutable-event contract and avoids inventing historical product identity from mutable current data.

### Reuse existing search and presentation behavior

No query algorithm or shared Blade layout change is expected. Since both names are stored in `subject_name` and the product code in `subject_code`, the existing tokenized `LIKE` query and existing subject renderer naturally cover all requested identifiers. Tests will verify this assumption so implementation can add a query or view change only if the focused regression exposes a gap.

## Risks / Trade-offs

- [Combined `subject_name` is less normalized than separate fields] → Keep the delimiter deterministic and cover exact capture in integration tests.
- [Long product and bundle names may produce a longer row title] → Retain the current wrapping behavior; do not truncate stored identity.
- [Historical rows remain less informative] → Explicitly preserve them rather than violating immutability with a mutable backfill.
- [A future producer could format bundle identity differently] → Centralize formatting within the current controller or extract it if more producers appear.

## Migration Plan

1. Deploy the capture change and focused tests without a database migration.
2. New bundle events begin storing combined identity immediately; existing rows remain untouched.
3. Rollback consists of reverting the capture change. Events created while the change was active remain valid immutable rows with a more descriptive subject.

## Open Questions

None. The display format is fixed as `<parent product name> — <bundle name>` with the existing optional code presentation.
