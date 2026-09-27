## Why

In a multi-tenant POS environment where a cashier in one setting (Terminal A) can sell stock owned by another setting (Setting B), the current implementation incorrectly fragments the POS transaction history and misattributes inventory mutations.

Specifically:
1. **POS Transaction Fragmentation**: Currently, the system creates separate `PosTransaction` records for each stock-owning setting. This causes the cashier's history in Setting A to be incomplete, while Setting B's history is cluttered with "phantom" transactions from other terminals.
2. **Incorrect Mutation Attribution**: Inventory mutations (stock deductions) are being attributed to the cashier's setting ID instead of the actual stock owner's setting ID. This corrupts the inventory audit trail for both tenants.

## What Changes

This change unifies the customer-facing and session-facing POS history while strictly localizing the backend inventory and sales accounting.

1. **POS Transaction Unification**: Modify the `PosTransactionService` to create/update exactly **one** `PosTransaction` record per checkout, always owned by the active POS session's setting.
2. **Inventory Mutation Localization**: Update the `InlinePosCheckoutPostingAdapter` to attribute stock mutations to the `source_setting_id` of the individual stock allocation chunk, ensuring each tenant's inventory ledger is accurate.
3. **Traceability**: Maintain the existing split in `Sale` documents (Sales Documents) and ensure the unified `PosTransaction` correctly links to the unified `PosCheckout`.

## Capabilities

### Modified Capabilities
- `pos-cart-management`: Ensure the finalization of a cart results in a single POS transaction regardless of stock origin.
- `pos-checkout-split-posting`: Refine the posting adapter to correctly attribute inventory mutations to the source setting of the stock.
- `pos-stock-posting-bucket-alignment`: Ensure inventory history (mutations) reflects the correct setting ownership.

## Impact

1. **PosTransactionService**: Logic for grouping and persisting transactions will be simplified to a unified model.
2. **InlinePosCheckoutPostingAdapter**: Inventory mutation creation will be updated to use chunk-level setting attribution.
3. **Database Integrity**: Inventory audit trails will now correctly reflect which tenant lost stock during a cross-tenant POS sale.
