## Context

The Product CRUD form uses conditional stock configuration, per-setting product prices, and conversion barcode identities. The current request rules require positive purchase and sale prices and can validate conversion rows even after stock management is disabled. POS discovery and cart services currently assume every directly sold product is stock-managed. Sales already persist the authoritative invoice due date at `sales.due_date`, but Global Payment history and the POS read-only sale modal omit it.

## Goals / Non-Goals

**Goals:**

- Make standard product price fields optional and zero-safe.
- Make disabling stock management a valid, atomic cleanup of conversions for products with no positive stock in any setting.
- Treat non-stock-managed catalog products as sellable POS services without bypassing inventory protections for stock-managed products.
- Surface existing due dates in the two requested read-only/list contexts.

**Non-Goals:**

- No schema changes, due-date calculation changes, or payment-term workflow changes.
- No changes to product quick-add validation unless separately proposed.
- No changes to stock-managed product availability, serial, dispatch, or inventory mutation rules.
- No retention of conversions after stock management is disabled.

## Decisions

### Use `nullable|numeric|min:0` for standard catalog price inputs

The Product Create and Update requests will use the same optional non-negative rule for purchase, base sale, and tier sale prices. Omitted values continue through existing zero defaults during persistence. This replaces positive-only validation because zero is a valid configured price; allowing negative values would create invalid monetary data.

### Treat the stock-management toggle as the authoritative conversion-cleanup trigger

When a submitted edit turns `stock_managed` off, request normalization will discard conversion input before validation so stale Livewire/HTML fields cannot fail `unit_id` rules. The update transaction will then remove existing conversion rows, their setting-scoped prices, and barcode identities. This intentionally deletes conversions rather than preserving inactive records, per agreed scope.

The existing global `ProductStock` positive-quantity guard remains the eligibility condition for enabling this transition. The server must enforce it as well as the UI so a stale edit page cannot disable stock management after stock appears.

### Model POS service availability separately from stock quantity

Search and scan responses will include the product's `stock_managed` state. A non-stock-managed product remains price-scoped to the active setting but is selectable with zero displayed inventory, and exact barcode lookup can auto-select it. Cart mutations will skip availability comparisons for those lines while retaining the current comparisons, serial guards, and stock allocation behavior for stock-managed lines. Using an artificial infinite inventory value is rejected because it would leak misleading quantities into the UI and transaction snapshots.

### Reuse `sales.due_date` as the only due-date source

Global payment history will eager-load/join the related Sale and expose a due-date column. The POS checkout-linked sale modal already loads the Sale, so its invoice summary can render the same field. This avoids duplicated due dates on payment or detail rows and guarantees the display follows the authoritative invoice date.

## Risks / Trade-offs

- [Client state posts stale conversion fields] → Normalize them away when stock management is disabled and verify with an HTTP update test.
- [Conversion deletion leaves barcode identities reserved] → Release identities before/with deleting rows inside the existing transaction and test reuse after the transition.
- [Service product still appears out of stock in one POS entry point] → Cover keyword search, exact barcode scan, add-to-cart, and quantity increase in focused tests.
- [Stock-managed products accidentally lose their guard] → Branch all availability behavior on the persisted `stock_managed` state and retain existing out-of-stock regression tests.
- [Additional query cost for payment-history due date] → Eager-load/select the related Sale rather than resolving it per row.

## Migration Plan

Deploy application and tests without a database migration. Rollback is code-only. Conversion deletion is intentionally irreversible once a user disables stock management; the edit action remains unavailable while any positive stock exists, and the destructive behavior will be explicit in the UI/implementation messaging if an existing pattern is available.

## Open Questions

None.
