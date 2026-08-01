## Context

The Global Sales Payment and Global Purchase Payment workspaces reuse their operational tables in cross-setting mode. Their default queries currently require a positive canonical live due balance, so fully paid documents cannot be inspected there. The summary cards switch the table into separate outstanding, overdue, or recent-paid views; sales and purchases presently apply the paid-card condition differently.

The requested workspace is a financial-document browser for every payment-eligible lifecycle state, while payment creation remains a payable-only operation. It must expose business context and use the existing persisted header, detail, party, external-reference, and sales-bundle snapshot data for searching.

## Goals / Non-Goals

**Goals:**

- List eligible paid and payable global sales/purchases without session-setting scope.
- Make business, document-date range, text search, and one selected summary-card state compose predictably.
- Preserve canonical live-balance logic and ensure fully paid rows are read-only.
- Make requested search terms work against persisted historical document data, including sales bundle-item names.

**Non-Goals:**

- Altering payment allocation, payment ledgers, customer credits, supplier/customer matching, or document lifecycle rules.
- Adding a new global-payment header, reporting/export workspace, new database schema, or catalogue-based bundle lookup.
- Searching payment notes, payment references, attachments, or arbitrary current product/bundle catalogue names.

## Decisions

### 1. Use one base eligibility query and layer independent filters

For sales, the base query remains non-archived `APPROVED`, `DISPATCHED PARTIALLY`, or `DISPATCHED`; for purchases, non-archived `RECEIVED`. It no longer restricts the base table to positive live due. The selected summary card adds its own balance/payment condition, while business, document-date, and free-text conditions are always applied alongside it.

This avoids the current mode-dependent branching where paid records appear only after a card click. It also makes a card an actual refinement rather than a replacement query.

Alternative considered: union separate payable and paid queries. Rejected because pagination, sorting, search, and duplicate/overpayment handling become substantially more complex than one lifecycle query with canonical balance predicates.

### 2. Define paid and payable by canonical live balance

Payable means `live_due_amount > 0`; fully paid means `live_due_amount <= 0`. The existing live-balance scopes remain the source of truth, including active-payment status and existing sales credit applications. Stored header `payment_status` and `due_amount` are presentation/reconciliation fields, not eligibility authority.

The outstanding and overdue cards continue to select payable records; overdue additionally requires document due date before today. The paid card selects fully paid records with an active payment in its displayed recent 30-day window. The sales card is aligned to this same fully-paid definition rather than treating any recent payment activity or recent document creation as paid.

Alternative considered: use stored `payment_status = PAID`. Rejected because invalidations, credits, and stale headers can diverge from the live financial state.

### 3. Filter by business and document date in the tables

Global-mode tables load selectable Settings as businesses and filter by their `setting_id`. Rows show the related business/company name so duplicate-looking transaction references remain distinguishable. Date range filters apply to the document header `date` column, inclusively, with either boundary independently optional. Invalid ranges are rejected or normalized before the query executes.

Filter changes reset pagination. The controls are scoped to global mode, preserve normal operational table behavior, and participate in query-string state where that table already persists its filters.

Alternative considered: filter by payment date. Rejected because a document can have multiple payments, be partially paid, or be paid through credit; payment-date analysis belongs in a payment report rather than this document list.

### 4. Search persisted document fields through a single grouped predicate

The table search remains one case-insensitive text term and searches, within one parenthesized OR group: document reference; customer/supplier name; detail product name/code; header note; external numbers; existing sales POS identifiers; and, for sales, `sale_bundle_items.name`. Purchases retain both external fields: `supplier_purchase_number` and `supplier_reference_no`; sales retain `imported_sales_reference_number`.

The grouped predicate is applied after lifecycle, archival, card, business, and date constraints, preventing an OR search match from escaping a selected filter. Sales bundle search uses the stored line snapshot, not a current product-bundle relation, so historical documents remain findable after catalogue renames.

### 5. Separate inspection authorization from payment creation eligibility

Global show/history routes accept any base-eligible paid or payable document. Row actions and detail controls expose create-payment only for a user with the existing create permission and a positive canonical live due. Fully paid rows retain read-only detail and payment history, but payment routes/forms reject them as they do today.

Alternative considered: leave show/history payable-only. Rejected because it would make newly visible paid rows lead to inaccessible detail pages.

## Risks / Trade-offs

- [Live-balance subqueries plus text `LIKE` / relationship searches can be expensive at scale] → Keep predicates scoped to eligible lifecycle records, eager-load displayed relationships, add focused query tests, and assess indexes with production-like data before adding migrations.
- [Multiple setting currencies can make cross-business card totals misleading] → Preserve existing currency presentation for this change; do not introduce currency aggregation semantics without a separate reporting decision.
- [Paid-card semantics change for sales] → Cover fully paid/partial/recent-payment boundary cases in tests and keep the 30-day label aligned with the query.
- [Existing direct global detail links may assume outstanding-only access] → Test paid and payable access separately while retaining normal setting-scoped routes.

## Migration Plan

No data migration is required. Deploy the table/view/controller eligibility and filter changes with focused tests. Rollback is code-only: restore the prior outstanding-only table query and read-only-route guard while leaving payment data untouched.

## Open Questions

- None. The date filter is defined as document date and the paid card as fully paid within its displayed recent-payment window.
