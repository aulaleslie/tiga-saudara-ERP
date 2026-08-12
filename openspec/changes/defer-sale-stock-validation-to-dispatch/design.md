## Context

The standard Sales create flow calls `SaleService::createSale()`, which currently aggregates the requested quantities of stock-managed parent and bundle component products and rejects the document when a product's global `product_quantity` is insufficient. This occurs before the Sales order is persisted.

The Sales dispatch workflow is already the fulfillment boundary. It validates the selected business location, serial availability and tax context, requested quantity versus unfulfilled sale quantity, and available location stock before creating a pending dispatch. Dispatch approval locks the relevant stock row, rechecks availability, and deducts stock atomically. Pending dispatches also reserve selected serials, while approved dispatches set serials sold.

This change adopts a backorder-compatible Sales-order policy without changing POS checkout, which has its own immediate stock-validation requirements.

## Goals / Non-Goals

**Goals:**

- Permit standard Sales documents and their editable lines to be saved when stock-managed products or bundle components have zero or insufficient current stock.
- Make the dispatch workflow the sole stock-availability gate for standard Sales fulfillment.
- Preserve authoritative location, serial, tax-bucket, remaining-quantity, and locked approval-time stock protections.
- Provide regression coverage for saving an unfulfillable Sales order and rejecting its attempted fulfillment.

**Non-Goals:**

- Reserving non-serial stock at Sales-order creation or approval.
- Allowing a dispatch to exceed the sale's remaining quantity or available inventory.
- Changing POS checkout stock validation, inventory schema, sale status lifecycle, permissions, routes, or inventory accounting.
- Changing pending-dispatch serial reservation behavior.

## Decisions

### Remove the standard Sale-service aggregate stock preflight

`SaleService::createSale()` will persist normalized Sales headers, lines, bundle items, and cost snapshots without invoking its aggregate `validateStock()` preflight. The validation is not location-aware and is premature for an order that has not selected a fulfillment warehouse.

**Alternatives considered:**

- Keep the check but make it advisory: rejected because it still blocks the requested workflow and encourages users to bypass the Sales order.
- Move the check to Sales approval: rejected because approval remains demand authorization, while the actual stock source and timing remain unknown until dispatch.
- Add a special backorder flag: rejected because the requested rule is universal and no separate policy or UI was requested.

### Retain dispatch submission validation as the operational preflight

Dispatch submission will continue to verify authoritative selected-location availability for non-serial stock, selected serial state/location/tax context, pending serial reservations, and remaining sale quantity including bundle components. This gives dispatch users actionable feedback before a pending document is submitted for approval.

### Retain locked dispatch-approval validation as the inventory integrity gate

Dispatch approval will continue to lock each location stock record, recheck its quantity, mutate inventory, record transactions, update serial status/history, and transition the dispatch in one transaction. A submission-time check cannot safely replace this because stock can change while a dispatch awaits approval.

### Scope only the standard Sales workflow

The removal applies to direct Sales creation and the existing editable Sales update service path. POS checkout is deliberately excluded because its completed transaction includes immediate fulfillment/posting semantics governed by separate POS specifications.

## Risks / Trade-offs

- [Demand can exceed available inventory] → This is intentional backorder behavior; dispatch rejection remains clear and inventory stays unchanged.
- [Two pending dispatches can pass their initial stock check before one is approved] → Approval-time row locks and revalidation ensure at most the stock that exists is deducted; the later approval remains pending with an error.
- [Tests may encode the obsolete rejection policy] → Replace those assertions with order-save assertions and dispatch insufficiency coverage for ordinary, bundle, and Livewire-originated orders.
- [A broad edit could affect POS] → Limit code changes and tests to `Modules/Sale` and standard Sales Livewire/HTTP paths; retain POS test coverage untouched.

## Migration Plan

1. Deploy the application change with no database migration.
2. Verify that a zero-stock standard Sales order saves and that its dispatch is rejected until a valid location/stock quantity is available.
3. If rollback is necessary, restore the aggregate Sales create preflight; no data migration or cleanup is required because persisted orders remain valid records.

## Open Questions

- None. The policy is that standard Sales creation and edit record demand, while dispatch validates fulfillment stock.
