# Seeding Average Purchase Prices from Sales HPP Snapshots

## Overview

After importing historical sales HPP (Harga Pokok Penjualan) snapshots via `product:sales-hpp-snapshot-import`, the system must establish current average purchase prices in `product_prices` for live POS and standard sales. The `product:seed-average-cost-from-sales-hpp` command provides an operator-controlled reconciliation step that previews and applies this seeding.

## Why This Step Is Needed

The sales HPP importer correctly applies source-ledger cost snapshots to matched historical `sale_details`, but it intentionally does not mutate `product_prices`. This separation ensures:
- Historical sale snapshots remain immutable
- Current catalog prices are not accidentally overwritten during partial imports
- Operators can review and approve the seeding process

**Note:** This command seeds only `average_purchase_price`. `last_purchase_price` is owned exclusively by the purchase-import workflow and is never touched by this command.

## Operator Workflow

### Step 1: Complete and Review HPP Import

1. Run the HPP CSV import through the product import batch workflow
2. Verify that the import completed successfully with the expected row counts
3. Check for failed rows and resolve any data issues

### Step 2: Run Dry-Run Preview

```bash
php artisan product:seed-average-cost-from-sales-hpp
```

This command runs in **dry-run mode by default** (does not write to the database).

**Expected output:**
- `== DRY-RUN MODE ==`
- Considered products count
- Created (missing rows to create across all settings)
- Baseline-filled (existing rows with null/zero average cost to fill from shared baseline)
- Special-overlay-updated (special company rows to update with their own latest HPP)
- Unchanged (rows already correct or with positive average cost not affected)
- Unresolved (products with no eligible HPP baseline in any source)

**Review the output to:**
- Verify the number of products being seeded
- Check that created/baseline-filled/special-overlay-updated counts are reasonable
- Confirm that special settings (CV TIGA NUSA COMPUTER, CV TOP IT INTERNUSA) are using their own HPP when available
- Review unresolved count; investigate if products should have eligible HPP

### Step 3: Apply Changes

Once confirmed, apply the changes:

```bash
php artisan product:seed-average-cost-from-sales-hpp --write
```

This mode:
- Creates missing `product_prices` rows with the seeded average cost
- Updates existing rows, changing only `average_purchase_price`
- Preserves all other price metadata (last_purchase_price, selling prices, taxes)
- Reports the number of actual writes performed

## Behavior Details

### Candidate Selection

Only the latest authoritative imported snapshot per product and cost bucket is used:
- Source: `cost_snapshot_source = 'HPP_SNAPSHOT_IMPORT'`
- Positive cost: `cost_unit_snapshot > 0`
- Stock-managed products only
- Latest by: sale date → sale ID → sale-detail ID (deterministic tiebreaking)

### Cost Buckets and Baseline Resolution

The command resolves average cost in two stages:

**Stage 1: Shared Baseline**
The command selects a shared baseline in strict priority order:
1. **Perdana**: Latest Perdana HPP if available
2. **Top IT**: Latest CV TOP IT INTERNUSA HPP if Perdana unavailable
3. **Tiga Nusa**: Latest CV TIGA NUSA COMPUTER HPP if both above unavailable

This baseline fills every uninitialized setting (missing row or null/zero average cost), ensuring all businesses have a usable starting value.

**Stage 2: Special Company Overlay**
After baseline filling:
- **CV TIGA NUSA COMPUTER** setting: Uses its own latest HPP (if available) instead of baseline
- **CV TOP IT INTERNUSA** setting: Uses its own latest HPP (if available) instead of baseline
- **All other settings**: Use the shared baseline only

This two-stage process ensures every business has current pricing while special companies retain owner-specific costs when available.

### Row Creation

When a new `product_prices` row is needed, the command:
1. Copies `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, `sale_tax_id` from an existing same-product row (if available)
2. Sets `average_purchase_price` to the selected snapshot cost
3. Defaults other fields to 0 or null

## Rollback

If changes must be reverted:
1. **Code rollback**: Uninstall the feature branch or revert to a prior release
2. **Data rollback**: Restore `product_prices.average_purchase_price` from a backup or re-run the seeding from a corrected imported snapshot dataset

For urgent recovery, manually update affected rows or contact database administration.

## Troubleshooting

### "Skipped" count is high

**Cause**: Few stock-managed products have imported HPP snapshots.

**Actions**:
- Verify HPP import completed successfully
- Check that imported sale details have `cost_snapshot_source = 'HPP_SNAPSHOT_IMPORT'`
- Ensure products are marked as `stock_managed = true`

### Created/Updated counts differ from dry-run

**Cause**: Expected; dry-run reports all target settings, actual writes may be fewer if no candidate exists for some buckets.

**Actions**: This is normal. Review the dry-run output for settings without candidates and decide if they should be seeded later.

### Command runs very slowly

**Cause**: Large product volume with many sale details.

**Actions**:
- This is expected on first run; subsequent runs are cached
- Consider running during low-traffic windows if on production

## Example Dry-Run Output

```
Starting product average-cost seeding in DRY-RUN mode...

== DRY-RUN MODE ==
Considered products: 1250
Created: 320
Baseline-filled: 450
Special-overlay-updated: 75
Unchanged: 380
Unresolved: 25
```

In this example:
- 320 new `product_prices` rows will be created
- 450 existing rows with zero/null average cost will be filled from the shared baseline
- 75 special company rows will be updated with their own latest HPP
- 380 rows are already correct and unchanged
- 25 products have no eligible HPP baseline and remain unresolved

## See Also

- `product:normalize-purchase-prices` - Normalizes prices from approved received purchases
- Sales HPP Snapshot Import spec - Details on the CSV import workflow
