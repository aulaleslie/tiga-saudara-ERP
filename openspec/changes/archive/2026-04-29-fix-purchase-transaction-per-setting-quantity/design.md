## Context

The `PurchaseController::approveReceiving()` method processes purchase receiving approvals. When recording a `Transaction` log, it captures `previous_quantity`, `after_quantity`, and `current_quantity` from `$product->product_quantity` — the **global** aggregate across all settings. The product show page's transaction history table (`ProductTransactionsTable`) filters transactions by `location.setting_id`, so users see global numbers in a per-setting context.

The `product_stocks` table stores quantity **per location** (keyed by `product_id + location_id`). A setting owns locations via `locations.setting_id`. The per-setting stock total is therefore `SUM(product_stocks.quantity) WHERE location.setting_id = X`.

The `transactions` table already has two tiers of quantity tracking:
- `previous_quantity` / `after_quantity` / `current_quantity` — aggregate level (currently global, should be per-setting)
- `previous_quantity_at_location` / `after_quantity_at_location` — per-location level (already correct)

## Goals / Non-Goals

**Goals:**
- Record `previous_quantity`, `after_quantity`, and `current_quantity` in the transaction log as the **per-setting** sum (across all locations belonging to the purchase's setting).
- Ensure transaction history displayed on the product show page shows meaningful, setting-scoped numbers.

**Non-Goals:**
- Changing the global `product.product_quantity` increment logic (it is correct as-is).
- Changing `previous_quantity_at_location` / `after_quantity_at_location` (already correct).
- Fixing `setting_id` on the transaction record (`session('setting_id')` vs `$purchase->setting_id`) — separate concern.
- Backfilling existing historical transaction records.
- Modifying the `PurchaseImportService` (same pattern exists there but is a separate change).

## Decisions

### Decision 1: Compute per-setting sum from ProductStock + Location

**Choice:** Query `ProductStock::whereIn(location_id, <setting's locations>)->sum('quantity')` before the stock increment.

**Alternative considered:** Sum from `Transaction` history — rejected because `Transaction` records may have gaps or be inconsistent, and `ProductStock` is the source of truth for current stock levels.

**Alternative considered:** Add a `setting_id` column to `product_stocks` — rejected as unnecessary; the setting relationship already exists through `location.setting_id`.

### Decision 2: Query location IDs once per approval, not per detail line

**Choice:** Pre-fetch `$settingLocationIds = Location::where('setting_id', $purchase->setting_id)->pluck('id')` once outside the detail loop, then reuse for all lines within the same approval.

**Rationale:** The approval method already pre-loads products and product stocks with `lockForUpdate()`. Adding a single location query is negligible overhead and avoids N repeated queries inside the loop.

### Decision 3: Compute after_quantity arithmetically

**Choice:** `$after_quantity_for_setting = $previous_quantity_for_setting + $receivedQuantity` instead of re-querying after increment.

**Rationale:** Within a `DB::transaction` with locked rows, the arithmetic is guaranteed correct and avoids an extra query. This matches the pattern used in `AdjustmentController`.

## Risks / Trade-offs

- **[Minimal performance cost]** → One additional `SUM()` query per product line within the approval transaction. Mitigated by reusing the pre-fetched location IDs and the fact that approvals process a bounded number of lines.
- **[Historical data inconsistency]** → Old transaction records still have global quantities. This is acceptable — we are not backfilling, and the fix only applies going forward.
- **[Multi-location settings]** → If a setting has multiple locations, the per-setting sum includes all of them. This is the correct behavior for "how much stock does this setting own."
