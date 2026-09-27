## Context

The four affected operational lists do not share one implementation layer. Purchase and sales indexes render Livewire table components, while purchase return and sale return indexes still use Yajra DataTables. Search behavior is also uneven:

- `PurchaseTable` searches purchase reference, supplier purchase number, tax reference, supplier, tags, and product name.
- `SaleTable` searches sale reference, imported sales reference, tax reference, customer, product name, and tags.
- `PurchaseReturnsDataTable` does not define an explicit global search and relies on DataTables/default column behavior.
- `SaleReturnsDataTable` does not define an explicit global search and mainly exposes return reference, sale reference, and customer name columns.

Document details already snapshot `product_name` and `product_code`, which is important for historical search because product master data can change after document creation. Return details also point back to source purchases/sales, so return list searches can traverse source documents without adding duplicated columns.

## Goals / Non-Goals

**Goals:**
- Make the four operational list searches match practical user lookup behavior: internal reference, external/import/tax reference, counterparty, product name, and product code.
- Keep existing list filters, status cards, archive toggles, sorting, pagination, permissions, and action rendering intact.
- Use historical document detail snapshot fields for product matching.
- Use source document relationships for return list source-number matching.
- Keep the implementation additive and localized to list query behavior.

**Non-Goals:**
- No new search UI, advanced search builder, or global search page.
- No change to document lifecycle, stock, serial, approval, receiving, settlement, payment, or archival behavior.
- No migration or denormalization of return source reference fields.
- No requirement to include purchase receiving delivery-note numbers in the main purchase list unless that scope is explicitly added later.
- No full-text search engine or external dependency.

## Decisions

### Decision: Use two query integration patterns matching the existing UI stack

Livewire-backed lists should update their existing query closures. DataTables-backed return lists should add explicit global search filtering in each DataTable query method.

Alternatives considered:
- Convert return lists to Livewire. Rejected because it is broader than the search change and would risk unrelated UI behavior.
- Move purchase/sales back to DataTables. Rejected because current index views render Livewire tables.
- Build one shared query object for all four lists. Deferred because the query shapes differ enough that premature unification could obscure source-specific fields.

### Decision: Search document detail snapshots before current product relations

Product matching should use detail snapshot columns (`product_name`, `product_code`) on purchase, sale, purchase return, and sale return detail tables. Current product relations can be used as a fallback only if needed.

Alternatives considered:
- Search only `details.product`. Rejected because historical documents should remain searchable by the product label/code stored on the document even if the product master changes or is deleted.
- Search only product master fields. Rejected for the same historical-data reason.

### Decision: Search return source documents through relationships

Purchase return search should traverse `purchaseReturnDetails.purchase` for source purchase references. Sale return search should traverse `sale` for source sale references.

Alternatives considered:
- Copy source external numbers onto return headers. Rejected because it introduces data duplication and would need backfill/migration decisions.
- Search only visible return columns. Rejected because it does not satisfy the user workflow of starting from the original invoice/external number.

### Decision: Keep purchase receiving delivery-note numbers out of initial scope

`received_notes.external_delivery_number` is related to purchases, but it is a receiving document number rather than a purchase header or return source number. It should be an explicit future extension if operational users expect the purchase list search to find supplier delivery notes.

Alternatives considered:
- Include delivery-note numbers immediately. Rejected for initial scope because it adds another relationship path and may surprise users by matching receiving records from the purchase list search.

### Decision: Prefer `whereHas`/`orWhereHas` search predicates over manual joins

Relationship predicates avoid duplicate parent rows when multiple detail lines match. They also fit the existing Eloquent style in the Livewire list components.

Alternatives considered:
- Manual joins with `distinct`. Viable, but more error-prone across DataTables count queries and permission/filter composition.

## Risks / Trade-offs

- Broad `LIKE "%term%"` searches across detail rows can be slow on large data sets -> Keep predicates scoped to the active list filters, use relationship `EXISTS` semantics, add focused tests, and consider indexes on exact reference/external columns if production query plans require it.
- DataTables may also apply its own column search in addition to custom global search -> Ensure custom global search is composed in the query path and computed/action columns are not treated as searchable.
- Return source-document matching can be ambiguous when a return has multiple lines from different source purchases/sales -> Match the return if any linked source document or detail line matches.
- Product code/name snapshots may be blank on legacy rows -> Use null-safe `LIKE` predicates and do not fail rendering or search when snapshot values are absent.
- Search behavior may differ between MySQL JSON tag search and SQLite tests -> Keep this change focused on document/detail/source columns; do not expand tag behavior in this proposal.
