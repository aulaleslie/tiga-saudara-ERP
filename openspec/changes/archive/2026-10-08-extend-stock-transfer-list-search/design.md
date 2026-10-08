# Design

## Context

See proposal.md for motivation. The list uses a Yajra server-side Eloquent DataTable over `transfers`. Its base query enforces active-business visibility for legacy transfers and broader version 3 document discovery. Product and serial evidence spans legacy `transfer_products` and version 3 goods, approval, and movement records. Product names and primary barcodes live on current `products` rows.

## Goals / Non-Goals

**Goals:** Keep all matching and pagination in the database, preserve the current transfer visibility predicate, and use current linked product identity without rewriting historical records.

**Non-Goals:** Change transfer-entry search, add conversion-barcode matching, add result columns, or introduce a search index.

## Decisions

1. Add a custom global search filter to the existing DataTable. Group header, product, and serial predicates inside one search condition applied after the existing visibility query. Keep the existing DataTable columns, ordering, and actions. A post-query PHP filter would break server-side counts and pagination.
2. Use correlated `EXISTS` queries for product and serial evidence rather than joining matching rows into the outer transfer query. This keeps one row per transfer, including when several products or serials match, and avoids `DISTINCT`/grouping effects on sorting and counts.
3. Resolve product text through current `products.product_name` and `products.barcode` linked from persisted transfer product identities. Cover legacy transfer products and version 3 recorded goods/allocation/movement product identities as needed for historical association. Do not search conversion barcodes or old product text snapshots.
4. Resolve serials from persisted transfer intent and history: legacy requested/dispatched serial selections, version 3 draft/request revisions and approval allocations, plus movement serial snapshots. Use a version-compatible strategy for JSON-backed selections and relational serial records; match by serial text, not numeric serial ID text. Include superseded and cancelled history because the serial still appeared on that transfer. A serial that merely belongs to the same product but was never associated with the transfer must not match.
5. Treat user input as a literal substring for SQL `LIKE`, escaping wildcard characters and using bindings. Preserve case-insensitive matching consistent with the app's search behavior across MySQL/MariaDB and SQLite.

## Risks / Trade-offs

- [Several `EXISTS` branches may slow broad substring searches] → Keep the outer transfer query scoped, avoid row-multiplying joins, and check the focused DataTable response with representative fixtures.
- [Serial evidence is stored in both JSON and normalized tables] → Map each persisted source during implementation and cover legacy and version 3 stages in focused tests.
- [A product may be deleted or a serial row may disappear] → Search remaining persisted transfer evidence where available; do not fail the list request on absent linked records.

## Migration Plan

No migration is required. Deploy the DataTable change and focused verification together. Rollback restores the previous DataTable filter behavior without changing stored data.
