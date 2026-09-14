## Context

The product catalogue is business-owned, but production inventory can legitimately contain a `product_stocks` row at a location owned by another business. Transfer entry already authorizes the selected origin against the active tenant and later validates stock and serials at that origin. The shared resolver nevertheless adds `products.setting_id = session setting_id`, so a real product with ten units at an authorized origin is invisible when its catalogue owner differs.

The legacy `transfer_products` dispatch counters were introduced as non-null columns with zero defaults. A later MySQL `change()` operation made them unsigned without restating those defaults, and the live schema now has five non-null columns with no default. Draft creation intentionally omits dispatch-only state, so MySQL rejects inserts before a destination is required or any dispatch can occur.

## Goals / Non-Goals

**Goals:**

- Resolve transfer-entry products from the selected, authorized origin's actual stock and serial inventory even when catalogue ownership differs.
- Preserve active-tenant origin authorization, exact condition eligibility, availability checks, and permission-safe feedback.
- Restore database-owned zero initialization for every undispatched quantity counter.
- Cover the observed production shapes with focused tests, including MySQL schema semantics.

**Non-Goals:**

- Reassign product catalogue ownership or migrate/delete cross-business stock rows.
- Broaden access to products that have no eligible stock or serial at the selected origin.
- Change allocation order, tax classification, movement approval, dispatch, or receipt behavior.
- Backfill transfer history or legacy stock transactions.

## Decisions

### Use authorized origin inventory as the transfer discovery boundary

The resolver will continue to require that the selected origin belongs to the active tenant. After that authorization, exact barcode, conversion, text-search, and serial candidates may reference any active stock-managed product whose authoritative stock or serial state is eligible at that exact origin and condition. Product catalogue `setting_id` will not be a second ownership gate.

This matches persistence, which validates `ProductStock(product_id, origin_location_id)`, and avoids treating catalogue metadata as stronger authority than physical location inventory.

Alternative: repair the single observed product's `setting_id` or delete its cross-business stock. Rejected because the user confirmed the ten units are legitimate and the same mismatch can exist for other products.

Alternative: search all businesses without an origin constraint. Rejected because it would expose unrelated catalogue inventory and weaken tenant isolation.

### Apply the same rule across every entry path

Exact product barcode, conversion barcode, deliberate tokenized search, ambiguity selection, and serialized lookup must converge on the same origin/condition eligibility. This prevents a product from being scannable but unsearchable, or selectable through one endpoint but rejected through another solely because of catalogue ownership.

For non-serialized goods, positive eligible origin stock remains required. For serialized goods, the exact live serial must be at the selected origin, match the selected condition, and pass the existing reservation, dispatch, return, custody, and availability guards.

### Restore defaults in an additive repair migration

An additive migration will redefine the five existing dispatch quantity columns as unsigned, non-null integers with `DEFAULT 0`. Draft persistence will continue omitting them, allowing the database to express the invariant that an undispatched line begins at zero.

Alternative: populate five explicit zeros in every `TransferProduct::create()` call. Rejected because it duplicates a schema invariant across numerous callers and leaves direct inserts vulnerable.

The migration must preserve existing values and be reversible by restoring the immediately preceding column definition. Verification must inspect actual MySQL metadata because SQLite does not reproduce the observed default-loss behavior reliably.

### Keep verification focused

Tests will target resolver behavior for cross-catalogue stock at an authorized origin, isolation from other origins, serialized eligibility, draft creation with an unset destination, and schema defaults. The proposal does not require the full application suite.

## Risks / Trade-offs

- [Cross-catalogue products become visible through transfer entry] → Require active-tenant ownership of the selected origin and eligible inventory at only that origin before returning identity.
- [Different entry paths drift again] → Centralize eligibility in the shared resolver and exercise exact, conversion, search, and serial paths.
- [MySQL column alteration locks a large table] → Use one additive migration, assess table size before deployment, and schedule within the normal migration window.
- [A database driver handles `change()` differently] → Verify generated/live MySQL metadata and retain focused SQLite behavioral coverage separately.
- [Existing nonzero dispatch counters are overwritten] → Alter only column definitions; do not update row data.

## Migration Plan

1. Deploy the additive migration restoring zero defaults on all five dispatch counters.
2. Verify `information_schema.columns` or `SHOW COLUMNS` reports `DEFAULT 0` and existing counter values are unchanged.
3. Deploy the resolver eligibility adjustment and focused transfer-entry tests.
4. Smoke-test the known barcode at its authorized origin and save a serialized destination-optional draft.
5. If rollback is required, revert resolver behavior and migration definitions without deleting transfer data.

## Open Questions

None. The selected origin's inventory is authoritative, and undispatched counters default to zero.
