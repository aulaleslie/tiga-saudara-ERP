## Context

The POS Sell page (`/pos/sell`) is driven by vanilla JS calling controller endpoints (`PosSellController`), with cart state held in the Laravel session managed by `PosCartSessionStore`. Cart snapshots are decorated with resolved customer information via `PosCheckoutCustomerResolverService` and `PosCartService::buildSnapshot()`.

Separately, `Setting.pos_walk_in_customer_id` exists in Settings and was previously consulted only during split/multi-source checkout resolution (`PosCheckoutGroupCustomerResolverService`).

## Goals / Non-Goals

**Goals:**
- Lazily resolve `Setting.pos_walk_in_customer_id` via `PosCheckoutCustomerResolverService` when `selected_customer_id` is null.
- Preserve distinction between explicit customer choice (`resolution_source: 'selected'`) and system default (`resolution_source: 'walk_in'`).
- Leave `emptyCart()` clean (`selected_customer_id: null`).
- Keep `CUSTOMER_UNRESOLVED` checkout protection intact when no customer is selected AND no walk-in default is configured on the setting.
- Support both `selected` and `walk_in` resolution sources in frontend UI (`sell.blade.php`) and checkout finalization.

**Non-Goals:**
- Eagerly mutating stored session cart customer id on cart creation.
- Changing split checkout group resolution logic.

## Decisions

**Lazy resolution in `PosCheckoutCustomerResolverService`.**
Mirroring `PosCheckoutGroupCustomerResolverService`, customer resolution checks:
1. `selected_customer_id` > 0 → `resolution_source: 'selected'`
2. `Setting.pos_walk_in_customer_id` > 0 → `resolution_source: 'walk_in'`
3. Otherwise → `resolution_source: 'none'` (triggers `CUSTOMER_UNRESOLVED` at finalize time).

**Frontend display & button gating in `sell.blade.php`.**
`resolution_source: 'walk_in'` displays the resolved customer badge with a `Default` tag and enables draft saving / checkout buttons. Clear-cart button remains clean and only enables when items exist or explicit customer is selected.

## Risks / Trade-offs

- **[Risk]** If walk-in default points to a nonexistent or deleted customer, fallback gracefully drops to `none`.
- **[Risk]** Setting without walk-in default configured blocks checkout with `CUSTOMER_UNRESOLVED` as expected.

## Migration Plan

No data migration required — this is a pure service layer resolution update.
