## Context

Currently, the multi-tenant POS logic incorrectly treats **POS Transactions** the same as **Sales Documents**. When a terminal in Setting A sells stock belonging to Setting B:
1. `PosTransactionService::completeFromCartSnapshot` splits the cart into two separate `PosTransaction` records.
2. `InlinePosCheckoutPostingAdapter` attributes all stock mutations to Setting A (the active setting), even for stock physically located in Setting B.

This creates fragmented history for the cashier and corrupted inventory audits for the tenants.

## Goals / Non-Goals

**Goals:**
- Unify the POS history under the active terminal's setting.
- Ensure inventory mutations (stock movements) are recorded in the history of the actual stock owner.
- Maintain existing split logic for Sales/Invoices (legal documents).

**Non-Goals:**
- Unifying Sales/Invoice documents (these MUST remain split for accounting).
- Changing the stock allocation logic (it is already working correctly).

## Decisions

### 1. Unified POS Transaction Persistence
**Decision**: Modify `PosTransactionService::completeFromCartSnapshot` to always persist exactly one `PosTransaction` record in the setting of the `activeSession`.
- **Rationale**: A POS Transaction represents a "User Event" at a terminal, not the legal transfer of goods. The cashier should see one unified transaction in their history, and the code sequence should only be consumed from the active setting.
- **Alternatives Considered**: Keeping them split but adding a parent-child relationship. *Rejected because it adds unnecessary complexity to the UI and reporting queries.*

### 2. Context-Aware Mutation Attribution
**Decision**: Update `InlinePosCheckoutPostingAdapter::post` to use `$chunk['source_setting_id']` for the `Transaction::create` call instead of the global `$settingId`.
- **Rationale**: Inventory mutations represent the physical movement of goods. If Setting B's stock is sold, Setting B's inventory log must record the deduction.
- **Alternatives Considered**: Using a trigger on `product_stocks` updates. *Rejected due to existing reliance on explicit Transaction entity creation for audit notes.*

## Risks / Trade-offs

- **[Risk]** Item-level reporting might be confusing if one transaction shows items from another tenant. → **[Mitigation]** Standard item sales summaries already use `Sale` records, which remain split. We will only change the `pos_transactions` history view.
- **[Risk]** Code generator collisions if multiple settings try to generate codes for the same transaction. → **[Mitigation]** By unifying under the active session setting, we only ever call the code generator for one setting per checkout.
