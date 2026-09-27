## Context

The sales and purchase Global Payment workspaces reuse the operational Livewire tables in an explicit global mode, including when embedded on customer and supplier detail pages. Both table queries already include the transaction header `note` in text search, but their reference cells do not render the note. The allocation pages use separate controller queries and Blade tables: sales candidates currently use ascending ID order, while purchase candidates have no explicit query order and are displayed through a DataTable with client-side ordering disabled.

The document selected from a Global Payment action is already carried into the allocation workflow as the starting sale route parameter or purchase `purchase_id` query parameter, and it receives the default full outstanding allocation. The change must align presentation and ordering across both modules without altering eligibility, settlement calculations, permissions, or submission services.

## Goals / Non-Goals

**Goals:**

- Make the existing sale or purchase header note visible immediately beneath the document number in Global Payment lists and allocation forms.
- Retain header-note search in standalone and customer/supplier-constrained Global Payment workspaces.
- Keep the entry document first in an allocation form and order all other candidates by the oldest due date first.
- Make candidate ordering deterministic and consistent on MySQL/MariaDB production and SQLite tests.
- Cover only the directly affected rendering, search, and candidate-order behavior with focused tests.

**Non-Goals:**

- Adding or changing note fields, inline note editing, note permissions, or payment memo behavior.
- Adding a search control to the sales allocation form or changing DataTable features on the purchase allocation form.
- Changing Global Payment eligibility, balances, default allocation values, validation, atomic settlement, routes, or permissions.
- Changing the presentation of normal setting-scoped sales and purchase lists.
- Running or planning the full application test suite.

## Decisions

### 1. Render notes in existing document-reference cells

The shared sales and purchase list templates will render the escaped header note beneath the reference only in Global Payment mode. The allocation templates, which are inherently global, will render the same note beneath each candidate reference. Blank notes will add no placeholder or extra row height.

This keeps the note adjacent to the identity it qualifies and avoids adding another wide table column. Conditional list rendering prevents an unrelated visual change to the normal operational lists. Blade's escaped output will be retained rather than rendering note content as HTML.

An alternative dedicated note column was rejected because it increases horizontal pressure in already wide payment tables and separates the context from the document number requested by users.

### 2. Preserve the existing list-query note search

The existing `SaleTable` and `PurchaseTable` global searches already match their respective `note` columns and apply equally to standalone and entity-constrained workspaces. Implementation will retain those predicates and add focused assertions that a matching note both returns the document and is visibly rendered beneath its reference.

Allocation-form search is not generalized by this change. The purchase DataTable can match rendered note text through its existing client-side search, while the sales allocation table has no search control; introducing one is outside the requested workspace search behavior and would broaden the UI change.

### 3. Apply a dominant entry-document sort followed by due-date order

Candidate queries will use a stable precedence:

1. The eligible entry document, when supplied, receives priority `0`; every other document receives priority `1`.
2. Dated documents precede documents with a null due date.
3. Remaining documents sort by `due_date` ascending.
4. Equal due dates sort by primary key ascending.

The priority and null placement will use bound `CASE` expressions through Eloquent query ordering, followed by ordinary `orderBy` clauses. This is portable across the production database and SQLite, avoids interpolating request values, and delivers the intended row order before Blade or DataTables renders it. The purchase form entered only from a supplier context without a `purchase_id` will omit the pinning expression and use due-date order for every candidate.

Sorting the loaded collection in Blade was rejected because ordering belongs with candidate selection, is harder to assert independently, and could diverge between the two views. Client-side ordering was also rejected because it could displace the starting document and would require custom pinned-row behavior across pagination and redraws.

### 4. Preserve allocation semantics independently of row order

The existing identity comparison will continue to assign the starting document its full live outstanding balance and all other candidates zero by default. Old submitted allocation values continue to override defaults. Reordering changes presentation only; allocation field keys remain document IDs and server-authoritative validation remains unchanged.

## Risks / Trade-offs

- [Long header notes can make allocation rows taller] → Keep the note in muted, wrapping secondary text beneath the reference and avoid a separate column.
- [Database null ordering differs across engines] → Use an explicit `CASE WHEN due_date IS NULL` ordering key rather than relying on engine defaults.
- [A request can name an ineligible starting purchase] → Preserve the existing candidate membership check and redirect; pinning never makes an ineligible document visible.
- [Shared list templates also serve normal workflows] → Gate the additional note presentation on Global Payment mode and add regression coverage for the global surface specifically.
- [Interactive purchase-table filtering can hide a pinned row when it does not match a user search] → Define pinning as the dominant candidate order, not an exemption from explicit filtering.

## Migration Plan

Deploy the controller-query, Blade, and focused test changes together. No data migration, backfill, cache invalidation, or configuration change is required. Rollback restores the previous templates and candidate ordering without affecting stored notes or payments.

## Open Questions

None. Searchability is scoped to the Global Payment workspace lists; adding search to the sales allocation form can be proposed separately if required.
