## Context

Product Bundle creation currently replicates one submitted component informational price into every setting copy. The item table resolves only the active setting's product price and leaves it editable, while the controller validates and persists the client value. POS split planning may then replace a zero/missing snapshot with a live active-setting product price and derives tax buckets from each source owner. Normal Sales already keeps components non-billable, but its discount behavior needs an explicit parent-only contract.

The change crosses Product administration, Normal Sales, POS cart snapshots, split planning, posting, tax persistence, and receipt reconstruction. Existing bundle definitions and historical transactions must remain readable, and later HPP work needs stable component identity, quantity, owner, and allocation inputs.

## Goals / Non-Goals

**Goals:**

- Make component informational-price snapshots server-authoritative and independently setting-scoped.
- Preserve transaction-level parent price overrides without repricing components.
- Keep Normal Sales components non-billable and discounts parent/commercial-row scoped.
- Make POS component allocations originate only from the POS owner's captured bundle snapshot.
- Reconcile POS owner documents and apply bundled tax only to the POS-owner allocation.
- Add focused tests for replication, snapshots, tampering, quantity, overrides, tax, receipts, and exact totals.

**Non-Goals:**

- Component HPP snapshot columns, fallback implementation, notifications, or profitability report changes.
- POS line or global discount functionality.
- Bundle definition lifecycle, cart drift beyond price-snapshot preservation, serial support, returns, or broad report rewrites.
- Historical data rewrites or removal of legacy bundle price columns.

## Decisions

### Resolve informational prices on the server for each bundle copy

Bundle create/update orchestration will resolve `ProductPrice.sale_price` by component product and the bundle copy's `setting_id`. Replicated creation uses the active setting's price only when a target setting lacks its own row. If both are unavailable, the transaction fails atomically. Client informational-price values are presentation data and never persistence authority.

Alternative considered: copy the active setting value to every setting. Rejected because independent setting copies need their own revenue-allocation snapshots.

### Refresh snapshots on administrative save, not during transactions

Saving one setting's copy rebuilds its items using freshly resolved setting prices; other copies remain untouched. Sales and POS capture the saved values and never reload live component prices, including when the saved value is zero.

Alternative considered: always calculate from current `ProductPrice`. Rejected because open carts, retries, receipts, returns, and owner reconciliation require stable historical allocation inputs.

### Keep parent price mutable and component allocations fixed

Normal Sales and POS preserve an explicit parent row price override. Component snapshots remain fixed, so POS computes parent residual as captured row amount minus component allocations. A negative result is rejected before posting. The override is a price, not a POS discount.

Alternative considered: proportionally scale component allocations. Rejected because the saved POS-owner component sale prices are the intended internal owner allocations and the parent absorbs commercial price changes.

### Keep Normal Sales and POS monetary representations distinct

Normal Sales remains single-owner: its parent `SaleDetail` carries the complete commercial value and component rows remain zero/non-billable. Normal Sales row discounts reduce the parent; global discounts use existing transaction proration across commercial rows only. POS may persist commercial component allocations in source-owner documents, while customer views continue to show the parent total and zero/free components.

Alternative considered: use the POS residual model in Normal Sales. Rejected because Normal Sales does not owner-split and existing downstream behavior treats its bundle items as composition.

### Separate POS revenue source, document owner, and tax source

For POS bundles:

- Revenue amount comes from the POS owner's captured bundle snapshot.
- Actual stock/source ownership chooses the Sales document and dispatch source.
- Only the POS-owner allocation is taxable when that owner is PKP; other source-owner bundle allocations are non-tax.

The split planner must carry terminal/POS-owner identity separately from each source owner instead of using source-owner PKP state to decide bundle tax. Non-bundle tax behavior remains unchanged.

Alternative considered: retain source-owner tax buckets for bundle parts. Rejected because the agreed bundle commercial tax belongs only to the POS transaction owner allocation.

### Preserve minor-unit reconciliation and transaction atomicity

All decomposition uses integer minor units. The planner validates nonnegative residual and exact line decomposition before posting. Finalize continues verifying aggregate owner grand totals and payments inside its existing atomic/idempotent transaction boundary.

## Risks / Trade-offs

- [Existing bundles may contain edited or active-setting-copied snapshots] → Do not rewrite history; refresh a copy only when an administrator saves it and cover legacy display/use with regression tests.
- [Target setting lacks a component price] → Use the explicit active-setting fallback and fail atomically only when neither price exists.
- [Changing bundle tax policy can affect stock tax-bucket assumptions] → Limit the POS-owner-only rule to selected bundle allocations and test dispatch/inventory persistence separately from non-bundle behavior.
- [Receipt total and tax base intentionally differ] → Persist and test the taxable POS-owner allocation so receipt tax equals internal posted tax, while customer total remains the full bundle price.
- [Reports may double-count POS component allocation] → Do not rewrite reports here; preserve stable identities and record the concern for Sequence 11.
- [HPP remains incomplete for components] → Keep HPP out of this change and preserve the inputs required by Sequence 9.

## Migration Plan

1. Deploy code and tests without destructive schema changes.
2. Existing bundle copies retain saved informational prices until individually edited and saved.
3. Newly created replicated copies use per-setting server-derived snapshots immediately.
4. Monitor POS finalize validation for negative residuals and reconciliation mismatches.
5. Roll back application code if necessary; persisted historical bundle and transaction data require no rollback migration.

## Open Questions

None for this change. HPP snapshot timing, last-purchase fallback ordering, missing-HPP storage, and notification are explicitly deferred to Sequence 9.
