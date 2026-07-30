## Context

`product_prices` already provides a unique `(product_id, setting_id)` record containing sale, tier, last purchase, average purchase, and optional tax values. The product list and standard product forms operate in the active setting. In particular, standard create/edit currently maps the manually entered purchase price to both `last_purchase_price` and `average_purchase_price`, even though the latter is an inventory-derived cost value.

The requested workflow is intentionally narrow: a suitably authorized user needs to maintain one product's commercial prices for every business from one page, without changing tax metadata or stock-derived average cost. All businesses use IDR.

## Goals / Non-Goals

**Goals:**

- Provide a permission-protected action from the product list and a dedicated cross-business price page.
- Render every setting as one price row, including settings without a stored row.
- Keep the initial page read-only; make all editable price inputs available only after an explicit edit action.
- Save all editable rows atomically, upserting missing rows and preserving average purchase price and taxes.
- Prevent stale bulk saves and duplicate submissions without adding tables or long-lived locks.
- Make manual purchase-price updates target last purchase price only; retain purchase workflows as the source of average cost.

**Non-Goals:**

- Editing purchase or sales tax assignments, product identity, stock, or unit-conversion prices from the new page.
- Changing purchase receiving/approval or normalization calculations that determine average purchase price.
- Adding currencies, persistent edit locks, audit tables, schema columns, or a mass multi-product price editor.

## Decisions

### Dedicated product-module page and permission

Add `products.manage_cross_business_prices` to the centralized permission configuration. It protects the product-list action and both page endpoints; this permission itself grants access to the page's sensitive price data.

The page remains in the Product module and uses the existing setting and `ProductPrice` models. This follows the existing Product controller, route, Blade, and permission conventions rather than introducing a generic pricing subsystem.

Alternative considered: add the controls to the standard product edit screen. Rejected because that form has product, tax, media, stock, and conversion responsibilities and is scoped to the active setting.

### Page-level view and edit state

The page loads every setting with its matching price row, defaulting missing values to zero. In view state, all five price columns are read-only. `Ubah` transitions only the editable fields—sale, tier 1, tier 2, and last purchase price—into inputs. Average purchase price remains a read-only display in every state. `Batal` restores the loaded view state; `Kembali` navigates to the product list.

The labels distinguish the manually maintained `Harga Beli` / Purchase Price (`last_purchase_price`) from the inventory-derived `Harga Beli Rata-rata` (`average_purchase_price`).

### Atomic write with preservation rules

The save request carries the product ID, each setting ID, the four editable decimal-Rupiah values, and the loaded row version. The server validates all values as non-negative before entering a database transaction.

Within the transaction, it conditionally updates existing rows by primary key and `updated_at`, or creates rows that were missing at load. Existing rows update only `sale_price`, `tier_1_price`, `tier_2_price`, and `last_purchase_price`; their average purchase price and tax IDs are not written. Newly created rows set the four submitted editable values, `average_purchase_price` to zero, and tax IDs to null. Any validation, stale-version, or create-race failure rolls back every row and reports a reload-required conflict.

Alternative considered: `lockForUpdate` only during save. Rejected as the sole strategy because it serializes simultaneous submits but does not detect a page that was edited from stale values. The selected optimistic version check uses existing timestamps and needs no architectural or schema change.

### Manual price versus purchase-derived cost

Product creation seeds last purchase price from the entered purchase price but initializes average purchase price to zero. Standard product editing updates the active setting's last purchase price without assigning average purchase price. Purchase receiving/approval and approved-history normalization retain responsibility for updating both purchase-cost snapshots where their existing workflows require it.

Alternative considered: retain the legacy behavior of copying manual price into average. Rejected because it corrupts the weighted/inventory-derived average without a purchase event.

### Submission protection

The Save button disables synchronously on submit and remains disabled while the request is pending. The server-side transaction and optimistic checks remain authoritative, so direct repeated requests cannot produce partial writes.

## Risks / Trade-offs

- [Stale browser overwrites a newer price] → Compare the loaded `updated_at` version on every existing row and reject the whole save on a mismatch.
- [A price row is created by another request after the page loaded] → Treat the unique-key create collision as a stale conflict and roll back the batch.
- [Missing row has no tax configuration] → Limit the page to prices; create such a row with null tax IDs and leave tax maintenance in standard product configuration.
- [A failed row leaves some businesses changed] → Validate first and persist all rows inside one transaction.
- [Manual price edits distort stock valuation] → Never write `average_purchase_price` outside purchase-derived workflows.

## Migration Plan

1. Deploy additive routes, permission registration, page/controller/view code, and standard create/edit behavior correction; no database migration is required.
2. Run the established permission sync/seeder so the new permission exists and is granted using existing role policy.
3. Verify with focused feature tests, then validate a product with existing and missing business price rows.
4. Roll back by removing access to the new route/action. Existing `product_prices` records remain valid because the change does not alter their schema or destructive data.

## Open Questions

None. The agreed scope is price-only, IDR-only, page-level editing with atomic optimistic saves, and no persistent locking.
